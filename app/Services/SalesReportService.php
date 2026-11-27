<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RefundTransaction;
use App\Models\ShopSetting;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalesReportService
{
    public function __construct(
        private readonly DashboardMetricsService $dashboardMetricsService,
    ) {}

    /**
     * Resolve today's business date (Y-m-d) in the shop timezone using the active business_day_start_time.
     */
    public function currentBusinessDate(?CarbonInterface $now = null, ?ShopSetting $setting = null): string
    {
        $window = $this->dashboardMetricsService->resolveBusinessDayWindow($now, $setting);

        return $window['start']->format('Y-m-d');
    }

    /**
     * Build the read-only Sales, COGS, and Gross-Profit report for an inclusive business-date range [from, to]
     * using a small constant number of aggregate queries.
     *
     * @return array{
     *     from_date: string,
     *     to_date: string,
     *     days_count: int,
     *     window: array{start: CarbonImmutable, end: CarbonImmutable, timezone: string, start_time: string, cutoff_seconds: int},
     *     generated_at: CarbonImmutable,
     *     totals: array{
     *         paid_orders_count: int,
     *         gross_sales: int,
     *         subtotal: int,
     *         tax: int,
     *         service_charge: int,
     *         discounts: int,
     *         refunds: int,
     *         refunds_count: int,
     *         net_sales: int,
     *         avg_ticket: int,
     *         cogs_milli: int,
     *         cogs: int,
     *         daily_rounded_cogs_sum: int,
     *         cogs_rounding_diff: int,
     *         has_cogs_rounding_note: bool,
     *         gross_profit: int,
     *         margin: array{tenths: ?int, formatted: string}
     *     },
     *     payment_mix: array{cash: int, qris: int, debit: int, other: int, total: int},
     *     uncosted_sales: array{
     *         amount: int,
     *         share_tenths: int,
     *         share_formatted: string
     *     },
     *     cogs_tracking: array{
     *         earliest_deduction_date: ?string,
     *         show_warning: bool
     *     },
     *     daily_rows: list<array{
     *         business_date: string,
     *         paid_orders_count: int,
     *         gross_sales: int,
     *         subtotal: int,
     *         tax: int,
     *         service_charge: int,
     *         discounts: int,
     *         refunds: int,
     *         refunds_count: int,
     *         net_sales: int,
     *         avg_ticket: int,
     *         cogs_milli: int,
     *         cogs: int,
     *         gross_profit: int,
     *         margin: array{tenths: ?int, formatted: string}
     *     }>
     * }
     */
    public function buildReport(
        string $fromBusinessDate,
        string $toBusinessDate,
        ?CarbonInterface $generatedAt = null,
        ?ShopSetting $setting = null,
    ): array {
        $shopSetting = $setting ?? ShopSetting::where('is_active', true)->first();
        $window = $this->dashboardMetricsService->resolveBusinessDateRangeWindow(
            $fromBusinessDate,
            $toBusinessDate,
            $shopSetting,
        );

        $timezone = $window['timezone'];
        $appTimezone = (string) config('app.timezone', 'Asia/Jakarta');

        if ($timezone !== $appTimezone) {
            throw new RuntimeException(
                "Shop timezone ({$timezone}) differs from database app timezone ({$appTimezone})."
            );
        }

        $start = $window['start'];
        $end = $window['end'];
        $cutoffSeconds = $window['cutoff_seconds'];

        $referenceNow = ($generatedAt !== null ? CarbonImmutable::instance($generatedAt) : CarbonImmutable::now())
            ->setTimezone($timezone);

        // 1. Daily Orders Aggregate (1 SQL query)
        $ordersByDay = $this->queryOrdersByDay($start, $end, $cutoffSeconds);

        // 2. Daily Refunds Aggregate (1 SQL query)
        $refundsByDay = $this->queryRefundsByDay($start, $end, $cutoffSeconds);

        // 3. Payment Mix Aggregate (1 SQL query via DashboardMetricsService)
        $paymentMix = $this->dashboardMetricsService->paymentMix($start, $end);

        // 4. Daily COGS Aggregate in Milli-Rupiah (1 SQL query)
        $cogsMilliByDay = $this->queryCogsMilliByDay($start, $end, $cutoffSeconds);

        // 5. Uncosted Sales Aggregate (1 SQL query)
        $uncostedAmount = $this->queryUncostedSales($start, $end);

        // 6. Earliest order_deduction movement timestamp for COGS tracking start warning (1 SQL query)
        $earliestDeductionDate = $this->queryEarliestDeductionBusinessDate($cutoffSeconds, $timezone);

        // Assemble daily rows in PHP across the inclusive date range
        $dailyRows = [];
        $cursor = CarbonImmutable::parse($fromBusinessDate, $timezone)->startOfDay();
        $endDate = CarbonImmutable::parse($toBusinessDate, $timezone)->startOfDay();

        $totalPaidOrders = 0;
        $totalGrossSales = 0;
        $totalSubtotal = 0;
        $totalTax = 0;
        $totalServiceCharge = 0;
        $totalDiscounts = 0;
        $totalRefunds = 0;
        $totalRefundsCount = 0;
        $totalCogsMilli = 0;
        $dailyRoundedCogsSum = 0;

        while ($cursor->lte($endDate)) {
            $dateKey = $cursor->format('Y-m-d');

            $orderAgg = $ordersByDay[$dateKey] ?? null;
            $refundAgg = $refundsByDay[$dateKey] ?? null;
            $dayCogsMilli = (int) ($cogsMilliByDay[$dateKey] ?? 0);

            $dayPaidOrders = (int) ($orderAgg['paid_orders_count'] ?? 0);
            $dayGrossSales = (int) ($orderAgg['gross_sales'] ?? 0);
            $daySubtotal = (int) ($orderAgg['subtotal'] ?? 0);
            $dayTax = (int) ($orderAgg['tax'] ?? 0);
            $dayServiceCharge = (int) ($orderAgg['service_charge'] ?? 0);
            $dayDiscounts = (int) ($orderAgg['discounts'] ?? 0);
            $dayRefunds = (int) ($refundAgg['refunds'] ?? 0);
            $dayRefundsCount = (int) ($refundAgg['refunds_count'] ?? 0);

            $dayNetSales = $this->dashboardMetricsService->netSales($dayGrossSales, $dayRefunds);
            $dayAvgTicket = $this->dashboardMetricsService->averageTicket($dayGrossSales, $dayPaidOrders);
            $dayCogs = self::milliToRupiahHalfUp($dayCogsMilli);
            $dayGrossProfit = $dayNetSales - $dayCogs;
            $dayMargin = self::marginInTenths($dayGrossProfit, $dayNetSales);

            $dailyRows[] = [
                'business_date' => $dateKey,
                'paid_orders_count' => $dayPaidOrders,
                'gross_sales' => $dayGrossSales,
                'subtotal' => $daySubtotal,
                'tax' => $dayTax,
                'service_charge' => $dayServiceCharge,
                'discounts' => $dayDiscounts,
                'refunds' => $dayRefunds,
                'refunds_count' => $dayRefundsCount,
                'net_sales' => $dayNetSales,
                'avg_ticket' => $dayAvgTicket,
                'cogs_milli' => $dayCogsMilli,
                'cogs' => $dayCogs,
                'gross_profit' => $dayGrossProfit,
                'margin' => $dayMargin,
            ];

            $totalPaidOrders += $dayPaidOrders;
            $totalGrossSales += $dayGrossSales;
            $totalSubtotal += $daySubtotal;
            $totalTax += $dayTax;
            $totalServiceCharge += $dayServiceCharge;
            $totalDiscounts += $dayDiscounts;
            $totalRefunds += $dayRefunds;
            $totalRefundsCount += $dayRefundsCount;
            $totalCogsMilli += $dayCogsMilli;
            $dailyRoundedCogsSum += $dayCogs;

            $cursor = $cursor->addDay();
        }

        $totalNetSales = $this->dashboardMetricsService->netSales($totalGrossSales, $totalRefunds);
        $totalAvgTicket = $this->dashboardMetricsService->averageTicket($totalGrossSales, $totalPaidOrders);
        $totalCogs = self::milliToRupiahHalfUp($totalCogsMilli);
        $cogsRoundingDiff = $totalCogs - $dailyRoundedCogsSum;
        $totalGrossProfit = $totalNetSales - $totalCogs;
        $totalMargin = self::marginInTenths($totalGrossProfit, $totalNetSales);

        $uncostedShare = self::shareInTenths($uncostedAmount, $totalGrossSales);

        $showTrackingWarning = ($earliestDeductionDate !== null && $fromBusinessDate < $earliestDeductionDate)
            || ($earliestDeductionDate === null && $totalPaidOrders > 0);

        return [
            'from_date' => $fromBusinessDate,
            'to_date' => $toBusinessDate,
            'days_count' => count($dailyRows),
            'window' => $window,
            'generated_at' => $referenceNow,
            'totals' => [
                'paid_orders_count' => $totalPaidOrders,
                'gross_sales' => $totalGrossSales,
                'subtotal' => $totalSubtotal,
                'tax' => $totalTax,
                'service_charge' => $totalServiceCharge,
                'discounts' => $totalDiscounts,
                'refunds' => $totalRefunds,
                'refunds_count' => $totalRefundsCount,
                'net_sales' => $totalNetSales,
                'avg_ticket' => $totalAvgTicket,
                'cogs_milli' => $totalCogsMilli,
                'cogs' => $totalCogs,
                'daily_rounded_cogs_sum' => $dailyRoundedCogsSum,
                'cogs_rounding_diff' => $cogsRoundingDiff,
                'has_cogs_rounding_note' => $cogsRoundingDiff !== 0,
                'gross_profit' => $totalGrossProfit,
                'margin' => $totalMargin,
            ],
            'payment_mix' => $paymentMix,
            'uncosted_sales' => [
                'amount' => $uncostedAmount,
                'share_tenths' => $uncostedShare['tenths'],
                'share_formatted' => $uncostedShare['formatted'],
            ],
            'cogs_tracking' => [
                'earliest_deduction_date' => $earliestDeductionDate,
                'show_warning' => $showTrackingWarning,
            ],
            'daily_rows' => $dailyRows,
        ];
    }

    /**
     * Convert milli-Rupiah (integer) to whole Rupiah (integer) using half-up integer arithmetic.
     */
    public static function milliToRupiahHalfUp(int $milli): int
    {
        if ($milli >= 0) {
            return intdiv($milli + 500, 1000);
        }

        return -intdiv(abs($milli) + 500, 1000);
    }

    /**
     * Compute gross margin (gross_profit / net_sales) in tenths of a percent using half-up integer arithmetic.
     * Shows '—' when net_sales <= 0.
     *
     * @return array{tenths: ?int, formatted: string}
     */
    public static function marginInTenths(int $grossProfit, int $netSales): array
    {
        if ($netSales <= 0) {
            return [
                'tenths' => null,
                'formatted' => '—',
            ];
        }

        if ($grossProfit === 0) {
            return [
                'tenths' => 0,
                'formatted' => '0.0%',
            ];
        }

        $absProfit = abs($grossProfit);
        $tenths = intdiv((2 * $absProfit * 1000) + $netSales, 2 * $netSales);
        $whole = intdiv($tenths, 10);
        $fraction = $tenths % 10;

        $sign = $grossProfit < 0 ? '-' : '';
        $signedTenths = $grossProfit < 0 ? -$tenths : $tenths;

        return [
            'tenths' => $signedTenths,
            'formatted' => "{$sign}{$whole}.{$fraction}%",
        ];
    }

    /**
     * Compute share (part / whole) in tenths of a percent using half-up integer arithmetic.
     *
     * @return array{tenths: int, formatted: string}
     */
    public static function shareInTenths(int $part, int $whole): array
    {
        if ($whole <= 0 || $part <= 0) {
            return [
                'tenths' => 0,
                'formatted' => '0.0%',
            ];
        }

        $tenths = intdiv((2 * $part * 1000) + $whole, 2 * $whole);
        $intPart = intdiv($tenths, 10);
        $fracPart = $tenths % 10;

        return [
            'tenths' => $tenths,
            'formatted' => "{$intPart}.{$fracPart}%",
        ];
    }

    /**
     * Aggregate paid orders grouped by business date in a single SQL query.
     *
     * @return array<string, array{
     *     paid_orders_count: int,
     *     gross_sales: int,
     *     subtotal: int,
     *     tax: int,
     *     service_charge: int,
     *     discounts: int
     * }>
     */
    private function queryOrdersByDay(CarbonImmutable $start, CarbonImmutable $end, int $cutoffSeconds): array
    {
        $bizDateExpr = $this->businessDateSqlExpression('paid_at', $cutoffSeconds);

        $rows = Order::query()
            ->whereNull('deleted_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->whereIn('payment_status', DashboardMetricsService::SALES_PAYMENT_STATUSES)
            ->selectRaw("
                {$bizDateExpr} as biz_date,
                COUNT(*) as paid_orders_count,
                COALESCE(SUM(total_amount), 0) as gross_sales,
                COALESCE(SUM(subtotal), 0) as subtotal_sales,
                COALESCE(SUM(tax_amount), 0) as tax_total,
                COALESCE(SUM(service_charge_amount), 0) as service_charge_total,
                COALESCE(SUM(discount_amount), 0) as discount_total
            ")
            ->groupByRaw($bizDateExpr)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $dateKey = (string) $row->biz_date;
            $result[$dateKey] = [
                'paid_orders_count' => (int) $row->paid_orders_count,
                'gross_sales' => (int) $row->gross_sales,
                'subtotal' => (int) $row->subtotal_sales,
                'tax' => (int) $row->tax_total,
                'service_charge' => (int) $row->service_charge_total,
                'discounts' => (int) $row->discount_total,
            ];
        }

        return $result;
    }

    /**
     * Aggregate refund_transactions grouped by business date of created_at in a single SQL query.
     *
     * @return array<string, array{refunds: int, refunds_count: int}>
     */
    private function queryRefundsByDay(CarbonImmutable $start, CarbonImmutable $end, int $cutoffSeconds): array
    {
        $bizDateExpr = $this->businessDateSqlExpression('created_at', $cutoffSeconds);

        $rows = RefundTransaction::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw("
                {$bizDateExpr} as biz_date,
                COALESCE(SUM(total_refunded_amount), 0) as refunds_total,
                COUNT(*) as refunds_count
            ")
            ->groupByRaw($bizDateExpr)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $dateKey = (string) $row->biz_date;
            $result[$dateKey] = [
                'refunds' => (int) $row->refunds_total,
                'refunds_count' => (int) $row->refunds_count,
            ];
        }

        return $result;
    }

    /**
     * Aggregate net COGS in milli-Rupiah grouped by business date of stock_movements.created_at in a single SQL query.
     *
     * @return array<string, int>
     */
    private function queryCogsMilliByDay(CarbonImmutable $start, CarbonImmutable $end, int $cutoffSeconds): array
    {
        $bizDateExpr = $this->businessDateSqlExpression('created_at', $cutoffSeconds);
        $qtyMilliExpr = $this->quantityMilliSqlExpression('quantity');

        $rows = StockMovement::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->whereIn('movement_type', [
                StockMovement::TYPE_ORDER_DEDUCTION,
                StockMovement::TYPE_REFUND_CREDIT,
            ])
            ->selectRaw("
                {$bizDateExpr} as biz_date,
                movement_type,
                COALESCE(SUM(({$qtyMilliExpr}) * unit_cost), 0) as cost_milli
            ")
            ->groupByRaw("{$bizDateExpr}, movement_type")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $dateKey = (string) $row->biz_date;
            $costMilli = (int) $row->cost_milli;
            $result[$dateKey] ??= 0;

            if ((string) $row->movement_type === StockMovement::TYPE_ORDER_DEDUCTION) {
                $result[$dateKey] += $costMilli;
            } elseif ((string) $row->movement_type === StockMovement::TYPE_REFUND_CREDIT) {
                $result[$dateKey] -= $costMilli;
            }
        }

        return $result;
    }

    /**
     * Sum order_items.subtotal for orders paid in [start, end) with payment_status IN ('paid', 'partially_refunded')
     * whose product currently has no recipe rows at all (neither base/variant recipe_items nor any modifier_recipe_items
     * on any modifier in a group linked to the product).
     */
    private function queryUncostedSales(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.paid_at', '>=', $start)
            ->where('orders.paid_at', '<', $end)
            ->whereIn('orders.payment_status', DashboardMetricsService::BEST_SELLER_PAYMENT_STATUSES)
            ->where(function ($query): void {
                $query->whereNull('order_items.product_id')
                    ->orWhere(function ($subQuery): void {
                        $subQuery->whereNotExists(function ($recipeSub): void {
                            $recipeSub->selectRaw('1')
                                ->from('recipe_items')
                                ->whereColumn('recipe_items.product_id', 'order_items.product_id');
                        })->whereNotExists(function ($modRecipeSub): void {
                            $modRecipeSub->selectRaw('1')
                                ->from('product_modifier_groups')
                                ->join(
                                    'modifiers',
                                    'modifiers.modifier_group_id',
                                    '=',
                                    'product_modifier_groups.modifier_group_id'
                                )
                                ->join(
                                    'modifier_recipe_items',
                                    'modifier_recipe_items.modifier_id',
                                    '=',
                                    'modifiers.id'
                                )
                                ->whereColumn('product_modifier_groups.product_id', 'order_items.product_id');
                        });
                    });
            })
            ->sum('order_items.subtotal');
    }

    /**
     * Find the business date (Y-m-d) of the earliest order_deduction stock movement in the database, or null if none exists.
     */
    private function queryEarliestDeductionBusinessDate(int $cutoffSeconds, string $timezone): ?string
    {
        $earliestRaw = StockMovement::query()
            ->where('movement_type', StockMovement::TYPE_ORDER_DEDUCTION)
            ->min('created_at');

        if ($earliestRaw === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $earliestRaw, $timezone)
            ->subSeconds($cutoffSeconds)
            ->format('Y-m-d');
    }

    /**
     * Build the database-driver-specific SQL expression that shifts a timestamp column back by $cutoffSeconds
     * and extracts the YYYY-MM-DD business date.
     */
    private function businessDateSqlExpression(string $column, int $cutoffSeconds): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "DATE({$column}, '-{$cutoffSeconds} seconds')";
        }

        return "DATE({$column} - INTERVAL {$cutoffSeconds} SECOND)";
    }

    /**
     * Build the SQL expression that converts a DECIMAL(12,3) quantity column into positive integer milli-units.
     */
    private function quantityMilliSqlExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "CAST((ABS({$column}) * 1000) + 0.5 AS INTEGER)";
        }

        return "CAST(ABS({$column}) * 1000 AS SIGNED)";
    }
}
