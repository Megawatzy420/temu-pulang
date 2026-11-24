<?php

namespace App\Livewire\Shifts;

use App\Models\CashMovement;
use App\Models\Shift;
use App\Services\CashMovementService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

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
            $this->dispatch('notify', message: 'Shift kasir berhasil dibuka.', type: 'success');
        } catch (DomainException $e) {
            $this->addError('openingCash', $e->getMessage());
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
            $this->addError('movementAmount', 'Tidak ada shift aktif untuk mencatat pergerakan kas.');

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
            $this->dispatch('notify', message: 'Pergerakan kas drawer berhasil dicatat.', type: 'success');
        } catch (DomainException $e) {
            $this->addError('movementAmount', $e->getMessage());
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
            $this->addError('reversalReason', 'Data pergerakan kas tidak ditemukan.');

            return;
        }

        try {
            $movementService->reverseMovement(
                movement: $movement,
                user: Auth::user(),
                reason: $this->reversalReason
            );

            $this->showReversalModal = false;
            $this->dispatch('notify', message: 'Pergerakan kas berhasil dibatalkan/dibalikkan.', type: 'info');
        } catch (DomainException $e) {
            $this->addError('reversalReason', $e->getMessage());
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
            $this->addError('actualCash', 'Tidak ada shift aktif untuk ditutup.');

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
            $this->dispatch('notify', message: 'Shift kasir berhasil ditutup dan direkonsiliasi.', type: 'success');
        } catch (DomainException $e) {
            $this->addError('closingNote', $e->getMessage());
        }
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
            'title' => 'Kelola Shift & Kas Drawer',
            'headerTitle' => 'Manajemen Shift & Kas Drawer',
            'headerSubtitle' => 'Pantau status shift aktif, arus kas masuk/keluar, dan penutupan kasir',
        ]);
    }
}
