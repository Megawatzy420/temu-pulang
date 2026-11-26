<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\RefundTransaction;
use App\Models\ShopSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DashboardMetricsService
{
    /**
     * Payment statuses counted toward Gross Sales and Paid Orders.
     *
     * @var list<string>
     */
    public const SALES_PAYMENT_STATUSES = [
        Order::PAYMENT_STATUS_PAID,
        Order::PAYMENT_STATUS_PARTIALLY_REFUNDED,
        Order::PAYMENT_STATUS_REFUNDED,
    ];

    /**
     * Payment statuses counted toward Best Sellers (excludes fully refunded orders).
     *
     * @var list<string>
     */
    public const BEST_SELLER_PAYMENT_STATUSES = [
        Order::PAYMENT_STATUS_PAID,
        Order::PAYMENT_STATUS_PARTIALLY_REFUNDED,
    ];

    /**
     * Resolve the current business day [start, end) window using shop_settings.business_day_start_time
     * (fallback '06:00:00') and the shop timezone (fallback 'Asia/Jakarta').
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, timezone: string}
     */
    public function resolveBusinessDayWindow(?CarbonInterface $now = null, ?ShopSetting $setting = null): array
    {
        $shopSetting = $setting ?? ShopSetting::where('is_active', true)->first();
        $startTime = $shopSetting?->business_day_start_time ?: '06:00:00';
        $timezone = $shopSetting?->timezone ?: 'Asia/Jakarta';

        $reference = ($now !== null ? CarbonImmutable::instance($now) : CarbonImmutable::now())
            ->setTimezone($timezone);

        $parts = array_pad(explode(':', $startTime), 3, '0');
        $cutoffHour = (int) $parts[0];
        $cutoffMinute = (int) $parts[1];
        $cutoffSecond = (int) $parts[2];

        $todayCutoff = $reference->setTime($cutoffHour, $cutoffMinute, $cutoffSecond);

        if ($reference->lt($todayCutoff)) {
            $start = $todayCutoff->subDay();
            $end = $todayCutoff;
        } else {
            $start = $todayCutoff;
            $end = $todayCutoff->addDay();
        }

        return [
            'start' => $start,
            'end' => $end,
            'timezone' => $timezone,
        ];
    }

    /**
     * Compute gross sales (sum of order total_amount) for orders paid within [start, end)
     * whose payment_status is paid, partially_refunded, or refunded.
     */
    public function grossSales(CarbonInterface $start, CarbonInterface $end): int
    {
        return $this->salesAggregate($start, $end)['gross_sales'];
    }

    /**
     * Compute count of orders paid within [start, end) whose payment_status is
     * paid, partially_refunded, or refunded.
     */
    public function paidOrdersCount(CarbonInterface $start, CarbonInterface $end): int
    {
        return $this->salesAggregate($start, $end)['paid_orders'];
    }

    /**
     * Compute average ticket in integer Rupiah: gross sales intdiv paid orders (0 when none).
     */
    public function averageTicket(int $grossSales, int $paidOrders): int
    {
        if ($paidOrders <= 0) {
            return 0;
        }

        return intdiv($grossSales, $paidOrders);
    }

    /**
     * Compute total refunds created within [start, end) by the refund's own created_at timestamp.
     */
    public function refundsTotal(CarbonInterface $start, CarbonInterface $end): int
    {
        [$dbStart, $dbEnd] = $this->toDatabaseWindow($start, $end);

        return (int) RefundTransaction::query()
            ->where('created_at', '>=', $dbStart)
            ->where('created_at', '<', $dbEnd)
            ->sum('total_refunded_amount');
    }

    /**
     * Compute net sales (gross sales minus refunds).
     */
    public function netSales(int $grossSales, int $refunds): int
    {
        return $grossSales - $refunds;
    }

    /**
     * Compute payment mix (money applied to the bill, never cash tendered) per method
     * for orders paid within [start, end). Includes an 'other' bucket for credit/transfer.
     * Refunds are not subtracted from payment mix.
     *
     * @return array{cash: int, qris: int, debit: int, other: int, total: int}
     */
    public function paymentMix(CarbonInterface $start, CarbonInterface $end): array
    {
        [$dbStart, $dbEnd] = $this->toDatabaseWindow($start, $end);

        $rows = Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.paid_at', '>=', $dbStart)
            ->where('orders.paid_at', '<', $dbEnd)
            ->whereIn('orders.payment_status', self::SALES_PAYMENT_STATUSES)
            ->selectRaw('payments.payment_method, COALESCE(SUM(payments.amount), 0) as total_amount')
            ->groupBy('payments.payment_method')
            ->pluck('total_amount', 'payment_method');

        $cash = 0;
        $qris = 0;
        $debit = 0;
        $other = 0;

        foreach ($rows as $method => $amount) {
            $intAmount = (int) $amount;
            match ((string) $method) {
                'cash' => $cash += $intAmount,
                'qris' => $qris += $intAmount,
                'debit' => $debit += $intAmount,
                default => $other += $intAmount,
            };
        }

        return [
            'cash' => $cash,
            'qris' => $qris,
            'debit' => $debit,
            'other' => $other,
            'total' => $cash + $qris + $debit + $other,
        ];
    }

    /**
     * Compute net sales change vs previous business day in tenths of a percent using
     * pure integer arithmetic (half-up rounding). Shows a dash ('—') when previous net sales <= 0.
     *
     * @return array{tenths: ?int, direction: string, formatted: string}
     */
    public function percentChangeInTenths(int $todayNetSales, int $previousNetSales): array
    {
        if ($previousNetSales <= 0) {
            return [
                'tenths' => null,
                'direction' => 'none',
                'formatted' => '—',
            ];
        }

        $diff = $todayNetSales - $previousNetSales;

        if ($diff === 0) {
            return [
                'tenths' => 0,
                'direction' => 'flat',
                'formatted' => '0.0%',
            ];
        }

        $absDiff = abs($diff);
        // Half-up integer rounding of ($absDiff * 1000) / $previousNetSales:
        // intdiv(2 * ($absDiff * 1000) + $previousNetSales, 2 * $previousNetSales)
        $tenths = intdiv((2 * $absDiff * 1000) + $previousNetSales, 2 * $previousNetSales);
        $whole = intdiv($tenths, 10);
        $fraction = $tenths % 10;

        $direction = $diff > 0 ? 'up' : 'down';
        $arrow = $diff > 0 ? '↑' : '↓';
        $signedTenths = $diff > 0 ? $tenths : -$tenths;

        return [
            'tenths' => $signedTenths,
            'direction' => $direction,
            'formatted' => "{$arrow} {$whole}.{$fraction}%",
        ];
    }

    /**
     * Fetch the last 5 orders created within the business day [start, end) (any status), newest first.
     *
     * @return Collection<int, array{
     *     id: int,
     *     order_number: string,
     *     call_number: string,
     *     customer_name: ?string,
     *     order_type: string,
     *     total_amount: int,
     *     status: string,
     *     payment_status: string,
     *     time: string
     * }>
     */
    public function recentOrders(CarbonInterface $start, CarbonInterface $end, string $timezone = 'Asia/Jakarta', int $limit = 5): Collection
    {
        [$dbStart, $dbEnd] = $this->toDatabaseWindow($start, $end);

        return Order::query()
            ->where('created_at', '>=', $dbStart)
            ->where('created_at', '<', $dbEnd)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'order_number',
                'daily_order_number',
                'daily_sequence',
                'customer_name',
                'order_type',
                'total_amount',
                'status',
                'payment_status',
                'created_at',
            ])
            ->map(function (Order $order) use ($timezone): array {
                return [
                    'id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'call_number' => $order->call_number,
                    'customer_name' => $order->customer_name,
                    'order_type' => (string) $order->order_type,
                    'total_amount' => (int) $order->total_amount,
                    'status' => (string) $order->status,
                    'payment_status' => (string) $order->payment_status,
                    'time' => $order->created_at
                        ? $order->created_at->copy()->setTimezone($timezone)->format('H:i')
                        : '',
                ];
            });
    }

    /**
     * Fetch the top 4 products by quantity sold in orders paid within [start, end)
     * whose payment_status is paid or partially_refunded (excluding fully refunded orders).
     * Uses order_items snapshot names and line subtotals.
     *
     * @return Collection<int, array{
     *     name: string,
     *     product_name_id: string,
     *     product_name_en: ?string,
     *     sku: string,
     *     sold_count: int,
     *     revenue: int,
     *     percentage: int
     * }>
     */
    public function bestSellers(CarbonInterface $start, CarbonInterface $end, int $limit = 4): Collection
    {
        [$dbStart, $dbEnd] = $this->toDatabaseWindow($start, $end);

        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.paid_at', '>=', $dbStart)
            ->where('orders.paid_at', '<', $dbEnd)
            ->whereIn('orders.payment_status', self::BEST_SELLER_PAYMENT_STATUSES)
            ->selectRaw('
                order_items.product_name_id,
                order_items.product_name_en,
                order_items.sku,
                COALESCE(SUM(order_items.quantity), 0) as sold_count,
                COALESCE(SUM(order_items.subtotal), 0) as revenue
            ')
            ->groupBy('order_items.product_name_id', 'order_items.product_name_en', 'order_items.sku')
            ->orderByDesc('sold_count')
            ->orderByDesc('revenue')
            ->orderBy('order_items.product_name_id')
            ->limit($limit)
            ->get();

        $maxSold = (int) ($rows->first()?->sold_count ?? 0);
        $locale = app()->getLocale();

        return $rows->map(function ($row) use ($maxSold, $locale): array {
            $soldCount = (int) $row->sold_count;
            $revenue = (int) $row->revenue;
            $nameId = (string) $row->product_name_id;
            $nameEn = $row->product_name_en !== null && $row->product_name_en !== ''
                ? (string) $row->product_name_en
                : null;

            $displayName = ($locale === 'en' && $nameEn !== null) ? $nameEn : $nameId;
            $percentage = $maxSold > 0 ? max(1, intdiv($soldCount * 100, $maxSold)) : 0;

            return [
                'name' => $displayName,
                'product_name_id' => $nameId,
                'product_name_en' => $nameEn,
                'sku' => (string) ($row->sku ?? ''),
                'sold_count' => $soldCount,
                'revenue' => $revenue,
                'percentage' => $percentage,
            ];
        });
    }

    /**
     * Format integer basis points (e.g. 0 -> '0', 1000 -> '10', 1050 -> '10.5')
     * using integer arithmetic only.
     */
    public static function formatBasisPoints(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $remainder = abs($basisPoints % 100);

        if ($remainder === 0) {
            return (string) $whole;
        }

        $tenths = intdiv(($remainder * 2) + 10, 20);
        if ($tenths === 10) {
            return (string) ($whole + 1);
        }

        return $whole.'.'.$tenths;
    }

    /**
     * Build the full dashboard snapshot in a small constant number of aggregate queries.
     *
     * @return array{
     *     window: array{start: CarbonImmutable, end: CarbonImmutable, timezone: string},
     *     gross_sales: int,
     *     refunds: int,
     *     net_sales: int,
     *     paid_orders: int,
     *     avg_ticket: int,
     *     previous_gross_sales: int,
     *     previous_refunds: int,
     *     previous_net_sales: int,
     *     change_vs_previous: array{tenths: ?int, direction: string, formatted: string},
     *     payment_mix: array{cash: int, qris: int, debit: int, other: int, total: int},
     *     recent_orders: Collection,
     *     best_sellers: Collection
     * }
     */
    public function getDashboardSnapshot(?CarbonInterface $now = null, ?ShopSetting $setting = null): array
    {
        $window = $this->resolveBusinessDayWindow($now, $setting);
        $start = $window['start'];
        $end = $window['end'];
        $timezone = $window['timezone'];

        $prevStart = $start->subDay();
        $prevEnd = $start;

        $todaySales = $this->salesAggregate($start, $end);
        $grossSales = $todaySales['gross_sales'];
        $paidOrders = $todaySales['paid_orders'];
        $refunds = $this->refundsTotal($start, $end);
        $netSales = $this->netSales($grossSales, $refunds);
        $avgTicket = $this->averageTicket($grossSales, $paidOrders);

        $prevSales = $this->salesAggregate($prevStart, $prevEnd);
        $prevGrossSales = $prevSales['gross_sales'];
        $prevRefunds = $this->refundsTotal($prevStart, $prevEnd);
        $prevNetSales = $this->netSales($prevGrossSales, $prevRefunds);

        return [
            'window' => $window,
            'gross_sales' => $grossSales,
            'refunds' => $refunds,
            'net_sales' => $netSales,
            'paid_orders' => $paidOrders,
            'avg_ticket' => $avgTicket,
            'previous_gross_sales' => $prevGrossSales,
            'previous_refunds' => $prevRefunds,
            'previous_net_sales' => $prevNetSales,
            'change_vs_previous' => $this->percentChangeInTenths($netSales, $prevNetSales),
            'payment_mix' => $this->paymentMix($start, $end),
            'recent_orders' => $this->recentOrders($start, $end, $timezone),
            'best_sellers' => $this->bestSellers($start, $end),
        ];
    }

    /**
     * Single SQL aggregate query for gross sales and paid order count within [start, end).
     *
     * @return array{gross_sales: int, paid_orders: int}
     */
    private function salesAggregate(CarbonInterface $start, CarbonInterface $end): array
    {
        [$dbStart, $dbEnd] = $this->toDatabaseWindow($start, $end);

        $row = Order::query()
            ->where('paid_at', '>=', $dbStart)
            ->where('paid_at', '<', $dbEnd)
            ->whereIn('payment_status', self::SALES_PAYMENT_STATUSES)
            ->selectRaw('COALESCE(SUM(total_amount), 0) as gross_sales, COUNT(*) as paid_orders')
            ->first();

        return [
            'gross_sales' => (int) ($row?->gross_sales ?? 0),
            'paid_orders' => (int) ($row?->paid_orders ?? 0),
        ];
    }

    /**
     * Convert business-day boundary timestamps into the application/database timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function toDatabaseWindow(CarbonInterface $start, CarbonInterface $end): array
    {
        $dbTz = (string) config('app.timezone', 'Asia/Jakarta');

        return [
            CarbonImmutable::instance($start)->setTimezone($dbTz),
            CarbonImmutable::instance($end)->setTimezone($dbTz),
        ];
    }
}
