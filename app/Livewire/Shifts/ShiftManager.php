<?php

namespace App\Livewire\Shifts;

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
use App\Models\CashMovement;
use App\Models\Shift;
use App\Services\CashMovementService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class ShiftManager extends Component
{
    use WithPagination;

    // Modals
    public bool $showOpenShiftModal = false;

    public bool $showCloseShiftModal = false;

    public bool $showMovementModal = false;

    public bool $showReversalModal = false;

    // Open Shift Form
    public int $openingCash = 200000;

    public string $openingNote = '';

    // Cash Movement Form
    public string $movementType = 'in';

    public int $movementAmount = 50000;

    public string $movementCategory = 'capital_add';

    public string $movementDescription = '';

    // Reversal Form
    public ?int $selectedMovementId = null;

    public string $reversalReason = '';

    // Close Shift Form
    public int $actualCash = 0;

    public string $closingNote = '';

    /**
     * Open the Shift opening modal.
     */
    public function openNewShiftModal(): void
    {
        $this->openingCash = 200000;
        $this->openingNote = '';
        $this->showOpenShiftModal = true;
    }

    /**
     * Submit Shift opening.
     */
    public function submitOpenShift(ShiftService $shiftService): void
    {
        $this->validate([
            'openingCash' => ['required', 'integer', 'min:0'],
            'openingNote' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $shiftService->openShift(
                user: Auth::user(),
                openingCash: $this->openingCash,
                openingNote: $this->openingNote ?: null
            );

            $this->showOpenShiftModal = false;
            $this->dispatch('notify', message: __('messages.shifts.shift_opened_notification'), type: 'success');
        } catch (DomainException $e) {
            $this->addError('openingCash', $this->mapShiftExceptionToMessage($e, 'open_shift'));
        }
    }

    /**
     * Open Cash Movement modal.
     */
    public function openNewMovementModal(string $type = 'in'): void
    {
        $this->movementType = in_array($type, ['in', 'out'], true) ? $type : 'in';
        $this->movementAmount = 50000;
        $this->movementCategory = $this->movementType === 'in' ? 'capital_add' : 'expense';
        $this->movementDescription = '';
        $this->showMovementModal = true;
    }

    /**
     * Submit Cash Movement.
     */
    public function submitCashMovement(ShiftService $shiftService, CashMovementService $movementService): void
    {
        $this->validate([
            'movementType' => ['required', 'in:in,out'],
            'movementAmount' => ['required', 'integer', 'min:1'],
            'movementCategory' => ['required', 'string', 'max:50'],
            'movementDescription' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $activeShift = $shiftService->getActiveShift();
        if (! $activeShift) {
            $this->addError('movementAmount', __('messages.shifts.no_active_shift_for_movement'));

            return;
        }

        try {
            $movementService->recordMovement(
                shift: $activeShift,
                user: Auth::user(),
                type: $this->movementType,
                amount: $this->movementAmount,
                category: $this->movementCategory,
                description: $this->movementDescription
            );

            $this->showMovementModal = false;
            $this->dispatch('notify', message: __('messages.shifts.movement_recorded_notification'), type: 'success');
        } catch (DomainException $e) {
            $this->addError('movementAmount', $this->mapShiftExceptionToMessage($e, 'cash_movement'));
        }
    }

    /**
     * Open reversal prompt.
     */
    public function promptReversal(int $movementId): void
    {
        $this->selectedMovementId = $movementId;
        $this->reversalReason = '';
        $this->showReversalModal = true;
    }

    /**
     * Submit reversal.
     */
    public function submitReversal(CashMovementService $movementService): void
    {
        $this->validate([
            'reversalReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $movement = CashMovement::find($this->selectedMovementId);
        if (! $movement) {
            $this->addError('reversalReason', __('messages.shifts.movement_not_found'));

            return;
        }

        try {
            $movementService->reverseMovement(
                movement: $movement,
                user: Auth::user(),
                reason: $this->reversalReason
            );

            $this->showReversalModal = false;
            $this->dispatch('notify', message: __('messages.shifts.movement_reversed_notification'), type: 'info');
        } catch (DomainException $e) {
            $this->addError('reversalReason', $this->mapShiftExceptionToMessage($e, 'reversal'));
        }
    }

    /**
     * Prepare Close Shift modal.
     */
    public function openCloseModal(ShiftService $shiftService): void
    {
        $activeShift = $shiftService->getActiveShift();
        if (! $activeShift) {
            return;
        }

        $expected = $shiftService->calculateExpectedCash($activeShift);
        $this->actualCash = $expected; // Default counted to expected for smooth cashier experience
        $this->closingNote = '';
        $this->showCloseShiftModal = true;
    }

    /**
     * Submit Close Shift.
     */
    public function submitCloseShift(ShiftService $shiftService): void
    {
        $this->validate([
            'actualCash' => ['required', 'integer', 'min:0'],
            'closingNote' => ['nullable', 'string', 'max:500'],
        ]);

        $activeShift = $shiftService->getActiveShift();
        if (! $activeShift) {
            $this->addError('actualCash', __('messages.shifts.no_active_shift_to_close'));

            return;
        }

        try {
            $shiftService->closeShift(
                shift: $activeShift,
                closingUser: Auth::user(),
                actualCash: $this->actualCash,
                closingNote: $this->closingNote ?: null
            );

            $this->showCloseShiftModal = false;
            $this->dispatch('notify', message: __('messages.shifts.shift_closed_notification'), type: 'success');
        } catch (DomainException $e) {
            $this->addError('closingNote', $this->mapShiftExceptionToMessage($e, 'close_shift'));
        }
    }

    /**
     * Map shift and cash drawer exceptions to localized user-facing messages by exception class.
     */
    protected function mapShiftExceptionToMessage(Throwable $e, string $context = 'shift'): string
    {
        return match (true) {
            $e instanceof NegativeOpeningCashException => __('messages.shifts.error_negative_opening_cash'),
            $e instanceof ShiftAlreadyActiveException => __('messages.shifts.error_shift_already_active'),
            $e instanceof NegativeCountedCashException => __('messages.shifts.error_negative_counted_cash'),
            $e instanceof ShiftAlreadyClosedException => __('messages.shifts.error_shift_already_closed'),
            $e instanceof UnauthorizedShiftCloseException => __('messages.shifts.error_unauthorized_shift_close'),
            $e instanceof ClosingNoteRequiredForDiscrepancyException => __('messages.shifts.error_closing_note_required'),
            $e instanceof InvalidCashMovementTypeException => __('messages.shifts.error_invalid_movement_type'),
            $e instanceof NonPositiveMovementAmountException => __('messages.shifts.error_movement_amount_positive'),
            $e instanceof MovementDescriptionRequiredException => __('messages.shifts.error_movement_note_required'),
            $e instanceof MovementOnClosedShiftException => __('messages.shifts.error_movement_on_closed_shift'),
            $e instanceof ReversalReasonRequiredException => __('messages.shifts.error_reversal_reason_required'),
            $e instanceof CannotReverseReversalException => __('messages.shifts.error_cannot_reverse_reversal'),
            $e instanceof MovementAlreadyReversedException => __('messages.shifts.error_movement_already_reversed'),
            $e instanceof ReversalOnClosedShiftException => __('messages.shifts.error_reversal_on_closed_shift'),
            default => (function () use ($e, $context): string {
                Log::error('Unhandled shift or cash drawer exception in ShiftManager', [
                    'context' => $context,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return __('messages.shifts.error_generic');
            })(),
        };
    }

    /**
     * Render the Livewire component view.
     */
    public function render(ShiftService $shiftService): View
    {
        $activeShift = $shiftService->getActiveShift();
        $expectedCash = $activeShift ? $shiftService->calculateExpectedCash($activeShift) : 0;
        $discrepancy = $this->actualCash - $expectedCash;

        $movements = $activeShift
            ? $activeShift->cashMovements()->with('user')->latest()->get()
            : collect();

        $pastShifts = Shift::closed()
            ->with(['user', 'closedByUser'])
            ->latest('closed_at')
            ->paginate(10);

        return view('livewire.shifts.shift-manager', [
            'activeShift' => $activeShift,
            'expectedCash' => $expectedCash,
            'discrepancy' => $discrepancy,
            'movements' => $movements,
            'pastShifts' => $pastShifts,
        ])->layout('components.layouts.app', [
            'title' => __('messages.shifts.page_title'),
            'headerTitle' => __('messages.shifts.header_title'),
            'headerSubtitle' => __('messages.shifts.header_subtitle'),
        ]);
    }
}
