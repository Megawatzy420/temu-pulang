<?php

namespace App\Services;

use App\Exceptions\Shift\ClosingNoteRequiredForDiscrepancyException;
use App\Exceptions\Shift\NegativeCountedCashException;
use App\Exceptions\Shift\NegativeOpeningCashException;
use App\Exceptions\Shift\ShiftAlreadyActiveException;
use App\Exceptions\Shift\ShiftAlreadyClosedException;
use App\Exceptions\Shift\UnauthorizedShiftCloseException;
use App\Models\ActivityLog;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    /**
     * Get currently active open shift for branch.
     */
    public function getActiveShift(?int $branchId = null): ?Shift
    {
        return Shift::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    /**
     * Open a new cashier shift.
     *
     * @throws DomainException
     */
    public function openShift(User $user, int $openingCash, ?string $openingNote = null, ?int $branchId = null): Shift
    {
        if ($openingCash < 0) {
            throw new NegativeOpeningCashException('Jumlah kas awal tidak boleh bernilai negatif.');
        }

        return DB::transaction(function () use ($user, $openingCash, $openingNote, $branchId) {
            // Serialize concurrent shift openings by locking the shop setting record for this branch
            ShopSetting::query()
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()
                ->first();

            // Pessimistic lock check on active shifts
            $activeCount = Shift::query()
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->where('status', 'open')
                ->lockForUpdate()
                ->count();

            if ($activeCount > 0) {
                throw new ShiftAlreadyActiveException('Masih ada shift yang aktif. Tutup shift aktif terlebih dahulu sebelum membuka shift baru.');
            }

            $datePrefix = now()->format('Ymd');
            $sequence = Shift::whereDate('opened_at', now()->toDateString())->count() + 1;
            $shiftNumber = sprintf('SFT-%s-%04d', $datePrefix, $sequence);

            try {
                $shift = Shift::create([
                    'branch_id' => $branchId,
                    'shift_number' => $shiftNumber,
                    'user_id' => $user->id,
                    'status' => 'open',
                    'opened_at' => now(),
                    'opening_cash' => $openingCash,
                    'opening_note' => $openingNote,
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'uq_active_shift')) {
                    throw new ShiftAlreadyActiveException('Masih ada shift yang aktif. Tutup shift aktif terlebih dahulu sebelum membuka shift baru.');
                }
                throw $e;
            }

            $formattedCash = 'Rp '.number_format($openingCash, 0, ',', '.');
            ActivityLog::record(
                action: 'shift.opened',
                description: "Shift {$shift->shift_number} dibuka oleh {$user->name} dengan kas awal {$formattedCash}.",
                subject: $shift,
                properties: [
                    'shift_number' => $shift->shift_number,
                    'opening_cash' => $openingCash,
                    'opening_note' => $openingNote,
                ],
                user: $user,
                module: 'shift'
            );

            return $shift;
        });
    }

    /**
     * Calculate expected cash balance in drawer for a shift.
     * Formula: Opening Cash + Manual Cash In - Manual Cash Out + Net Cash Sales - Cash Refunds
     */
    public function calculateExpectedCash(Shift $shift): int
    {
        return $shift->currentExpectedCash();
    }

    /**
     * Get complete structured cash reconciliation summary for a shift.
     *
     * @return array{
     *     opening_cash: int,
     *     manual_cash_in: int,
     *     manual_cash_out: int,
     *     cash_sales: int,
     *     cash_tendered: int,
     *     cash_change: int,
     *     cash_refunds: int,
     *     non_cash_sales: int,
     *     expected_cash: int
     * }
     */
    public function getCashSummary(Shift $shift): array
    {
        return $shift->cashReconciliationSummary();
    }

    /**
     * Close an active shift with physical cash drawer count and discrepancy calculation.
     *
     * @throws DomainException
     */
    public function closeShift(Shift $shift, User $closingUser, int $actualCash, ?string $closingNote = null): Shift
    {
        if ($actualCash < 0) {
            throw new NegativeCountedCashException('Jumlah kas fisik tidak boleh bernilai negatif.');
        }

        if ($shift->isClosed()) {
            throw new ShiftAlreadyClosedException('Shift ini sudah ditutup dan tidak dapat diubah kembali.');
        }

        // Cashier cannot close another cashier's shift unless closingUser is Owner
        if (! $closingUser->isOwner() && $closingUser->id !== $shift->user_id) {
            throw new UnauthorizedShiftCloseException('Anda tidak berwenang menutup shift milik kasir lain.');
        }

        return DB::transaction(function () use ($shift, $closingUser, $actualCash, $closingNote) {
            /** @var Shift $lockedShift */
            $lockedShift = Shift::query()
                ->where('id', $shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedShift->isClosed()) {
                throw new ShiftAlreadyClosedException('Shift ini sudah ditutup.');
            }

            $expectedCash = $this->calculateExpectedCash($lockedShift);
            $discrepancy = $actualCash - $expectedCash;

            // Discrepancy requires a closing note explanation
            if ($discrepancy !== 0 && (empty($closingNote) || trim($closingNote) === '')) {
                throw new ClosingNoteRequiredForDiscrepancyException('Catatan penutupan wajib diisi apabila terdapat selisih kas.');
            }

            $lockedShift->update([
                'status' => 'closed',
                'closed_by_user_id' => $closingUser->id,
                'closed_at' => now(),
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'discrepancy' => $discrepancy,
                'closing_note' => $closingNote,
            ]);

            $formattedActual = 'Rp '.number_format($actualCash, 0, ',', '.');
            $formattedExpected = 'Rp '.number_format($expectedCash, 0, ',', '.');
            $formattedDiscrepancy = ($discrepancy >= 0 ? '+' : '').'Rp '.number_format($discrepancy, 0, ',', '.');

            $summary = $lockedShift->cashReconciliationSummary();

            ActivityLog::record(
                action: 'shift.closed',
                description: "Shift {$lockedShift->shift_number} ditutup oleh {$closingUser->name}. Kas Aktual: {$formattedActual}, Kas Diharapkan: {$formattedExpected}, Selisih: {$formattedDiscrepancy}.",
                subject: $lockedShift,
                properties: [
                    'shift_number' => $lockedShift->shift_number,
                    'opening_cash' => $lockedShift->opening_cash,
                    'manual_cash_in' => $summary['manual_cash_in'],
                    'manual_cash_out' => $summary['manual_cash_out'],
                    'cash_sales' => $summary['cash_sales'],
                    'cash_tendered' => $summary['cash_tendered'],
                    'cash_change' => $summary['cash_change'],
                    'cash_refunds' => $summary['cash_refunds'],
                    'non_cash_sales' => $summary['non_cash_sales'],
                    'expected_cash' => $expectedCash,
                    'actual_cash' => $actualCash,
                    'discrepancy' => $discrepancy,
                    'closing_note' => $closingNote,
                ],
                user: $closingUser,
                module: 'shift'
            );

            return $lockedShift;
        });
    }
}
