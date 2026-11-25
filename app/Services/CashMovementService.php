<?php

namespace App\Services;

use App\Exceptions\Cash\CannotReverseReversalException;
use App\Exceptions\Cash\InvalidCashMovementTypeException;
use App\Exceptions\Cash\MovementAlreadyReversedException;
use App\Exceptions\Cash\MovementDescriptionRequiredException;
use App\Exceptions\Cash\MovementOnClosedShiftException;
use App\Exceptions\Cash\NonPositiveMovementAmountException;
use App\Exceptions\Cash\ReversalOnClosedShiftException;
use App\Exceptions\Cash\ReversalReasonRequiredException;
use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\Shift;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CashMovementService
{
    /**
     * Record a new cash movement (inflow or outflow) associated with an active shift.
     *
     * @throws DomainException|InvalidArgumentException
     */
    public function recordMovement(
        Shift $shift,
        User $user,
        string $type,
        int $amount,
        string $category,
        string $description,
        ?User $approvedBy = null
    ): CashMovement {
        $type = strtolower(trim($type));

        if (! in_array($type, ['in', 'out'], true)) {
            throw new InvalidCashMovementTypeException("Tipe pergerakan kas tidak valid: '{$type}'. Gunakan 'in' atau 'out'.");
        }

        if ($amount <= 0) {
            throw new NonPositiveMovementAmountException('Jumlah uang pergerakan kas harus lebih besar dari 0 Rupiah.');
        }

        if (empty(trim($description))) {
            throw new MovementDescriptionRequiredException('Keterangan atau alasan pergerakan kas wajib diisi.');
        }

        if ($shift->isClosed()) {
            throw new MovementOnClosedShiftException('Tidak dapat mencatat pergerakan kas pada shift yang sudah ditutup.');
        }

        return DB::transaction(function () use ($shift, $user, $type, $amount, $category, $description, $approvedBy) {
            // Re-verify shift state under lock
            $freshShift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();
            if ($freshShift->isClosed()) {
                throw new MovementOnClosedShiftException('Tidak dapat mencatat pergerakan kas pada shift yang sudah ditutup.');
            }

            $movement = CashMovement::create([
                'shift_id' => $freshShift->id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'category' => $category,
                'description' => trim($description),
                'approved_by_user_id' => $approvedBy?->id,
            ]);

            $typeName = $type === 'in' ? 'Kas Masuk' : 'Kas Keluar';
            $formattedAmount = 'Rp '.number_format($amount, 0, ',', '.');

            ActivityLog::record(
                action: 'cash_movement.created',
                description: "{$typeName} sebesar {$formattedAmount} dicatat oleh {$user->name} pada Shift {$freshShift->shift_number}. Kategori: {$category}, Alasan: {$description}",
                subject: $movement,
                properties: [
                    'shift_id' => $freshShift->id,
                    'shift_number' => $freshShift->shift_number,
                    'type' => $type,
                    'amount' => $amount,
                    'category' => $category,
                    'description' => $description,
                ],
                user: $user,
                module: 'cash_movement'
            );

            return $movement;
        });
    }

    /**
     * Reverse an existing cash movement by creating an audited opposite movement.
     *
     * @throws DomainException
     */
    public function reverseMovement(CashMovement $movement, User $user, string $reason): CashMovement
    {
        if (empty(trim($reason))) {
            throw new ReversalReasonRequiredException('Alasan pembatalan/pembalikan pergerakan kas wajib diisi.');
        }

        if ($movement->is_reversal) {
            throw new CannotReverseReversalException('Transaksi pembalikan tidak dapat dibatalkan kembali.');
        }

        if ($movement->isReversed()) {
            throw new MovementAlreadyReversedException('Pergerakan kas ini sudah pernah dibatalkan sebelumnya.');
        }

        $shift = $movement->shift;
        if ($shift->isClosed()) {
            throw new ReversalOnClosedShiftException('Tidak dapat membatalkan pergerakan kas pada shift yang sudah ditutup.');
        }

        return DB::transaction(function () use ($movement, $user, $reason) {
            /** @var CashMovement $lockedMovement */
            $lockedMovement = CashMovement::query()
                ->where('id', $movement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMovement->is_reversal) {
                throw new CannotReverseReversalException('Transaksi pembalikan tidak dapat dibatalkan kembali.');
            }

            if ($lockedMovement->isReversed()) {
                throw new MovementAlreadyReversedException('Pergerakan kas ini sudah pernah dibatalkan sebelumnya.');
            }

            $shift = Shift::query()
                ->where('id', $lockedMovement->shift_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->isClosed()) {
                throw new ReversalOnClosedShiftException('Tidak dapat membatalkan pergerakan kas pada shift yang sudah ditutup.');
            }

            // Opposite type for reversal
            $reverseType = $lockedMovement->type === 'in' ? 'out' : 'in';

            try {
                $reversalRecord = CashMovement::create([
                    'shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'type' => $reverseType,
                    'amount' => $lockedMovement->amount,
                    'category' => 'reversal',
                    'description' => "Pembalikan transaksi #{$lockedMovement->id}: {$reason}",
                    'is_reversal' => true,
                    'reversed_movement_id' => $lockedMovement->id,
                    'reversal_reason' => trim($reason),
                    'approved_by_user_id' => $user->id,
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'uq_reversed_movement_once')) {
                    throw new MovementAlreadyReversedException('Pergerakan kas ini sudah pernah dibatalkan sebelumnya.');
                }
                throw $e;
            }

            $formattedAmount = 'Rp '.number_format($lockedMovement->amount, 0, ',', '.');
            ActivityLog::record(
                action: 'cash_movement.reversed',
                description: "Pembalikan pergerakan kas #{$lockedMovement->id} ({$formattedAmount}) dilakukan oleh {$user->name}. Alasan: {$reason}",
                subject: $reversalRecord,
                properties: [
                    'original_movement_id' => $lockedMovement->id,
                    'reversal_movement_id' => $reversalRecord->id,
                    'shift_number' => $shift->shift_number,
                    'amount' => $lockedMovement->amount,
                    'reason' => $reason,
                ],
                user: $user,
                module: 'cash_movement'
            );

            return $reversalRecord;
        });
    }
}
