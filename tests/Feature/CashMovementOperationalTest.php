<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\ShiftService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CashMovementOperationalTest extends TestCase
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
     * 8. Cash inflow increases expected cash.
     */
    public function test_cash_inflow_increases_expected_cash(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 100000);

        $initialExpected = $this->shiftService->calculateExpectedCash($shift);
        $this->assertEquals(100000, $initialExpected);

        $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $cashier,
            type: 'in',
            amount: 75000,
            category: 'capital_add',
            description: 'Uang kembalian koin'
        );

        $newExpected = $this->shiftService->calculateExpectedCash($shift);
        $this->assertEquals(175000, $newExpected);
    }

    /**
     * 9. Cash outflow decreases expected cash.
     */
    public function test_cash_outflow_decreases_expected_cash(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $cashier,
            type: 'out',
            amount: 45000,
            category: 'expense',
            description: 'Beli kertas kasir'
        );

        $newExpected = $this->shiftService->calculateExpectedCash($shift);
        $this->assertEquals(155000, $newExpected);
    }

    /**
     * 10. Zero or negative amounts are rejected.
     */
    public function test_zero_or_negative_amounts_are_rejected(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        // Zero amount
        try {
            $this->cashMovementService->recordMovement(
                shift: $shift,
                user: $cashier,
                type: 'in',
                amount: 0,
                category: 'capital_add',
                description: 'Test nominal nol'
            );
            $this->fail('Expected DomainException for zero amount was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('harus lebih besar dari 0 Rupiah', $e->getMessage());
        }

        // Negative amount
        try {
            $this->cashMovementService->recordMovement(
                shift: $shift,
                user: $cashier,
                type: 'out',
                amount: -5000,
                category: 'expense',
                description: 'Test nominal negatif'
            );
            $this->fail('Expected DomainException for negative amount was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('harus lebih besar dari 0 Rupiah', $e->getMessage());
        }
    }

    /**
     * 11. Invalid movement types are rejected.
     */
    public function test_invalid_movement_types_are_rejected(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tipe pergerakan kas tidak valid');

        $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $cashier,
            type: 'transfer', // Invalid
            amount: 10000,
            category: 'other',
            description: 'Invalid type test'
        );
    }

    /**
     * 12. A movement cannot be created without an active shift.
     */
    public function test_a_movement_cannot_be_created_without_an_active_shift(): void
    {
        $cashier = User::factory()->cashier()->create();
        $closedShift = Shift::factory()->closed()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tidak dapat mencatat pergerakan kas pada shift yang sudah ditutup');

        $this->cashMovementService->recordMovement(
            shift: $closedShift,
            user: $cashier,
            type: 'in',
            amount: 50000,
            category: 'capital_add',
            description: 'Coba input di shift tutup'
        );
    }

    /**
     * 13. A reversal creates an auditable record.
     */
    public function test_a_reversal_creates_an_auditable_record(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        // Original Outflow: 30.000
        $original = $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $cashier,
            type: 'out',
            amount: 30000,
            category: 'expense',
            description: 'Salah catat nota'
        );

        // Reversal
        $reversal = $this->cashMovementService->reverseMovement(
            movement: $original,
            user: $cashier,
            reason: 'Nota dibatalkan oleh supplier'
        );

        $this->assertInstanceOf(CashMovement::class, $reversal);
        $this->assertTrue($reversal->is_reversal);
        $this->assertEquals($original->id, $reversal->reversed_movement_id);
        $this->assertEquals('in', $reversal->type); // Opposite of 'out'
        $this->assertEquals(30000, $reversal->amount);
        $this->assertEquals('Nota dibatalkan oleh supplier', $reversal->reversal_reason);

        // Drawer balance should be restored to 200.000
        $expected = $this->shiftService->calculateExpectedCash($shift);
        $this->assertEquals(200000, $expected);

        // Audit log created
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'cash_movement.reversed',
            'module' => 'cash_movement',
        ]);
    }

    /**
     * 14. An original movement cannot be reversed more than once (duplicate reversal rejected).
     */
    public function test_an_original_movement_cannot_be_reversed_more_than_once(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        $original = $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $cashier,
            type: 'in',
            amount: 50000,
            category: 'capital_add',
            description: 'Uang masuk tambahan'
        );

        // 1st reversal: SUCCESS
        $this->cashMovementService->reverseMovement(
            movement: $original,
            user: $cashier,
            reason: 'Salah pencatatan pertama'
        );

        // 2nd reversal attempt on same movement: MUST FAIL
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pergerakan kas ini sudah pernah dibatalkan sebelumnya');

        $this->cashMovementService->reverseMovement(
            movement: $original,
            user: $cashier,
            reason: 'Mencoba membatalkan kedua kali'
        );
    }

    /**
     * 15. A reversal record itself cannot be reversed.
     */
    public function test_a_reversal_record_itself_cannot_be_reversed(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        $original = $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $cashier,
            type: 'out',
            amount: 25000,
            category: 'expense',
            description: 'Beli galon air'
        );

        $reversal = $this->cashMovementService->reverseMovement(
            movement: $original,
            user: $cashier,
            reason: 'Galon air gratis dari sponsor'
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Transaksi pembalikan tidak dapat dibatalkan kembali');

        $this->cashMovementService->reverseMovement(
            movement: $reversal,
            user: $cashier,
            reason: 'Membatalkan pembalikan'
        );
    }

    /**
     * 16. Cash movements cannot be modified after creation (model-level immutability).
     */
    public function test_cash_movements_cannot_be_modified_at_model_level(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        $movement = $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $cashier,
            type: 'in',
            amount: 50000,
            category: 'capital_add',
            description: 'Setoran modal kasir'
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pergerakan kas tidak dapat diubah setelah dicatat');

        $movement->update(['amount' => 999999]);
    }

    /**
     * 17. Cash movements cannot be deleted (model-level audit preservation).
     */
    public function test_cash_movements_cannot_be_deleted(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = $this->shiftService->openShift($cashier, 200000);

        $movement = $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $cashier,
            type: 'in',
            amount: 50000,
            category: 'capital_add',
            description: 'Setoran modal kasir'
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pergerakan kas tidak dapat dihapus');

        $movement->delete();
    }
}
