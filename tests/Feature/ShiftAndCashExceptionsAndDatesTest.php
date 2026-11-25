<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Cash\CannotReverseReversalException;
use App\Exceptions\Cash\InvalidCashMovementTypeException;
use App\Exceptions\Cash\MovementAlreadyReversedException;
use App\Exceptions\Cash\MovementDescriptionRequiredException;
use App\Exceptions\Cash\MovementOnClosedShiftException;
use App\Exceptions\Cash\NonPositiveMovementAmountException;
use App\Exceptions\Cash\ReversalOnClosedShiftException;
use App\Exceptions\Cash\ReversalReasonRequiredException;
use App\Exceptions\Shift\ClosingNoteRequiredForDiscrepancyException;
use App\Exceptions\Shift\NegativeCountedCashException;
use App\Exceptions\Shift\NegativeOpeningCashException;
use App\Exceptions\Shift\ShiftAlreadyActiveException;
use App\Exceptions\Shift\ShiftAlreadyClosedException;
use App\Exceptions\Shift\UnauthorizedShiftCloseException;
use App\Livewire\ActivityLogs\ActivityLogIndex;
use App\Livewire\Orders\OrderIndex;
use App\Livewire\Shifts\ShiftManager;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\ShiftService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use Throwable;

class ShiftAndCashExceptionsAndDatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashierA;

    private User $cashierB;

    private ShiftService $shiftService;

    private CashMovementService $cashMovementService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        ShopSetting::create([
            'name' => 'Temu Pulang',
            'tax_rate' => 0,
            'service_charge_rate' => 0,
            'currency_symbol' => 'Rp',
            'receipt_paper_width' => '58mm',
            'auto_print_receipt' => false,
            'printer_connection_type' => 'usb',
        ]);

        $this->owner = User::factory()->owner()->create();
        $this->cashierA = User::factory()->cashier()->create();
        $this->cashierB = User::factory()->cashier()->create();

        $this->shiftService = app(ShiftService::class);
        $this->cashMovementService = app(CashMovementService::class);
    }

    // ── 1. One test per new Shift / Cash exception class ──────────────────────

    public function test_shift_service_throws_negative_opening_cash_exception(): void
    {
        try {
            $this->shiftService->openShift($this->cashierA, -1);
            $this->fail('Expected NegativeOpeningCashException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(NegativeOpeningCashException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Jumlah kas awal tidak boleh bernilai negatif.', $e->getMessage());
        }
    }

    public function test_shift_service_throws_shift_already_active_exception(): void
    {
        $this->shiftService->openShift($this->cashierA, 100000);

        try {
            $this->shiftService->openShift($this->cashierB, 100000);
            $this->fail('Expected ShiftAlreadyActiveException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(ShiftAlreadyActiveException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame(
                'Masih ada shift yang aktif. Tutup shift aktif terlebih dahulu sebelum membuka shift baru.',
                $e->getMessage()
            );
        }
    }

    public function test_shift_service_throws_negative_counted_cash_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);

        try {
            $this->shiftService->closeShift($shift, $this->cashierA, -500, 'Negative test');
            $this->fail('Expected NegativeCountedCashException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(NegativeCountedCashException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Jumlah kas fisik tidak boleh bernilai negatif.', $e->getMessage());
        }
    }

    public function test_shift_service_throws_shift_already_closed_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);
        $closed = $this->shiftService->closeShift($shift, $this->cashierA, 100000, 'Closed');

        try {
            $this->shiftService->closeShift($closed, $this->cashierA, 100000, 'Second close');
            $this->fail('Expected ShiftAlreadyClosedException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(ShiftAlreadyClosedException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Shift ini sudah ditutup dan tidak dapat diubah kembali.', $e->getMessage());
        }
    }

    public function test_shift_service_throws_unauthorized_shift_close_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);

        try {
            $this->shiftService->closeShift($shift, $this->cashierB, 100000, 'Other cashier');
            $this->fail('Expected UnauthorizedShiftCloseException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(UnauthorizedShiftCloseException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Anda tidak berwenang menutup shift milik kasir lain.', $e->getMessage());
        }
    }

    public function test_shift_service_throws_closing_note_required_for_discrepancy_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);

        try {
            $this->shiftService->closeShift($shift, $this->cashierA, 95000, '   ');
            $this->fail('Expected ClosingNoteRequiredForDiscrepancyException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(ClosingNoteRequiredForDiscrepancyException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Catatan penutupan wajib diisi apabila terdapat selisih kas.', $e->getMessage());
        }
    }

    public function test_cash_movement_service_throws_invalid_cash_movement_type_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);

        try {
            $this->cashMovementService->recordMovement($shift, $this->cashierA, 'invalid', 10000, 'other', 'Note');
            $this->fail('Expected InvalidCashMovementTypeException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(InvalidCashMovementTypeException::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame("Tipe pergerakan kas tidak valid: 'invalid'. Gunakan 'in' atau 'out'.", $e->getMessage());
        }
    }

    public function test_cash_movement_service_throws_non_positive_movement_amount_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);

        try {
            $this->cashMovementService->recordMovement($shift, $this->cashierA, 'in', 0, 'capital_add', 'Note');
            $this->fail('Expected NonPositiveMovementAmountException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(NonPositiveMovementAmountException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Jumlah uang pergerakan kas harus lebih besar dari 0 Rupiah.', $e->getMessage());
        }
    }

    public function test_cash_movement_service_throws_movement_description_required_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);

        try {
            $this->cashMovementService->recordMovement($shift, $this->cashierA, 'in', 10000, 'capital_add', '   ');
            $this->fail('Expected MovementDescriptionRequiredException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(MovementDescriptionRequiredException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Keterangan atau alasan pergerakan kas wajib diisi.', $e->getMessage());
        }
    }

    public function test_cash_movement_service_throws_movement_on_closed_shift_exception(): void
    {
        $closedShift = Shift::factory()->closed()->create(['user_id' => $this->cashierA->id]);

        try {
            $this->cashMovementService->recordMovement($closedShift, $this->cashierA, 'in', 10000, 'capital_add', 'Late');
            $this->fail('Expected MovementOnClosedShiftException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(MovementOnClosedShiftException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Tidak dapat mencatat pergerakan kas pada shift yang sudah ditutup.', $e->getMessage());
        }
    }

    public function test_cash_movement_service_throws_reversal_reason_required_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);
        $movement = $this->cashMovementService->recordMovement($shift, $this->cashierA, 'in', 10000, 'capital_add', 'Float');

        try {
            $this->cashMovementService->reverseMovement($movement, $this->cashierA, '   ');
            $this->fail('Expected ReversalReasonRequiredException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(ReversalReasonRequiredException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Alasan pembatalan/pembalikan pergerakan kas wajib diisi.', $e->getMessage());
        }
    }

    public function test_cash_movement_service_throws_cannot_reverse_reversal_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);
        $movement = $this->cashMovementService->recordMovement($shift, $this->cashierA, 'in', 10000, 'capital_add', 'Float');
        $reversal = $this->cashMovementService->reverseMovement($movement, $this->cashierA, 'Wrong entry');

        try {
            $this->cashMovementService->reverseMovement($reversal, $this->cashierA, 'Reverse reversal');
            $this->fail('Expected CannotReverseReversalException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(CannotReverseReversalException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Transaksi pembalikan tidak dapat dibatalkan kembali.', $e->getMessage());
        }
    }

    public function test_cash_movement_service_throws_movement_already_reversed_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);
        $movement = $this->cashMovementService->recordMovement($shift, $this->cashierA, 'in', 10000, 'capital_add', 'Float');
        $this->cashMovementService->reverseMovement($movement, $this->cashierA, 'First reversal');

        try {
            $this->cashMovementService->reverseMovement($movement, $this->cashierA, 'Second reversal');
            $this->fail('Expected MovementAlreadyReversedException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(MovementAlreadyReversedException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Pergerakan kas ini sudah pernah dibatalkan sebelumnya.', $e->getMessage());
        }
    }

    public function test_cash_movement_service_throws_reversal_on_closed_shift_exception(): void
    {
        $shift = $this->shiftService->openShift($this->cashierA, 100000);
        $movement = $this->cashMovementService->recordMovement($shift, $this->cashierA, 'in', 10000, 'capital_add', 'Float');
        $this->shiftService->closeShift($shift, $this->cashierA, 110000, 'Closed');

        try {
            $this->cashMovementService->reverseMovement($movement->fresh(), $this->cashierA, 'Late reversal');
            $this->fail('Expected ReversalOnClosedShiftException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(ReversalOnClosedShiftException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Tidak dapat membatalkan pergerakan kas pada shift yang sudah ditutup.', $e->getMessage());
        }
    }

    // ── 2. ShiftManager catch block mapping tests (real + mocked + unknown) ───

    public function test_shift_manager_catch_blocks_with_real_service_conditions_in_en_and_id(): void
    {
        // Active shift opened by cashierA
        $shift = $this->shiftService->openShift($this->cashierA, 100000);
        $movement = $this->cashMovementService->recordMovement($shift, $this->cashierA, 'in', 20000, 'capital_add', 'Initial');
        $reversal = $this->cashMovementService->reverseMovement($movement, $this->cashierA, 'Mistake');
        $closedShift = Shift::factory()->closed()->create(['user_id' => $this->cashierA->id]);

        foreach (['en', 'id'] as $locale) {
            App::setLocale($locale);

            // Catch block 1: submitOpenShift -> 'openingCash' (real condition: ShiftAlreadyActiveException)
            Livewire::actingAs($this->cashierA)
                ->test(ShiftManager::class)
                ->set('openingCash', 100000)
                ->call('submitOpenShift')
                ->assertHasErrors(['openingCash' => [__('messages.shifts.error_shift_already_active')]]);

            // Catch block 2: submitCashMovement -> 'movementAmount' (real CashMovementService condition: MovementOnClosedShiftException)
            $shiftServiceReturningClosed = Mockery::mock(ShiftService::class)->makePartial();
            $shiftServiceReturningClosed->shouldReceive('getActiveShift')->andReturn($closedShift);
            $this->app->instance(ShiftService::class, $shiftServiceReturningClosed);

            Livewire::actingAs($this->cashierA)
                ->test(ShiftManager::class)
                ->set('movementType', 'in')
                ->set('movementAmount', 10000)
                ->set('movementCategory', 'capital_add')
                ->set('movementDescription', 'Real service closed shift check')
                ->call('submitCashMovement')
                ->assertHasErrors(['movementAmount' => [__('messages.shifts.error_movement_on_closed_shift')]]);

            $this->app->instance(ShiftService::class, $this->shiftService);

            // Catch block 3: submitReversal -> 'reversalReason' (real condition: CannotReverseReversalException & MovementAlreadyReversedException)
            Livewire::actingAs($this->cashierA)
                ->test(ShiftManager::class)
                ->set('selectedMovementId', $reversal->id)
                ->set('reversalReason', 'Try reversing a reversal')
                ->call('submitReversal')
                ->assertHasErrors(['reversalReason' => [__('messages.shifts.error_cannot_reverse_reversal')]]);

            Livewire::actingAs($this->cashierA)
                ->test(ShiftManager::class)
                ->set('selectedMovementId', $movement->id)
                ->set('reversalReason', 'Try reversing again')
                ->call('submitReversal')
                ->assertHasErrors(['reversalReason' => [__('messages.shifts.error_movement_already_reversed')]]);

            // Catch block 4: submitCloseShift -> 'closingNote' (real conditions: UnauthorizedShiftCloseException & ClosingNoteRequiredForDiscrepancyException)
            Livewire::actingAs($this->cashierB)
                ->test(ShiftManager::class)
                ->set('actualCash', 100000)
                ->set('closingNote', 'Cashier B attempt')
                ->call('submitCloseShift')
                ->assertHasErrors(['closingNote' => [__('messages.shifts.error_unauthorized_shift_close')]]);

            Livewire::actingAs($this->cashierA)
                ->test(ShiftManager::class)
                ->set('actualCash', 50000)
                ->set('closingNote', '')
                ->call('submitCloseShift')
                ->assertHasErrors(['closingNote' => [__('messages.shifts.error_closing_note_required')]]);
        }
    }

    public function test_shift_manager_catch_blocks_map_all_exception_classes_and_unknown_exceptions_in_en_and_id(): void
    {
        $activeShift = $this->shiftService->openShift($this->cashierA, 100000);
        $movement = $this->cashMovementService->recordMovement($activeShift, $this->cashierA, 'in', 10000, 'capital_add', 'Test');

        // 1. Block 1 (submitOpenShift -> openingCash): NegativeOpeningCashException, ShiftAlreadyActiveException, Unknown DomainException
        $block1Cases = [
            [new NegativeOpeningCashException('REWORDED NEGATIVE FLOAT 123'), 'messages.shifts.error_negative_opening_cash'],
            [new ShiftAlreadyActiveException('REWORDED ACTIVE SHIFT 123'), 'messages.shifts.error_shift_already_active'],
            [new DomainException('RAW SECRET OPEN ERROR 999'), 'messages.shifts.error_generic'],
        ];

        foreach ($block1Cases as [$exception, $expectedKey]) {
            Log::spy();
            foreach (['en', 'id'] as $locale) {
                App::setLocale($locale);

                $mockShiftService = Mockery::mock(ShiftService::class)->makePartial();
                $mockShiftService->shouldReceive('openShift')->once()->andThrow($exception);
                $this->app->instance(ShiftService::class, $mockShiftService);

                Livewire::actingAs($this->cashierA)
                    ->test(ShiftManager::class)
                    ->set('openingCash', 100000)
                    ->call('submitOpenShift')
                    ->assertHasErrors(['openingCash' => [__($expectedKey)]])
                    ->assertDontSee($exception->getMessage());
            }

            if ($expectedKey === 'messages.shifts.error_generic') {
                Log::shouldHaveReceived('error')
                    ->withArgs(fn (string $msg, array $ctx): bool => ($ctx['message'] ?? null) === $exception->getMessage())
                    ->twice();
            }
        }

        // 2. Block 2 (submitCashMovement -> movementAmount): NonPositiveMovementAmountException, MovementDescriptionRequiredException, MovementOnClosedShiftException, Unknown DomainException
        $block2Cases = [
            [new NonPositiveMovementAmountException('REWORDED AMOUNT 123'), 'messages.shifts.error_movement_amount_positive'],
            [new MovementDescriptionRequiredException('REWORDED NOTE 123'), 'messages.shifts.error_movement_note_required'],
            [new MovementOnClosedShiftException('REWORDED CLOSED 123'), 'messages.shifts.error_movement_on_closed_shift'],
            [new DomainException('RAW SECRET MOVEMENT ERROR 999'), 'messages.shifts.error_generic'],
        ];

        $this->app->instance(ShiftService::class, $this->shiftService);

        foreach ($block2Cases as [$exception, $expectedKey]) {
            Log::spy();
            foreach (['en', 'id'] as $locale) {
                App::setLocale($locale);

                $mockMovementService = Mockery::mock(CashMovementService::class)->makePartial();
                $mockMovementService->shouldReceive('recordMovement')->once()->andThrow($exception);
                $this->app->instance(CashMovementService::class, $mockMovementService);

                Livewire::actingAs($this->cashierA)
                    ->test(ShiftManager::class)
                    ->set('movementType', 'in')
                    ->set('movementAmount', 25000)
                    ->set('movementCategory', 'capital_add')
                    ->set('movementDescription', 'Valid description')
                    ->call('submitCashMovement')
                    ->assertHasErrors(['movementAmount' => [__($expectedKey)]])
                    ->assertDontSee($exception->getMessage());
            }

            if ($expectedKey === 'messages.shifts.error_generic') {
                Log::shouldHaveReceived('error')
                    ->withArgs(fn (string $msg, array $ctx): bool => ($ctx['message'] ?? null) === $exception->getMessage())
                    ->twice();
            }
        }

        // 3. Block 3 (submitReversal -> reversalReason): ReversalReasonRequiredException, CannotReverseReversalException, MovementAlreadyReversedException, ReversalOnClosedShiftException, Unknown DomainException
        $block3Cases = [
            [new ReversalReasonRequiredException('REWORDED REASON 123'), 'messages.shifts.error_reversal_reason_required'],
            [new CannotReverseReversalException('REWORDED REVERSAL 123'), 'messages.shifts.error_cannot_reverse_reversal'],
            [new MovementAlreadyReversedException('REWORDED ALREADY REVERSED 123'), 'messages.shifts.error_movement_already_reversed'],
            [new ReversalOnClosedShiftException('REWORDED REVERSAL CLOSED 123'), 'messages.shifts.error_reversal_on_closed_shift'],
            [new DomainException('RAW SECRET REVERSAL ERROR 999'), 'messages.shifts.error_generic'],
        ];

        foreach ($block3Cases as [$exception, $expectedKey]) {
            Log::spy();
            foreach (['en', 'id'] as $locale) {
                App::setLocale($locale);

                $mockMovementService = Mockery::mock(CashMovementService::class)->makePartial();
                $mockMovementService->shouldReceive('reverseMovement')->once()->andThrow($exception);
                $this->app->instance(CashMovementService::class, $mockMovementService);

                Livewire::actingAs($this->cashierA)
                    ->test(ShiftManager::class)
                    ->set('selectedMovementId', $movement->id)
                    ->set('reversalReason', 'Valid reason')
                    ->call('submitReversal')
                    ->assertHasErrors(['reversalReason' => [__($expectedKey)]])
                    ->assertDontSee($exception->getMessage());
            }

            if ($expectedKey === 'messages.shifts.error_generic') {
                Log::shouldHaveReceived('error')
                    ->withArgs(fn (string $msg, array $ctx): bool => ($ctx['message'] ?? null) === $exception->getMessage())
                    ->twice();
            }
        }

        // 4. Block 4 (submitCloseShift -> closingNote): NegativeCountedCashException, ShiftAlreadyClosedException, UnauthorizedShiftCloseException, ClosingNoteRequiredForDiscrepancyException, Unknown DomainException
        $block4Cases = [
            [new NegativeCountedCashException('REWORDED NEGATIVE COUNT 123'), 'messages.shifts.error_negative_counted_cash'],
            [new ShiftAlreadyClosedException('REWORDED ALREADY CLOSED 123'), 'messages.shifts.error_shift_already_closed'],
            [new UnauthorizedShiftCloseException('REWORDED UNAUTHORIZED 123'), 'messages.shifts.error_unauthorized_shift_close'],
            [new ClosingNoteRequiredForDiscrepancyException('REWORDED CLOSING NOTE 123'), 'messages.shifts.error_closing_note_required'],
            [new DomainException('RAW SECRET CLOSE ERROR 999'), 'messages.shifts.error_generic'],
        ];

        $this->app->instance(CashMovementService::class, $this->cashMovementService);

        foreach ($block4Cases as [$exception, $expectedKey]) {
            Log::spy();
            foreach (['en', 'id'] as $locale) {
                App::setLocale($locale);

                $mockShiftService = Mockery::mock(ShiftService::class)->makePartial();
                $mockShiftService->shouldReceive('closeShift')->once()->andThrow($exception);
                $this->app->instance(ShiftService::class, $mockShiftService);

                Livewire::actingAs($this->cashierA)
                    ->test(ShiftManager::class)
                    ->set('actualCash', 110000)
                    ->set('closingNote', 'Valid note')
                    ->call('submitCloseShift')
                    ->assertHasErrors(['closingNote' => [__($expectedKey)]])
                    ->assertDontSee($exception->getMessage());
            }

            if ($expectedKey === 'messages.shifts.error_generic') {
                Log::shouldHaveReceived('error')
                    ->withArgs(fn (string $msg, array $ctx): bool => ($ctx['message'] ?? null) === $exception->getMessage())
                    ->twice();
            }
        }
    }

    // ── 3. Locale-aware date formatting tests (May, Aug, Oct, Dec) ────────────

    public function test_set_locale_and_translated_format_render_indonesian_and_english_month_names(): void
    {
        $months = [
            ['2026-05-15 14:30:00', '15 Mei 2026', '15 May 2026'],
            ['2026-08-15 14:30:00', '15 Agt 2026', '15 Aug 2026'],
            ['2026-10-15 14:30:00', '15 Okt 2026', '15 Oct 2026'],
            ['2026-12-15 14:30:00', '15 Des 2026', '15 Dec 2026'],
        ];

        foreach ($months as [$dateStr, $expectedId, $expectedEn]) {
            $dt = Carbon::parse($dateStr, 'Asia/Jakarta');

            App::setLocale('id');
            Carbon::setLocale('id');
            $this->assertSame($expectedId, $dt->timezone('Asia/Jakarta')->translatedFormat('d M Y'));

            App::setLocale('en');
            Carbon::setLocale('en');
            $this->assertSame($expectedEn, $dt->timezone('Asia/Jakarta')->translatedFormat('d M Y'));
        }

        // Verify through rendered components & SetLocale middleware
        $mayDate = Carbon::parse('2026-05-15 10:25:00', 'Asia/Jakarta');
        $octDate = Carbon::parse('2026-10-20 11:40:00', 'Asia/Jakarta');
        $decDate = Carbon::parse('2026-12-25 16:05:30', 'Asia/Jakarta');

        $shift = Shift::factory()->create([
            'user_id' => $this->owner->id,
            'opened_at' => $mayDate,
            'opening_cash' => 100000,
            'status' => 'open',
        ]);

        $log = ActivityLog::create([
            'user_id' => $this->owner->id,
            'action' => 'shift.opened',
            'module' => 'shift',
            'description' => 'Test log',
        ]);
        DB::table('activity_logs')
            ->where('id', $log->id)
            ->update(['created_at' => $octDate, 'updated_at' => $octDate]);

        $order = Order::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->owner->id,
        ]);
        DB::table('orders')
            ->where('id', $order->id)
            ->update(['created_at' => $decDate, 'updated_at' => $decDate]);

        // Hit route with session locale 'id' to trigger SetLocale middleware
        $this->actingAs($this->owner)
            ->withSession(['locale' => 'id'])
            ->get(route('shifts'))
            ->assertOk()
            ->assertSee('15 Mei 2026, 10:25');

        Livewire::actingAs($this->owner)
            ->test(ActivityLogIndex::class)
            ->assertSee('20 Okt 2026');

        Livewire::actingAs($this->owner)
            ->test(OrderIndex::class)
            ->call('openDetailModal', $order->id)
            ->assertSee('25 Des 2026');

        // Hit route with session locale 'en' to trigger SetLocale middleware
        $this->actingAs($this->owner)
            ->withSession(['locale' => 'en'])
            ->get(route('shifts'))
            ->assertOk()
            ->assertSee('15 May 2026, 10:25');

        Livewire::actingAs($this->owner)
            ->test(ActivityLogIndex::class)
            ->assertSee('20 Oct 2026');

        Livewire::actingAs($this->owner)
            ->test(OrderIndex::class)
            ->call('openDetailModal', $order->id)
            ->assertSee('25 Dec 2026');
    }
}
