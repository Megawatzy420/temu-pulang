<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\ShiftService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftManagementOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftService $shiftService;

    protected CashMovementService $cashMovementService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->shiftService = app(ShiftService::class);
        $this->cashMovementService = app(CashMovementService::class);
    }

    /**
     * 1. Owner can open a shift.
     */
    public function test_owner_can_open_a_shift(): void
    {
        $owner = User::factory()->owner()->create();

        $shift = $this->shiftService->openShift(
            user: $owner,
            openingCash: 250000,
            openingNote: 'Kas modal awal owner'
        );

        $this->assertInstanceOf(Shift::class, $shift);
        $this->assertEquals(250000, $shift->opening_cash);
        $this->assertEquals('open', $shift->status);
        $this->assertEquals($owner->id, $shift->user_id);
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'open',
            'opening_cash' => 250000,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'shift.opened',
            'module' => 'shift',
        ]);
    }

    /**
     * 2. Cashier can open a shift if permitted.
     */
    public function test_cashier_can_open_a_shift_if_permitted(): void
    {
        $cashier = User::factory()->cashier()->create();

        $shift = $this->shiftService->openShift(
            user: $cashier,
            openingCash: 150000,
            openingNote: 'Kas awal shift pagi'
        );

        $this->assertTrue($shift->isOpen());
        $this->assertEquals($cashier->id, $shift->user_id);
        $this->assertEquals(150000, $shift->opening_cash);
    }

    /**
     * 3. Opening a second active shift is rejected.
     */
    public function test_opening_a_second_active_shift_is_rejected(): void
    {
        $cashier1 = User::factory()->cashier()->create();
        $cashier2 = User::factory()->cashier()->create();

        // First shift opened
        $this->shiftService->openShift($cashier1, 200000);

        // Attempt second shift without closing first
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Masih ada shift yang aktif');

        $this->shiftService->openShift($cashier2, 200000);
    }

    /**
     * 4. A shift can be closed.
     */
    public function test_a_shift_can_be_closed(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        $closedShift = $this->shiftService->closeShift(
            shift: $shift,
            closingUser: $cashier,
            actualCash: 200000,
            closingNote: 'Seimbang pas Rp 200.000'
        );

        $this->assertTrue($closedShift->isClosed());
        $this->assertEquals(200000, $closedShift->expected_cash);
        $this->assertEquals(200000, $closedShift->actual_cash);
        $this->assertEquals(0, $closedShift->discrepancy);
        $this->assertNotNull($closedShift->closed_at);
        $this->assertEquals($cashier->id, $closedShift->closed_by_user_id);
    }

    /**
     * 5. A closed shift cannot be reopened.
     */
    public function test_a_closed_shift_cannot_be_reopened_or_reclosed(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);
        $this->shiftService->closeShift($shift, $cashier, 200000, 'Shift selesai');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Shift ini sudah ditutup');

        $this->shiftService->closeShift($shift, $cashier, 200000, 'Mencoba menutup ulang');
    }

    /**
     * 6. An unauthorized user cannot close another user's shift.
     */
    public function test_an_unauthorized_user_cannot_close_another_users_shift(): void
    {
        $cashierA = User::factory()->cashier()->create();
        $cashierB = User::factory()->cashier()->create();

        $shift = $this->shiftService->openShift($cashierA, 200000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Anda tidak berwenang menutup shift milik kasir lain');

        // Cashier B tries to close Cashier A's shift
        $this->shiftService->closeShift($shift, $cashierB, 200000, 'Tutup paksa');
    }

    /**
     * 6b. Owner CAN close any cashier's shift (authorized escalation).
     */
    public function test_owner_can_close_any_cashiers_shift(): void
    {
        $cashier = User::factory()->cashier()->create();
        $owner = User::factory()->owner()->create();

        $shift = $this->shiftService->openShift($cashier, 200000);
        $closed = $this->shiftService->closeShift($shift, $owner, 200000, 'Owner shift override close');

        $this->assertTrue($closed->isClosed());
        $this->assertEquals($owner->id, $closed->closed_by_user_id);
    }

    /**
     * 7. A closed shift cannot receive new cash movements.
     */
    public function test_a_closed_shift_cannot_receive_new_cash_movements(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);
        $this->shiftService->closeShift($shift, $cashier, 200000, 'Shift selesai');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tidak dapat mencatat pergerakan kas pada shift yang sudah ditutup');

        $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $cashier,
            type: 'in',
            amount: 50000,
            category: 'capital_add',
            description: 'Uang terlambat masuk'
        );
    }

    /**
     * 14. Expected cash is calculated correctly: Opening Cash + Inflows - Outflows.
     */
    public function test_expected_cash_is_calculated_correctly(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        // Inflow +50.000
        $this->cashMovementService->recordMovement($shift, $cashier, 'in', 50000, 'capital_add', 'Tambah modal koin');

        // Outflow -20.000
        $this->cashMovementService->recordMovement($shift, $cashier, 'out', 20000, 'expense', 'Beli lakban packing');

        // Expected: 200.000 + 50.000 - 20.000 = 230.000
        $expected = $this->shiftService->calculateExpectedCash($shift);
        $this->assertEquals(230000, $expected);
    }

    /**
     * 15. Discrepancy is calculated correctly: Actual - Expected.
     */
    public function test_discrepancy_is_calculated_correctly(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        // Expected is 200.000, actual counted is 195.000 (short by 5.000)
        $closed = $this->shiftService->closeShift(
            shift: $shift,
            closingUser: $cashier,
            actualCash: 195000,
            closingNote: 'Kurang Rp 5.000 salah kembalian receh'
        );

        $this->assertEquals(-5000, $closed->discrepancy);
    }

    /**
     * 16. A discrepancy requires a closing note.
     */
    public function test_a_discrepancy_requires_a_closing_note(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Catatan penutupan wajib diisi apabila terdapat selisih kas');

        // Actual 190.000 vs Expected 200.000, note is empty
        $this->shiftService->closeShift($shift, $cashier, 190000, '');
    }

    /**
     * 17. Closing data remains unchanged after closure.
     */
    public function test_closing_data_remains_unchanged_after_closure(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);
        $closed = $this->shiftService->closeShift($shift, $cashier, 200000, 'Shift beres');

        $refetched = Shift::find($closed->id);
        $this->assertEquals(200000, $refetched->opening_cash);
        $this->assertEquals(200000, $refetched->expected_cash);
        $this->assertEquals(200000, $refetched->actual_cash);
        $this->assertEquals(0, $refetched->discrepancy);
        $this->assertEquals('Shift beres', $refetched->closing_note);
        $this->assertEquals('closed', $refetched->status);
    }

    /**
     * 18. Physical database constraint prevents duplicate active shifts for the same branch.
     */
    public function test_concurrent_or_duplicate_active_shift_fails_at_database_constraint_level(): void
    {
        $cashier = User::factory()->cashier()->create();

        // 1st active shift
        $this->shiftService->openShift($cashier, 100000);

        // Attempting direct model creation of a 2nd active shift bypassing service
        $this->expectException(\Throwable::class);

        Shift::create([
            'branch_id' => null,
            'shift_number' => 'SFT-DIRECT-0002',
            'user_id' => $cashier->id,
            'status' => 'open',
            'opened_at' => now(),
            'opening_cash' => 100000,
        ]);
    }

    /**
     * 19. Closed shift cannot be updated at the model level (immutability).
     */
    public function test_closed_shift_cannot_be_modified_at_model_level(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 150000);
        $closed = $this->shiftService->closeShift($shift, $cashier, 150000, 'Shift closed balanced');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Shift yang sudah ditutup bersifat permanen');

        $closed->update(['opening_cash' => 999999]);
    }

    /**
     * 20. Shifts cannot be deleted (audit preservation).
     */
    public function test_shifts_cannot_be_deleted(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 150000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Shift tidak dapat dihapus');

        $shift->delete();
    }

    /**
     * 21. Branch-aware shift concurrency: different branches can each have an open shift.
     */
    public function test_different_branches_can_each_have_an_active_shift(): void
    {
        $cashier1 = User::factory()->cashier()->create();
        $cashier2 = User::factory()->cashier()->create();

        $shiftBranch1 = $this->shiftService->openShift($cashier1, 100000, null, 1);
        $shiftBranch2 = $this->shiftService->openShift($cashier2, 200000, null, 2);

        $this->assertTrue($shiftBranch1->isOpen());
        $this->assertTrue($shiftBranch2->isOpen());
        $this->assertEquals(1, $shiftBranch1->branch_id);
        $this->assertEquals(2, $shiftBranch2->branch_id);

        // But opening a 2nd shift for branch 1 must fail
        $this->expectException(DomainException::class);
        $this->shiftService->openShift($cashier1, 100000, null, 1);
    }
}
