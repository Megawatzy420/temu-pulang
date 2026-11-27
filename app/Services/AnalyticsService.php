<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShopSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AnalyticsService
{
    public const TOP_PRODUCTS_LIMIT = 10;

    public const ZERO_SALES_PRODUCTS_LIMIT = 20;

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
     * Build the read-only Sales Analytics report for an inclusive business-date range [from, to]
     * using a small constant number of SQL queries and pure integer arithmetic.
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
     *         avg_ticket: int,
     *         ranked_items_quantity: int,
     *         ranked_items_revenue: int,
     *         ranked_products_count: int
     *     },
     *     top_by_quantity: list<array{
     *         rank: int,
     *         product_id: ?int,
     *         sku: string,
     *         name: string,
     *         product_name_id: string,
     *         product_name_en: ?string,
     *         quantity: int,
     *         revenue: int,
     *         revenue_share_tenths: int,
     *         revenue_share_formatted: string,
     *         bar_pct: int
     *     }>,
     *     top_by_revenue: list<array{
     *         rank: int,
     *         product_id: ?int,
     *         sku: string,
     *         name: string,
     *         product_name_id: string,
     *         product_name_en: ?string,
     *         quantity: int,
     *         revenue: int,
     *         revenue_share_tenths: int,
     *         revenue_share_formatted: string,
     *         bar_pct: int
     *     }>,
     *     products_without_sales: array{
     *         items: list<array{
     *             id: int,
     *             sku: string,
     *             name: string,
     *             base_price: int,
     *             is_sold_out: bool
     *         }>,
     *         total_count: int,
     *         shown_count: int,
     *         remaining_count: int
     *     },
     *     sales_by_hour: array{
     *         hours: list<array{
     *             hour: int,
     *             label: string,
     *             orders_count: int,
     *             gross_sales: int,
     *             sales_bar_pct: int,
     *             orders_bar_pct: int,
     *             is_peak: bool
     *         }>,
     *         peak_hour: ?int,
     *         peak_hour_label: ?string,
     *         peak_hour_sales: int,
     *         peak_hour_orders: int,
     *         total_orders: int,
     *         total_gross_sales: int
     *     },
     *     sales_by_weekday: array{
     *         weekdays: list<array{
     *             iso_weekday: int,
     *             label_key: string,
     *             orders_count: int,
     *             gross_sales: int,
     *             share_tenths: int,
     *             share_formatted: string,
     *             sales_bar_pct: int,
     *             orders_bar_pct: int,
     *             is_peak: bool
     *         }>,
     *         peak_iso_weekday: ?int,
     *         peak_label_key: ?string,
     *         total_orders: int,
     *         total_gross_sales: int
     *     },
     *     sales_by_order_type: array{
     *         types: array<string, array{
     *             order_type: string,
     *             label_key: string,
     *             orders_count: int,
     *             gross_sales: int,
     *             sales_share_tenths: int,
     *             sales_share_formatted: string,
     *             orders_share_tenths: int,
     *             orders_share_formatted: string,
     *             sales_bar_pct: int
     *         }>,
     *         total_orders: int,
     *         total_gross_sales: int
     *     }
     * }
     */
    public function buildAnalytics(
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

        $fromCarbon = CarbonImmutable::parse($fromBusinessDate, $timezone)->startOfDay();
        $toCarbon = CarbonImmutable::parse($toBusinessDate, $timezone)->startOfDay();
        $daysCount = (int) $fromCarbon->diffInDays($toCarbon) + 1;

        // 1. Sales by Clock Hour 0..23 (1 SQL query)
        $salesByHour = $this->querySalesByHour($start, $end);

        // 2. Sales by Business Date (used to compute Sales by Business Weekday Mon..Sun in PHP, 1 SQL query)
        $salesByWeekday = $this->querySalesByWeekday($start, $end, $cutoffSeconds, $timezone);

        // 3. Sales by Order Type (dine_in, takeaway, 1 SQL query)
        $salesByOrderType = $this->querySalesByOrderType($start, $end);

        // 4. Product Rankings (Top 10 by quantity & Top 10 by revenue, 2 SQL queries: 1 aggregate + 1 latest snapshot lookup)
        $productRankings = $this->queryProductRankings($start, $end);

        // 5. Active Products Without Sales in the Ranking Set (1 SQL query with translations)
        $productsWithoutSales = $this->queryProductsWithoutSales($start, $end);

        $totalPaidOrders = $salesByOrderType['total_orders'];
        $totalGrossSales = $salesByOrderType['total_gross_sales'];
        $avgTicket = $this->dashboardMetricsService->averageTicket($totalGrossSales, $totalPaidOrders);

        return [
            'from_date' => $fromBusinessDate,
            'to_date' => $toBusinessDate,
            'days_count' => $daysCount,
            'window' => $window,
            'generated_at' => $referenceNow,
            'totals' => [
                'paid_orders_count' => $totalPaidOrders,
                'gross_sales' => $totalGrossSales,
                'avg_ticket' => $avgTicket,
                'ranked_items_quantity' => $productRankings['total_quantity'],
                'ranked_items_revenue' => $productRankings['total_revenue'],
                'ranked_products_count' => $productRankings['total_products_count'],
            ],
            'top_by_quantity' => $productRankings['top_by_quantity'],
            'top_by_revenue' => $productRankings['top_by_revenue'],
            'products_without_sales' => $productsWithoutSales,
            'sales_by_hour' => $salesByHour,
            'sales_by_weekday' => $salesByWeekday,
            'sales_by_order_type' => $salesByOrderType,
        ];
    }

    /**
     * Compute share (part / whole) in tenths of a percent using half-up integer arithmetic.
     *
     * @return array{tenths: int, formatted: string}
     */
    public static function shareInTenths(int $part, int $whole): array
    {
        return SalesReportService::shareInTenths($part, $whole);
    }

    /**
     * Compute integer bar percentage (0..100) scaled against the maximum value using integer arithmetic.
     */
    public static function barPercentage(int $value, int $max): int
    {
        if ($max <= 0 || $value <= 0) {
            return 0;
        }

        return min(100, intdiv($value * 100, $max));
    }

    /**
     * Aggregate gross-sales orders by real clock hour (0..23) of paid_at in the shop timezone.
     *
     * @return array{
     *     hours: list<array{
     *         hour: int,
     *         label: string,
     *         orders_count: int,
     *         gross_sales: int,
     *         sales_bar_pct: int,
     *         orders_bar_pct: int,
     *         is_peak: bool
     *     }>,
     *     peak_hour: ?int,
     *     peak_hour_label: ?string,
     *     peak_hour_sales: int,
     *     peak_hour_orders: int,
     *     total_orders: int,
     *     total_gross_sales: int
     * }
     */
    private function querySalesByHour(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $hourExpr = $this->clockHourSqlExpression('paid_at');

        $rows = Order::query()
            ->whereNull('deleted_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->whereIn('payment_status', DashboardMetricsService::SALES_PAYMENT_STATUSES)
            ->selectRaw("
                {$hourExpr} as clock_hour,
                COUNT(*) as orders_count,
                COALESCE(SUM(total_amount), 0) as gross_sales
            ")
            ->groupByRaw($hourExpr)
            ->get();

        $byHour = [];
        for ($h = 0; $h <= 23; $h++) {
            $byHour[$h] = [
                'orders_count' => 0,
                'gross_sales' => 0,
            ];
        }

        $totalOrders = 0;
        $totalGrossSales = 0;

        foreach ($rows as $row) {
            $h = (int) $row->clock_hour;
            if ($h >= 0 && $h <= 23) {
                $count = (int) $row->orders_count;
                $sales = (int) $row->gross_sales;
                $byHour[$h]['orders_count'] = $count;
                $byHour[$h]['gross_sales'] = $sales;
                $totalOrders += $count;
                $totalGrossSales += $sales;
            }
        }

        $maxSales = 0;
        $maxOrders = 0;
        $peakHour = null;
        $peakSales = -1;
        $peakOrders = -1;

        for ($h = 0; $h <= 23; $h++) {
            $sales = $byHour[$h]['gross_sales'];
            $count = $byHour[$h]['orders_count'];

            if ($sales > $maxSales) {
                $maxSales = $sales;
            }
            if ($count > $maxOrders) {
                $maxOrders = $count;
            }

            if ($count > 0 && ($sales > $peakSales || ($sales === $peakSales && $count > $peakOrders))) {
                $peakHour = $h;
                $peakSales = $sales;
                $peakOrders = $count;
            }
        }

        $hours = [];
        for ($h = 0; $h <= 23; $h++) {
            $sales = $byHour[$h]['gross_sales'];
            $count = $byHour[$h]['orders_count'];
            $hours[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'orders_count' => $count,
                'gross_sales' => $sales,
                'sales_bar_pct' => self::barPercentage($sales, $maxSales),
                'orders_bar_pct' => self::barPercentage($count, $maxOrders),
                'is_peak' => $peakHour !== null && $peakHour === $h,
            ];
        }

        return [
            'hours' => $hours,
            'peak_hour' => $peakHour,
            'peak_hour_label' => $peakHour !== null ? sprintf('%02d:00–%02d:59', $peakHour, $peakHour) : null,
            'peak_hour_sales' => $peakHour !== null ? $peakSales : 0,
            'peak_hour_orders' => $peakHour !== null ? $peakOrders : 0,
            'total_orders' => $totalOrders,
            'total_gross_sales' => $totalGrossSales,
        ];
    }

    /**
     * Aggregate gross-sales orders by the business date's ISO weekday (1 = Monday .. 7 = Sunday).
     *
     * @return array{
     *     weekdays: list<array{
     *         iso_weekday: int,
     *         label_key: string,
     *         orders_count: int,
     *         gross_sales: int,
     *         share_tenths: int,
     *         share_formatted: string,
     *         sales_bar_pct: int,
     *         orders_bar_pct: int,
     *         is_peak: bool
     *     }>,
     *     peak_iso_weekday: ?int,
     *     peak_label_key: ?string,
     *     total_orders: int,
     *     total_gross_sales: int
     * }
     */
    private function querySalesByWeekday(
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $cutoffSeconds,
        string $timezone,
    ): array {
        $bizDateExpr = $this->businessDateSqlExpression('paid_at', $cutoffSeconds);

        $rows = Order::query()
            ->whereNull('deleted_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->whereIn('payment_status', DashboardMetricsService::SALES_PAYMENT_STATUSES)
            ->selectRaw("
                {$bizDateExpr} as biz_date,
                COUNT(*) as orders_count,
                COALESCE(SUM(total_amount), 0) as gross_sales
            ")
            ->groupByRaw($bizDateExpr)
            ->get();

        $byWeekday = [];
        for ($iso = 1; $iso <= 7; $iso++) {
            $byWeekday[$iso] = [
                'orders_count' => 0,
                'gross_sales' => 0,
            ];
        }

        $totalOrders = 0;
        $totalGrossSales = 0;

        foreach ($rows as $row) {
            $bizDate = (string) $row->biz_date;
            $isoWeekday = CarbonImmutable::parse($bizDate, $timezone)->dayOfWeekIso;
            $count = (int) $row->orders_count;
            $sales = (int) $row->gross_sales;

            $byWeekday[$isoWeekday]['orders_count'] += $count;
            $byWeekday[$isoWeekday]['gross_sales'] += $sales;
            $totalOrders += $count;
            $totalGrossSales += $sales;
        }

        $maxSales = 0;
        $maxOrders = 0;
        $peakIso = null;
        $peakSales = -1;
        $peakOrders = -1;

        for ($iso = 1; $iso <= 7; $iso++) {
            $sales = $byWeekday[$iso]['gross_sales'];
            $count = $byWeekday[$iso]['orders_count'];

            if ($sales > $maxSales) {
                $maxSales = $sales;
            }
            if ($count > $maxOrders) {
                $maxOrders = $count;
            }

            if ($count > 0 && ($sales > $peakSales || ($sales === $peakSales && $count > $peakOrders))) {
                $peakIso = $iso;
                $peakSales = $sales;
                $peakOrders = $count;
            }
        }

        $weekdays = [];
        for ($iso = 1; $iso <= 7; $iso++) {
            $sales = $byWeekday[$iso]['gross_sales'];
            $count = $byWeekday[$iso]['orders_count'];
            $share = self::shareInTenths($sales, $totalGrossSales);

            $weekdays[] = [
                'iso_weekday' => $iso,
                'label_key' => "messages.analytics.weekday_{$iso}",
                'orders_count' => $count,
                'gross_sales' => $sales,
                'share_tenths' => $share['tenths'],
                'share_formatted' => $share['formatted'],
                'sales_bar_pct' => self::barPercentage($sales, $maxSales),
                'orders_bar_pct' => self::barPercentage($count, $maxOrders),
                'is_peak' => $peakIso !== null && $peakIso === $iso,
            ];
        }

        return [
            'weekdays' => $weekdays,
            'peak_iso_weekday' => $peakIso,
            'peak_label_key' => $peakIso !== null ? "messages.analytics.weekday_{$peakIso}" : null,
            'total_orders' => $totalOrders,
            'total_gross_sales' => $totalGrossSales,
        ];
    }

    /**
     * Aggregate gross-sales orders by order_type (dine_in and takeaway).
     *
     * @return array{
     *     types: array<string, array{
     *         order_type: string,
     *         label_key: string,
     *         orders_count: int,
     *         gross_sales: int,
     *         sales_share_tenths: int,
     *         sales_share_formatted: string,
     *         orders_share_tenths: int,
     *         orders_share_formatted: string,
     *         sales_bar_pct: int
     *     }>,
     *     total_orders: int,
     *     total_gross_sales: int
     * }
     */
    private function querySalesByOrderType(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Order::query()
            ->whereNull('deleted_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->whereIn('payment_status', DashboardMetricsService::SALES_PAYMENT_STATUSES)
            ->selectRaw('
                order_type,
                COUNT(*) as orders_count,
                COALESCE(SUM(total_amount), 0) as gross_sales
            ')
            ->groupBy('order_type')
            ->get();

        $buckets = [
            Order::TYPE_DINE_IN => [
                'orders_count' => 0,
                'gross_sales' => 0,
                'label_key' => 'messages.analytics.order_type_dine_in',
            ],
            Order::TYPE_TAKEAWAY => [
                'orders_count' => 0,
                'gross_sales' => 0,
                'label_key' => 'messages.analytics.order_type_takeaway',
            ],
        ];

        $totalOrders = 0;
        $totalGrossSales = 0;

        foreach ($rows as $row) {
            $type = (string) $row->order_type;
            $count = (int) $row->orders_count;
            $sales = (int) $row->gross_sales;

            if (! isset($buckets[$type])) {
                $buckets[$type] = [
                    'orders_count' => 0,
                    'gross_sales' => 0,
                    'label_key' => $type === Order::TYPE_TAKEAWAY
                        ? 'messages.analytics.order_type_takeaway'
                        : 'messages.analytics.order_type_dine_in',
                ];
            }

            $buckets[$type]['orders_count'] += $count;
            $buckets[$type]['gross_sales'] += $sales;
            $totalOrders += $count;
            $totalGrossSales += $sales;
        }

        $maxSales = max(
            $buckets[Order::TYPE_DINE_IN]['gross_sales'],
            $buckets[Order::TYPE_TAKEAWAY]['gross_sales'],
        );

        $types = [];
        foreach ($buckets as $type => $data) {
            $salesShare = self::shareInTenths($data['gross_sales'], $totalGrossSales);
            $ordersShare = self::shareInTenths($data['orders_count'], $totalOrders);

            $types[$type] = [
                'order_type' => $type,
                'label_key' => $data['label_key'],
                'orders_count' => $data['orders_count'],
                'gross_sales' => $data['gross_sales'],
                'sales_share_tenths' => $salesShare['tenths'],
                'sales_share_formatted' => $salesShare['formatted'],
                'orders_share_tenths' => $ordersShare['tenths'],
                'orders_share_formatted' => $ordersShare['formatted'],
                'sales_bar_pct' => self::barPercentage($data['gross_sales'], $maxSales),
            ];
        }

        return [
            'types' => $types,
            'total_orders' => $totalOrders,
            'total_gross_sales' => $totalGrossSales,
        ];
    }

    /**
     * Rank products from orders paid in [start, end) with payment_status IN ('paid', 'partially_refunded').
     * Groups by product_id (falling back to snapshot name and sku when product_id is null), displays the most
     * recent snapshot name, and builds top 10 by quantity and top 10 by revenue.
     *
     * @return array{
     *     total_quantity: int,
     *     total_revenue: int,
     *     total_products_count: int,
     *     top_by_quantity: list<array{
     *         rank: int,
     *         product_id: ?int,
     *         sku: string,
     *         name: string,
     *         product_name_id: string,
     *         product_name_en: ?string,
     *         quantity: int,
     *         revenue: int,
     *         revenue_share_tenths: int,
     *         revenue_share_formatted: string,
     *         bar_pct: int
     *     }>,
     *     top_by_revenue: list<array{
     *         rank: int,
     *         product_id: ?int,
     *         sku: string,
     *         name: string,
     *         product_name_id: string,
     *         product_name_en: ?string,
     *         quantity: int,
     *         revenue: int,
     *         revenue_share_tenths: int,
     *         revenue_share_formatted: string,
     *         bar_pct: int
     *     }>
     * }
     */
    private function queryProductRankings(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.paid_at', '>=', $start)
            ->where('orders.paid_at', '<', $end)
            ->whereIn('orders.payment_status', DashboardMetricsService::BEST_SELLER_PAYMENT_STATUSES)
            ->selectRaw("
                order_items.product_id,
                CASE WHEN order_items.product_id IS NULL THEN order_items.product_name_id ELSE '' END as fallback_name_id,
                CASE WHEN order_items.product_id IS NULL THEN COALESCE(order_items.product_name_en, '') ELSE '' END as fallback_name_en,
                CASE WHEN order_items.product_id IS NULL THEN COALESCE(order_items.sku, '') ELSE '' END as fallback_sku,
                COALESCE(SUM(order_items.quantity), 0) as total_qty,
                COALESCE(SUM(order_items.subtotal), 0) as total_revenue,
                MAX(order_items.id) as latest_order_item_id
            ")
            ->groupByRaw("
                order_items.product_id,
                CASE WHEN order_items.product_id IS NULL THEN order_items.product_name_id ELSE '' END,
                CASE WHEN order_items.product_id IS NULL THEN COALESCE(order_items.product_name_en, '') ELSE '' END,
                CASE WHEN order_items.product_id IS NULL THEN COALESCE(order_items.sku, '') ELSE '' END
            ")
            ->get();

        if ($rows->isEmpty()) {
            return [
                'total_quantity' => 0,
                'total_revenue' => 0,
                'total_products_count' => 0,
                'top_by_quantity' => [],
                'top_by_revenue' => [],
            ];
        }

        $latestItemIds = $rows->pluck('latest_order_item_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $latestSnapshots = OrderItem::query()
            ->whereIn('id', $latestItemIds)
            ->get(['id', 'sku', 'product_name_id', 'product_name_en'])
            ->keyBy('id');

        $locale = app()->getLocale();
        $totalQuantity = 0;
        $totalRevenue = 0;
        $items = [];

        foreach ($rows as $row) {
            $qty = (int) $row->total_qty;
            $rev = (int) $row->total_revenue;
            $latestId = (int) $row->latest_order_item_id;
            $snapshot = $latestSnapshots->get($latestId);

            $nameId = $snapshot !== null
                ? (string) $snapshot->product_name_id
                : (string) $row->fallback_name_id;
            $rawNameEn = $snapshot !== null
                ? $snapshot->product_name_en
                : $row->fallback_name_en;
            $nameEn = ($rawNameEn !== null && (string) $rawNameEn !== '')
                ? (string) $rawNameEn
                : null;
            $sku = $snapshot !== null
                ? (string) ($snapshot->sku ?? '')
                : (string) $row->fallback_sku;

            $displayName = ($locale === 'en' && $nameEn !== null) ? $nameEn : $nameId;

            $totalQuantity += $qty;
            $totalRevenue += $rev;

            $items[] = [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'sku' => $sku,
                'name' => $displayName,
                'product_name_id' => $nameId,
                'product_name_en' => $nameEn,
                'quantity' => $qty,
                'revenue' => $rev,
            ];
        }

        // Attach share of ranked revenue
        foreach ($items as &$item) {
            $share = self::shareInTenths($item['revenue'], $totalRevenue);
            $item['revenue_share_tenths'] = $share['tenths'];
            $item['revenue_share_formatted'] = $share['formatted'];
        }
        unset($item);

        // 1. Sort for Top 10 by Quantity: quantity DESC, revenue DESC, name ASC
        $byQty = $items;
        usort($byQty, static function (array $a, array $b): int {
            if ($a['quantity'] !== $b['quantity']) {
                return $b['quantity'] <=> $a['quantity'];
            }
            if ($a['revenue'] !== $b['revenue']) {
                return $b['revenue'] <=> $a['revenue'];
            }

            return strcasecmp($a['name'], $b['name']);
        });
        $topQtySlice = array_slice($byQty, 0, self::TOP_PRODUCTS_LIMIT);
        $maxQty = $topQtySlice !== [] ? $topQtySlice[0]['quantity'] : 0;

        $topByQuantity = [];
        foreach ($topQtySlice as $idx => $entry) {
            $entry['rank'] = $idx + 1;
            $entry['bar_pct'] = self::barPercentage($entry['quantity'], $maxQty);
            $topByQuantity[] = $entry;
        }

        // 2. Sort for Top 10 by Revenue: revenue DESC, quantity DESC, name ASC
        $byRev = $items;
        usort($byRev, static function (array $a, array $b): int {
            if ($a['revenue'] !== $b['revenue']) {
                return $b['revenue'] <=> $a['revenue'];
            }
            if ($a['quantity'] !== $b['quantity']) {
                return $b['quantity'] <=> $a['quantity'];
            }

            return strcasecmp($a['name'], $b['name']);
        });
        $topRevSlice = array_slice($byRev, 0, self::TOP_PRODUCTS_LIMIT);
        $maxRev = $topRevSlice !== [] ? $topRevSlice[0]['revenue'] : 0;

        $topByRevenue = [];
        foreach ($topRevSlice as $idx => $entry) {
            $entry['rank'] = $idx + 1;
            $entry['bar_pct'] = self::barPercentage($entry['revenue'], $maxRev);
            $topByRevenue[] = $entry;
        }

        return [
            'total_quantity' => $totalQuantity,
            'total_revenue' => $totalRevenue,
            'total_products_count' => count($items),
            'top_by_quantity' => $topByQuantity,
            'top_by_revenue' => $topByRevenue,
        ];
    }

    /**
     * Find active, non-deleted products with zero quantity sold in the ranking set within [start, end),
     * sorted alphabetically by localized name, limited to 20 with an integer "and N more" count.
     *
     * @return array{
     *     items: list<array{
     *         id: int,
     *         sku: string,
     *         name: string,
     *         base_price: int,
     *         is_sold_out: bool
     *     }>,
     *     total_count: int,
     *     shown_count: int,
     *     remaining_count: int
     * }
     */
    private function queryProductsWithoutSales(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $products = Product::query()
            ->active()
            ->with('translations')
            ->whereNotExists(function ($sub) use ($start, $end): void {
                $sub->selectRaw('1')
                    ->from('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereColumn('order_items.product_id', 'products.id')
                    ->whereNull('orders.deleted_at')
                    ->where('orders.paid_at', '>=', $start)
                    ->where('orders.paid_at', '<', $end)
                    ->whereIn('orders.payment_status', DashboardMetricsService::BEST_SELLER_PAYMENT_STATUSES)
                    ->where('order_items.quantity', '>', 0);
            })
            ->get(['id', 'sku', 'base_price', 'is_sold_out']);

        $mapped = [];
        foreach ($products as $product) {
            $mapped[] = [
                'id' => (int) $product->id,
                'sku' => (string) ($product->sku ?? ''),
                'name' => (string) ($product->name ?? $product->sku ?? '#'.$product->id),
                'base_price' => (int) $product->base_price,
                'is_sold_out' => (bool) $product->is_sold_out,
            ];
        }

        usort($mapped, static function (array $a, array $b): int {
            $cmp = strcasecmp($a['name'], $b['name']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['sku'], $b['sku']);
        });

        $totalCount = count($mapped);
        $sliced = array_slice($mapped, 0, self::ZERO_SALES_PRODUCTS_LIMIT);
        $shownCount = count($sliced);
        $remainingCount = max(0, $totalCount - $shownCount);

        return [
            'items' => $sliced,
            'total_count' => $totalCount,
            'shown_count' => $shownCount,
            'remaining_count' => $remainingCount,
        ];
    }

    /**
     * Build the database-driver-specific SQL expression that extracts the clock hour (0..23)
     * from a timestamp column stored in the shop/app timezone.
     */
    private function clockHourSqlExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "CAST(STRFTIME('%H', {$column}) AS INTEGER)";
        }

        return "HOUR({$column})";
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
}
