<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Shift;
use App\Models\ShopSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ShiftReportService
{
    /**
     * Build a complete read-only X-Report (open shift) or Z-Report (closed shift)
     * as a plain array of integers and strings using a small constant number of
     * SQL aggregate queries and integer Rupiah arithmetic only.
     *
     * @return array{
     *     report_type: string,
     *     is_open: bool,
     *     header: array{
     *         shop_name: string,
     *         shift_id: int,
     *         shift_number: string,
     *         cashier_name: string,
     *         closed_by_name: string,
     *         status: string,
     *         opened_at: string,
     *         closed_at: ?string,
     *         generated_at: string,
     *         opening_note: string,
     *         closing_note: string
     *     },
     *     sales: array{
     *         gross_sales: int,
     *         subtotal_sales: int,
     *         tax_total: int,
     *         service_charge_total: int,
     *         discount_total: int,
     *         paid_orders_count: int,
     *         avg_ticket: int,
     *         refunds_total: int,
     *         net_sales: int
     *     },
     *     excluded_orders: array{
     *         unpaid_count: int,
     *         cancelled_count: int,
     *         total_count: int
     *     },
     *     payments_by_method: array{
     *         cash: int,
     *         qris: int,
     *         debit: int,
     *         other: int,
     *         total: int
     *     },
     *     refunds: array{
     *         count: int,
     *         total: int,
     *         by_method: array{
     *             cash: int,
     *             qris_reversal: int,
     *             manual_transfer: int
     *         }
     *     },
     *     cash_drawer: array{
     *         opening_cash: int,
     *         manual_cash_in: int,
     *         manual_cash_out: int,
     *         cash_sales: int,
     *         cash_tendered: int,
     *         cash_change: int,
     *         cash_refunds: int,
     *         non_cash_sales: int,
     *         expected_cash: int,
     *         counted_cash: ?int,
     *         variance: ?int,
     *         closing_note: string,
     *         movements_by_category: list<array{
     *             type: string,
     *             category: string,
     *             is_reversal: bool,
     *             count: int,
     *             amount: int
     *         }>
     *     }
     * }
     */
    public function buildReport(Shift $shift, ?CarbonInterface $generatedAt = null): array
    {
        $shift->loadMissing(['user', 'closedByUser']);

        $shopSetting = ShopSetting::current($shift->branch_id);
        $timezone = $shopSetting?->timezone ?: 'Asia/Jakarta';
        $shopName = (string) ($shopSetting?->name ?: config('app.name', 'Temu Pulang'));

        $isOpen = $shift->isOpen();

        // 1. Sales aggregate for paid orders whose payment transaction was settled in this shift
        $salesRow = Order::query()
            ->join('payment_transactions', 'payment_transactions.order_id', '=', 'orders.id')
            ->whereNull('orders.deleted_at')
            ->where('payment_transactions.shift_id', $shift->id)
            ->whereIn('orders.payment_status', [
                Order::PAYMENT_STATUS_PAID,
                Order::PAYMENT_STATUS_PARTIALLY_REFUNDED,
                Order::PAYMENT_STATUS_REFUNDED,
            ])
            ->selectRaw('
                COALESCE(SUM(orders.total_amount), 0) as gross_sales,
                COALESCE(SUM(orders.subtotal), 0) as subtotal_sales,
                COALESCE(SUM(orders.tax_amount), 0) as tax_total,
                COALESCE(SUM(orders.service_charge_amount), 0) as service_charge_total,
                COALESCE(SUM(orders.discount_amount), 0) as discount_total,
                COUNT(orders.id) as paid_orders_count
            ')
            ->first();

        $grossSales = (int) ($salesRow?->gross_sales ?? 0);
        $subtotalSales = (int) ($salesRow?->subtotal_sales ?? 0);
        $taxTotal = (int) ($salesRow?->tax_total ?? 0);
        $serviceChargeTotal = (int) ($salesRow?->service_charge_total ?? 0);
        $discountTotal = (int) ($salesRow?->discount_total ?? 0);
        $paidOrdersCount = (int) ($salesRow?->paid_orders_count ?? 0);
        $avgTicket = $paidOrdersCount > 0 ? intdiv($grossSales, $paidOrdersCount) : 0;

        // 2. Cancelled or unpaid orders of this shift (excluded from sales, counted separately)
        $excludedRow = Order::query()
            ->where('shift_id', $shift->id)
            ->where('payment_status', Order::PAYMENT_STATUS_UNPAID)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) as cancelled_count,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN 1 ELSE 0 END), 0) as unpaid_count
            ")
            ->first();

        $unpaidCount = (int) ($excludedRow?->unpaid_count ?? 0);
        $cancelledCount = (int) ($excludedRow?->cancelled_count ?? 0);

        // 3. Payments received in this shift grouped by payment_method
        $paymentRows = Payment::query()
            ->where('shift_id', $shift->id)
            ->selectRaw('
                payment_method,
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(SUM(tendered_amount), 0) as total_tendered,
                COALESCE(SUM(change_amount), 0) as total_change
            ')
            ->groupBy('payment_method')
            ->get();

        $cashPayments = 0;
        $cashTendered = 0;
        $cashChange = 0;
        $qrisPayments = 0;
        $debitPayments = 0;
        $otherPayments = 0;

        foreach ($paymentRows as $row) {
            $method = (string) $row->payment_method;
            $amount = (int) $row->total_amount;

            if ($method === 'cash') {
                $cashPayments += $amount;
                $cashTendered += (int) $row->total_tendered;
                $cashChange += (int) $row->total_change;
            } elseif ($method === 'qris') {
                $qrisPayments += $amount;
            } elseif ($method === 'debit') {
                $debitPayments += $amount;
            } else {
                $otherPayments += $amount;
            }
        }

        $nonCashPayments = $qrisPayments + $debitPayments + $otherPayments;
        $totalPaymentsReceived = $cashPayments + $nonCashPayments;

        // 4. Refunds recorded in this shift grouped by refund_method
        $refundRows = PaymentRefund::query()
            ->where('shift_id', $shift->id)
            ->selectRaw('
                refund_method,
                COALESCE(SUM(amount), 0) as total_amount,
                COUNT(*) as refund_count
            ')
            ->groupBy('refund_method')
            ->get();

        $cashRefunds = 0;
        $qrisReversalRefunds = 0;
        $manualTransferRefunds = 0;
        $refundsCount = 0;

        foreach ($refundRows as $row) {
            $method = (string) $row->refund_method;
            $amount = (int) $row->total_amount;
            $count = (int) $row->refund_count;
            $refundsCount += $count;

            match ($method) {
                'cash' => $cashRefunds += $amount,
                'qris_reversal' => $qrisReversalRefunds += $amount,
                'manual_transfer' => $manualTransferRefunds += $amount,
                default => null,
            };
        }

        $refundsTotal = $cashRefunds + $qrisReversalRefunds + $manualTransferRefunds;
        $netSales = $grossSales - $refundsTotal;

        // 5. Cash drawer movements in this shift grouped by type, category, and is_reversal
        $movementRows = CashMovement::query()
            ->where('shift_id', $shift->id)
            ->selectRaw('
                type,
                category,
                is_reversal,
                COUNT(*) as movement_count,
                COALESCE(SUM(amount), 0) as total_amount
            ')
            ->groupBy('type', 'category', 'is_reversal')
            ->orderBy('type')
            ->orderBy('category')
            ->get();

        $manualCashIn = 0;
        $manualCashOut = 0;
        $movementsByCategory = [];

        foreach ($movementRows as $row) {
            $type = (string) $row->type;
            $amount = (int) $row->total_amount;

            if ($type === 'in') {
                $manualCashIn += $amount;
            } elseif ($type === 'out') {
                $manualCashOut += $amount;
            }

            $movementsByCategory[] = [
                'type' => $type,
                'category' => (string) $row->category,
                'is_reversal' => (bool) $row->is_reversal,
                'count' => (int) $row->movement_count,
                'amount' => $amount,
            ];
        }

        $openingCash = (int) $shift->opening_cash;

        // Reuse the exact Shift::currentExpectedCash() formula:
        // Opening Cash + Manual Inflows - Manual Outflows + Net Cash Sales - Cash Refunds
        $expectedCash = $openingCash + $manualCashIn - $manualCashOut + $cashPayments - $cashRefunds;

        $generatedTimestamp = ($generatedAt !== null ? CarbonImmutable::instance($generatedAt) : CarbonImmutable::now())
            ->timezone($timezone)
            ->translatedFormat('d M Y, H:i');

        return [
            'report_type' => $isOpen ? 'x_report' : 'z_report',
            'is_open' => $isOpen,
            'header' => [
                'shop_name' => $shopName,
                'shift_id' => (int) $shift->id,
                'shift_number' => (string) $shift->shift_number,
                'cashier_name' => (string) ($shift->user?->name ?? '-'),
                'closed_by_name' => (string) ($shift->closedByUser?->name ?? ($shift->user?->name ?? '-')),
                'status' => (string) $shift->status,
                'opened_at' => $shift->opened_at
                    ? $shift->opened_at->copy()->timezone($timezone)->translatedFormat('d M Y, H:i')
                    : '-',
                'closed_at' => $shift->closed_at
                    ? $shift->closed_at->copy()->timezone($timezone)->translatedFormat('d M Y, H:i')
                    : null,
                'generated_at' => $generatedTimestamp,
                'opening_note' => (string) ($shift->opening_note ?? ''),
                'closing_note' => (string) ($shift->closing_note ?? ''),
            ],
            'sales' => [
                'gross_sales' => $grossSales,
                'subtotal_sales' => $subtotalSales,
                'tax_total' => $taxTotal,
                'service_charge_total' => $serviceChargeTotal,
                'discount_total' => $discountTotal,
                'paid_orders_count' => $paidOrdersCount,
                'avg_ticket' => $avgTicket,
                'refunds_total' => $refundsTotal,
                'net_sales' => $netSales,
            ],
            'excluded_orders' => [
                'unpaid_count' => $unpaidCount,
                'cancelled_count' => $cancelledCount,
                'total_count' => $unpaidCount + $cancelledCount,
            ],
            'payments_by_method' => [
                'cash' => $cashPayments,
                'qris' => $qrisPayments,
                'debit' => $debitPayments,
                'other' => $otherPayments,
                'total' => $totalPaymentsReceived,
            ],
            'refunds' => [
                'count' => $refundsCount,
                'total' => $refundsTotal,
                'by_method' => [
                    'cash' => $cashRefunds,
                    'qris_reversal' => $qrisReversalRefunds,
                    'manual_transfer' => $manualTransferRefunds,
                ],
            ],
            'cash_drawer' => [
                'opening_cash' => $openingCash,
                'manual_cash_in' => $manualCashIn,
                'manual_cash_out' => $manualCashOut,
                'cash_sales' => $cashPayments,
                'cash_tendered' => $cashTendered,
                'cash_change' => $cashChange,
                'cash_refunds' => $cashRefunds,
                'non_cash_sales' => $nonCashPayments,
                'expected_cash' => $expectedCash,
                'counted_cash' => $isOpen ? null : (int) ($shift->actual_cash ?? 0),
                'variance' => $isOpen ? null : (int) ($shift->discrepancy ?? 0),
                'closing_note' => (string) ($shift->closing_note ?? ''),
                'movements_by_category' => $movementsByCategory,
            ],
        ];
    }
}
