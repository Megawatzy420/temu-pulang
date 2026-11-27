<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Reports\SalesReport;
use App\Models\ActivityLog;
use App\Models\Shift;
use App\Services\AnalyticsService;
use App\Services\Export\CsvWriter;
use App\Services\Export\XlsxWriter;
use App\Services\SalesReportService;
use App\Services\ShiftReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ReportExportController extends Controller
{
    public const MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const MIME_CSV = 'text/csv; charset=utf-8';

    public function __construct(
        private readonly XlsxWriter $xlsxWriter,
        private readonly CsvWriter $csvWriter,
    ) {}

    /**
     * Export the Sales, COGS & Gross-Profit Report to XLSX or CSV (Owner only).
     */
    public function exportSales(Request $request, SalesReportService $salesReportService): Response|RedirectResponse
    {
        $this->authorizeOwner();

        $format = strtolower(trim((string) $request->query('format', '')));
        if (! in_array($format, ['xlsx', 'csv'], true)) {
            return redirect()
                ->route('reports.sales')
                ->with('warning', __('messages.export.invalid_format'));
        }

        $todayBusinessDate = $salesReportService->currentBusinessDate();
        $rawFrom = (string) $request->query('from', $todayBusinessDate);
        $rawTo = (string) $request->query('to', $todayBusinessDate);

        $validatedDates = $this->validateDateRange($rawFrom, $rawTo);
        if ($validatedDates === null) {
            return redirect()
                ->route('reports.sales')
                ->with('warning', __('messages.sales_report.validation_reset_notice'));
        }

        [$from, $to] = $validatedDates;
        $report = $salesReportService->buildReport($from, $to);
        $sheets = $this->buildSalesReportSheets($report);

        $rowCount = $this->countTotalDataRows($sheets);
        ActivityLog::record(
            action: 'report.exported',
            description: "Exported sales report ({$from} to {$to}) as ".strtoupper($format).'.',
            properties: [
                'report' => 'sales',
                'from' => $from,
                'to' => $to,
                'format' => $format,
                'row_count' => $rowCount,
            ],
            module: 'reports',
        );

        $filenameBase = "temu_pulang_sales_{$from}_to_{$to}";

        return $this->buildDownloadResponse($sheets, $format, $filenameBase);
    }

    /**
     * Export the Sales Analytics Report to XLSX or CSV (Owner only).
     */
    public function exportAnalytics(Request $request, AnalyticsService $analyticsService): Response|RedirectResponse
    {
        $this->authorizeOwner();

        $format = strtolower(trim((string) $request->query('format', '')));
        if (! in_array($format, ['xlsx', 'csv'], true)) {
            return redirect()
                ->route('reports.analytics')
                ->with('warning', __('messages.export.invalid_format'));
        }

        $todayBusinessDate = $analyticsService->currentBusinessDate();
        $rawFrom = (string) $request->query('from', $todayBusinessDate);
        $rawTo = (string) $request->query('to', $todayBusinessDate);

        $validatedDates = $this->validateDateRange($rawFrom, $rawTo);
        if ($validatedDates === null) {
            return redirect()
                ->route('reports.analytics')
                ->with('warning', __('messages.analytics.validation_reset_notice'));
        }

        [$from, $to] = $validatedDates;
        $analytics = $analyticsService->buildAnalytics($from, $to);
        $sheets = $this->buildAnalyticsSheets($analytics);

        $rowCount = $this->countTotalDataRows($sheets);
        ActivityLog::record(
            action: 'report.exported',
            description: "Exported sales analytics ({$from} to {$to}) as ".strtoupper($format).'.',
            properties: [
                'report' => 'analytics',
                'from' => $from,
                'to' => $to,
                'format' => $format,
                'row_count' => $rowCount,
            ],
            module: 'reports',
        );

        $filenameBase = "temu_pulang_analytics_{$from}_to_{$to}";

        return $this->buildDownloadResponse($sheets, $format, $filenameBase);
    }

    /**
     * Export the Shift X-Report or Z-Report to XLSX or CSV (Owner any shift; Cashier only own shift).
     */
    public function exportShift(Request $request, Shift $shift, ShiftReportService $shiftReportService): Response|RedirectResponse
    {
        $this->authorizeShift($shift);

        $format = strtolower(trim((string) $request->query('format', '')));
        if (! in_array($format, ['xlsx', 'csv'], true)) {
            return redirect()
                ->route('reports.shift', $shift)
                ->with('warning', __('messages.export.invalid_format'));
        }

        $report = $shiftReportService->buildReport($shift);
        $sheets = $this->buildShiftReportSheets($report);

        $shiftNumber = (string) $report['header']['shift_number'];
        $safeShiftSlug = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $shiftNumber);
        $rowCount = $this->countTotalDataRows($sheets);

        ActivityLog::record(
            action: 'report.exported',
            description: "Exported shift report ({$shiftNumber}) as ".strtoupper($format).'.',
            properties: [
                'report' => 'shift',
                'shift_number' => $shiftNumber,
                'format' => $format,
                'row_count' => $rowCount,
            ],
            module: 'reports',
        );

        $filenameBase = "temu_pulang_shift_{$safeShiftSlug}";

        return $this->buildDownloadResponse($sheets, $format, $filenameBase);
    }

    /**
     * Build the 3 worksheet definitions for the Sales, COGS & Gross-Profit Report.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{name: string, headers: list<string>, rows: list<list<array{type: 's'|'n', value: string}>>}>
     */
    private function buildSalesReportSheets(array $report): array
    {
        $locale = app()->getLocale();
        $tz = (string) $report['window']['timezone'];
        $startTime = (string) $report['window']['start_time'];
        $generatedFormatted = $report['generated_at']
            ->copy()
            ->setTimezone($tz)
            ->locale($locale)
            ->translatedFormat('d M Y, H:i:s');

        $totals = $report['totals'];
        $uncosted = $report['uncosted_sales'];
        $tracking = $report['cogs_tracking'];
        $mix = $report['payment_mix'];

        $marginCell = $totals['margin']['tenths'] !== null
            ? XlsxWriter::numericCell(XlsxWriter::tenthsToDecimalString((int) $totals['margin']['tenths']))
            : XlsxWriter::stringCell('—');

        $trackingNote = __('messages.export.cogs_tracking_ok');
        if ($tracking['show_warning']) {
            if ($tracking['earliest_deduction_date'] !== null) {
                $earliestFormatted = CarbonImmutable::parse((string) $tracking['earliest_deduction_date'], $tz)
                    ->locale($locale)
                    ->translatedFormat('d M Y');
                $trackingNote = __('messages.sales_report.cogs_tracking_warning_with_date', ['date' => $earliestFormatted]);
            } else {
                $trackingNote = __('messages.sales_report.cogs_tracking_warning_no_deductions');
            }
        }

        $summaryRows = [
            [XlsxWriter::stringCell(__('messages.sales_report.filter_from_label')), XlsxWriter::stringCell((string) $report['from_date'])],
            [XlsxWriter::stringCell(__('messages.sales_report.filter_to_label')), XlsxWriter::stringCell((string) $report['to_date'])],
            [XlsxWriter::stringCell(__('messages.export.days_in_period')), XlsxWriter::numericCell((int) $report['days_count'])],
            [XlsxWriter::stringCell(__('messages.export.business_day_cutoff')), XlsxWriter::stringCell("{$startTime} {$tz}")],
            [XlsxWriter::stringCell(__('messages.export.generated_at')), XlsxWriter::stringCell($generatedFormatted)],
            [XlsxWriter::stringCell(__('messages.sales_report.card_paid_orders')), XlsxWriter::numericCell((int) $totals['paid_orders_count'])],
            [XlsxWriter::stringCell(__('messages.sales_report.card_gross_sales')), XlsxWriter::numericCell((int) $totals['gross_sales'])],
            [XlsxWriter::stringCell(__('messages.export.col_subtotal')), XlsxWriter::numericCell((int) $totals['subtotal'])],
            [XlsxWriter::stringCell(__('messages.sales_report.col_tax')), XlsxWriter::numericCell((int) $totals['tax'])],
            [XlsxWriter::stringCell(__('messages.sales_report.col_service_charge')), XlsxWriter::numericCell((int) $totals['service_charge'])],
            [XlsxWriter::stringCell(__('messages.export.col_discounts')), XlsxWriter::numericCell((int) $totals['discounts'])],
            [XlsxWriter::stringCell(__('messages.export.refunds_count')), XlsxWriter::numericCell((int) $totals['refunds_count'])],
            [XlsxWriter::stringCell(__('messages.sales_report.card_refunds')), XlsxWriter::numericCell((int) $totals['refunds'])],
            [XlsxWriter::stringCell(__('messages.sales_report.card_net_sales')), XlsxWriter::numericCell((int) $totals['net_sales'])],
            [XlsxWriter::stringCell(__('messages.sales_report.card_avg_ticket')), XlsxWriter::numericCell((int) $totals['avg_ticket'])],
            [XlsxWriter::stringCell(__('messages.sales_report.card_cogs')), XlsxWriter::numericCell((int) $totals['cogs'])],
            [XlsxWriter::stringCell(__('messages.sales_report.card_gross_profit')), XlsxWriter::numericCell((int) $totals['gross_profit'])],
            [XlsxWriter::stringCell(__('messages.export.col_margin_pct')), $marginCell],
            [XlsxWriter::stringCell(__('messages.sales_report.uncosted_amount_label')), XlsxWriter::numericCell((int) $uncosted['amount'])],
            [
                XlsxWriter::stringCell(__('messages.export.uncosted_share_pct')),
                XlsxWriter::numericCell(XlsxWriter::tenthsToDecimalString((int) $uncosted['share_tenths'])),
            ],
            [XlsxWriter::stringCell(__('messages.sales_report.uncosted_heading')), XlsxWriter::stringCell(__('messages.sales_report.uncosted_explanation'))],
            [XlsxWriter::stringCell(__('messages.export.cogs_tracking_status')), XlsxWriter::stringCell($trackingNote)],
        ];

        if ($totals['has_cogs_rounding_note']) {
            $roundingText = __('messages.sales_report.cogs_rounding_note', [
                'total_cogs' => number_format((int) $totals['cogs'], 0, ',', '.'),
                'diff' => number_format(abs((int) $totals['cogs_rounding_diff']), 0, ',', '.'),
                'daily_sum' => number_format((int) $totals['daily_rounded_cogs_sum'], 0, ',', '.'),
            ]);
            $summaryRows[] = [
                XlsxWriter::stringCell(__('messages.export.cogs_rounding_note_label')),
                XlsxWriter::stringCell($roundingText),
            ];
        }

        // Sheet 2: Per Day
        $perDayHeaders = [
            __('messages.sales_report.col_date'),
            __('messages.sales_report.col_orders'),
            __('messages.sales_report.col_gross_sales'),
            __('messages.export.col_subtotal'),
            __('messages.sales_report.col_tax'),
            __('messages.sales_report.col_service_charge'),
            __('messages.export.col_discounts'),
            __('messages.export.refunds_count'),
            __('messages.sales_report.col_refunds'),
            __('messages.sales_report.col_net_sales'),
            __('messages.sales_report.card_avg_ticket'),
            __('messages.sales_report.col_cogs'),
            __('messages.sales_report.col_gross_profit'),
            __('messages.export.col_margin_pct'),
        ];

        $perDayRows = [];
        foreach ($report['daily_rows'] as $day) {
            $dayMarginCell = $day['margin']['tenths'] !== null
                ? XlsxWriter::numericCell(XlsxWriter::tenthsToDecimalString((int) $day['margin']['tenths']))
                : XlsxWriter::stringCell('—');

            $perDayRows[] = [
                XlsxWriter::stringCell((string) $day['business_date']),
                XlsxWriter::numericCell((int) $day['paid_orders_count']),
                XlsxWriter::numericCell((int) $day['gross_sales']),
                XlsxWriter::numericCell((int) $day['subtotal']),
                XlsxWriter::numericCell((int) $day['tax']),
                XlsxWriter::numericCell((int) $day['service_charge']),
                XlsxWriter::numericCell((int) $day['discounts']),
                XlsxWriter::numericCell((int) $day['refunds_count']),
                XlsxWriter::numericCell((int) $day['refunds']),
                XlsxWriter::numericCell((int) $day['net_sales']),
                XlsxWriter::numericCell((int) $day['avg_ticket']),
                XlsxWriter::numericCell((int) $day['cogs']),
                XlsxWriter::numericCell((int) $day['gross_profit']),
                $dayMarginCell,
            ];
        }

        // Totals row at bottom of Per Day
        $perDayRows[] = [
            XlsxWriter::stringCell(__('messages.sales_report.totals_row_label', ['days' => (int) $report['days_count']])),
            XlsxWriter::numericCell((int) $totals['paid_orders_count']),
            XlsxWriter::numericCell((int) $totals['gross_sales']),
            XlsxWriter::numericCell((int) $totals['subtotal']),
            XlsxWriter::numericCell((int) $totals['tax']),
            XlsxWriter::numericCell((int) $totals['service_charge']),
            XlsxWriter::numericCell((int) $totals['discounts']),
            XlsxWriter::numericCell((int) $totals['refunds_count']),
            XlsxWriter::numericCell((int) $totals['refunds']),
            XlsxWriter::numericCell((int) $totals['net_sales']),
            XlsxWriter::numericCell((int) $totals['avg_ticket']),
            XlsxWriter::numericCell((int) $totals['cogs']),
            XlsxWriter::numericCell((int) $totals['gross_profit']),
            $marginCell,
        ];

        // Sheet 3: Payment Mix
        $paymentMixRows = [
            [XlsxWriter::stringCell(__('messages.sales_report.payment_cash')), XlsxWriter::numericCell((int) $mix['cash'])],
            [XlsxWriter::stringCell(__('messages.sales_report.payment_qris')), XlsxWriter::numericCell((int) $mix['qris'])],
            [XlsxWriter::stringCell(__('messages.sales_report.payment_debit')), XlsxWriter::numericCell((int) $mix['debit'])],
            [XlsxWriter::stringCell(__('messages.sales_report.payment_other')), XlsxWriter::numericCell((int) $mix['other'])],
            [XlsxWriter::stringCell(__('messages.sales_report.payment_total')), XlsxWriter::numericCell((int) $mix['total'])],
        ];

        return [
            [
                'name' => __('messages.export.sheet_summary'),
                'headers' => [__('messages.export.col_metric'), __('messages.export.col_value')],
                'rows' => $summaryRows,
            ],
            [
                'name' => __('messages.export.sheet_per_day'),
                'headers' => $perDayHeaders,
                'rows' => $perDayRows,
            ],
            [
                'name' => __('messages.export.sheet_payment_mix'),
                'headers' => [__('messages.export.col_payment_method'), __('messages.export.col_amount_idr')],
                'rows' => $paymentMixRows,
            ],
        ];
    }

    /**
     * Build the 6 worksheet definitions for the Sales Analytics Report.
     *
     * @param  array<string, mixed>  $analytics
     * @return list<array{name: string, headers: list<string>, rows: list<list<array{type: 's'|'n', value: string}>>}>
     */
    private function buildAnalyticsSheets(array $analytics): array
    {
        // Sheet 1: Top Products by Quantity
        $topQtyHeaders = [
            __('messages.analytics.col_rank'),
            __('messages.export.col_sku'),
            __('messages.analytics.col_product'),
            __('messages.analytics.col_qty_sold'),
            __('messages.analytics.col_item_revenue'),
            __('messages.export.col_revenue_share_pct'),
        ];
        $topQtyRows = [];
        foreach ($analytics['top_by_quantity'] as $item) {
            $topQtyRows[] = [
                XlsxWriter::numericCell((int) $item['rank']),
                XlsxWriter::stringCell((string) $item['sku']),
                XlsxWriter::stringCell((string) $item['name']),
                XlsxWriter::numericCell((int) $item['quantity']),
                XlsxWriter::numericCell((int) $item['revenue']),
                XlsxWriter::numericCell(XlsxWriter::tenthsToDecimalString((int) $item['revenue_share_tenths'])),
            ];
        }
        $topQtyRows[] = [
            XlsxWriter::stringCell('—'),
            XlsxWriter::stringCell('—'),
            XlsxWriter::stringCell(__('messages.analytics.no_per_product_margin_note')),
            XlsxWriter::numericCell((int) $analytics['totals']['ranked_items_quantity']),
            XlsxWriter::numericCell((int) $analytics['totals']['ranked_items_revenue']),
            XlsxWriter::numericCell($analytics['totals']['ranked_items_revenue'] > 0 ? '100' : '0'),
        ];

        // Sheet 2: Top Products by Revenue
        $topRevHeaders = [
            __('messages.analytics.col_rank'),
            __('messages.export.col_sku'),
            __('messages.analytics.col_product'),
            __('messages.analytics.col_item_revenue'),
            __('messages.analytics.col_qty_sold'),
            __('messages.export.col_revenue_share_pct'),
        ];
        $topRevRows = [];
        foreach ($analytics['top_by_revenue'] as $item) {
            $topRevRows[] = [
                XlsxWriter::numericCell((int) $item['rank']),
                XlsxWriter::stringCell((string) $item['sku']),
                XlsxWriter::stringCell((string) $item['name']),
                XlsxWriter::numericCell((int) $item['revenue']),
                XlsxWriter::numericCell((int) $item['quantity']),
                XlsxWriter::numericCell(XlsxWriter::tenthsToDecimalString((int) $item['revenue_share_tenths'])),
            ];
        }

        // Sheet 3: Products Without Sales
        $zeroSalesHeaders = [
            __('messages.export.col_sku'),
            __('messages.analytics.col_product'),
            __('messages.export.col_base_price'),
            __('messages.export.col_stock_status'),
        ];
        $zeroSalesRows = [];
        foreach ($analytics['products_without_sales']['items'] as $product) {
            $statusLabel = $product['is_sold_out']
                ? __('messages.products.status_sold_out')
                : __('messages.products.status_available');
            $zeroSalesRows[] = [
                XlsxWriter::stringCell((string) $product['sku']),
                XlsxWriter::stringCell((string) $product['name']),
                XlsxWriter::numericCell((int) $product['base_price']),
                XlsxWriter::stringCell($statusLabel),
            ];
        }
        if ((int) $analytics['products_without_sales']['remaining_count'] > 0) {
            $zeroSalesRows[] = [
                XlsxWriter::stringCell('—'),
                XlsxWriter::stringCell(__('messages.analytics.zero_sales_and_more', [
                    'count' => (int) $analytics['products_without_sales']['remaining_count'],
                ])),
                XlsxWriter::numericCell((int) $analytics['products_without_sales']['remaining_count']),
                XlsxWriter::stringCell('—'),
            ];
        }

        // Sheet 4: Sales by Hour (all 24 hours)
        $hourlyHeaders = [
            __('messages.export.col_hour'),
            __('messages.export.col_orders_count'),
            __('messages.sales_report.col_gross_sales'),
            __('messages.export.col_peak_indicator'),
        ];
        $hourlyRows = [];
        foreach ($analytics['sales_by_hour']['hours'] as $h) {
            $hourlyRows[] = [
                XlsxWriter::stringCell((string) $h['label']),
                XlsxWriter::numericCell((int) $h['orders_count']),
                XlsxWriter::numericCell((int) $h['gross_sales']),
                XlsxWriter::stringCell($h['is_peak'] ? __('messages.analytics.peak_badge') : ''),
            ];
        }
        $hourlyRows[] = [
            XlsxWriter::stringCell(__('messages.analytics.total_label')),
            XlsxWriter::numericCell((int) $analytics['sales_by_hour']['total_orders']),
            XlsxWriter::numericCell((int) $analytics['sales_by_hour']['total_gross_sales']),
            XlsxWriter::stringCell(''),
        ];

        // Sheet 5: Sales by Weekday (Monday to Sunday)
        $weekdayHeaders = [
            __('messages.export.col_weekday'),
            __('messages.export.col_orders_count'),
            __('messages.sales_report.col_gross_sales'),
            __('messages.export.col_sales_share_pct'),
            __('messages.export.col_peak_indicator'),
        ];
        $weekdayRows = [];
        foreach ($analytics['sales_by_weekday']['weekdays'] as $day) {
            $weekdayRows[] = [
                XlsxWriter::stringCell(__((string) $day['label_key'])),
                XlsxWriter::numericCell((int) $day['orders_count']),
                XlsxWriter::numericCell((int) $day['gross_sales']),
                XlsxWriter::numericCell(XlsxWriter::tenthsToDecimalString((int) $day['share_tenths'])),
                XlsxWriter::stringCell($day['is_peak'] ? __('messages.analytics.peak_badge') : ''),
            ];
        }
        $weekdayRows[] = [
            XlsxWriter::stringCell(__('messages.analytics.total_label')),
            XlsxWriter::numericCell((int) $analytics['sales_by_weekday']['total_orders']),
            XlsxWriter::numericCell((int) $analytics['sales_by_weekday']['total_gross_sales']),
            XlsxWriter::numericCell($analytics['sales_by_weekday']['total_gross_sales'] > 0 ? '100' : '0'),
            XlsxWriter::stringCell(''),
        ];

        // Sheet 6: Sales by Order Type
        $orderTypeHeaders = [
            __('messages.export.col_order_type'),
            __('messages.export.col_orders_count'),
            __('messages.export.col_orders_share_pct'),
            __('messages.sales_report.col_gross_sales'),
            __('messages.export.col_sales_share_pct'),
        ];
        $orderTypeRows = [];
        foreach ($analytics['sales_by_order_type']['types'] as $typeData) {
            $orderTypeRows[] = [
                XlsxWriter::stringCell(__((string) $typeData['label_key'])),
                XlsxWriter::numericCell((int) $typeData['orders_count']),
                XlsxWriter::numericCell(XlsxWriter::tenthsToDecimalString((int) $typeData['orders_share_tenths'])),
                XlsxWriter::numericCell((int) $typeData['gross_sales']),
                XlsxWriter::numericCell(XlsxWriter::tenthsToDecimalString((int) $typeData['sales_share_tenths'])),
            ];
        }
        $orderTypeRows[] = [
            XlsxWriter::stringCell(__('messages.analytics.total_label')),
            XlsxWriter::numericCell((int) $analytics['sales_by_order_type']['total_orders']),
            XlsxWriter::numericCell($analytics['sales_by_order_type']['total_orders'] > 0 ? '100' : '0'),
            XlsxWriter::numericCell((int) $analytics['sales_by_order_type']['total_gross_sales']),
            XlsxWriter::numericCell($analytics['sales_by_order_type']['total_gross_sales'] > 0 ? '100' : '0'),
        ];

        return [
            [
                'name' => __('messages.export.sheet_top_qty'),
                'headers' => $topQtyHeaders,
                'rows' => $topQtyRows,
            ],
            [
                'name' => __('messages.export.sheet_top_revenue'),
                'headers' => $topRevHeaders,
                'rows' => $topRevRows,
            ],
            [
                'name' => __('messages.export.sheet_zero_sales'),
                'headers' => $zeroSalesHeaders,
                'rows' => $zeroSalesRows,
            ],
            [
                'name' => __('messages.export.sheet_hourly'),
                'headers' => $hourlyHeaders,
                'rows' => $hourlyRows,
            ],
            [
                'name' => __('messages.export.sheet_weekday'),
                'headers' => $weekdayHeaders,
                'rows' => $weekdayRows,
            ],
            [
                'name' => __('messages.export.sheet_order_types'),
                'headers' => $orderTypeHeaders,
                'rows' => $orderTypeRows,
            ],
        ];
    }

    /**
     * Build the 3 worksheet definitions for the Shift X-Report / Z-Report.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{name: string, headers: list<string>, rows: list<list<array{type: 's'|'n', value: string}>>}>
     */
    private function buildShiftReportSheets(array $report): array
    {
        $isOpen = (bool) $report['is_open'];
        $header = $report['header'];
        $drawer = $report['cash_drawer'];
        $payments = $report['payments_by_method'];
        $refunds = $report['refunds'];
        $sales = $report['sales'];
        $excluded = $report['excluded_orders'];

        $reportTypeLabel = $isOpen
            ? __('messages.reports.x_report_title')
            : __('messages.reports.z_report_title');
        $statusLabel = $isOpen
            ? __('messages.reports.status_open')
            : __('messages.reports.status_closed');
        $closedAtLabel = $header['closed_at'] !== null
            ? $header['closed_at'].' WIB'
            : __('messages.reports.header_still_open');

        // Sheet 1: Header & Cash Drawer Reconciliation
        $drawerRows = [
            [XlsxWriter::stringCell(__('messages.export.shop_name')), XlsxWriter::stringCell((string) $header['shop_name'])],
            [XlsxWriter::stringCell(__('messages.export.report_type')), XlsxWriter::stringCell($reportTypeLabel)],
            [XlsxWriter::stringCell(__('messages.reports.header_shift_number')), XlsxWriter::stringCell((string) $header['shift_number'])],
            [XlsxWriter::stringCell(__('messages.reports.header_cashier')), XlsxWriter::stringCell((string) $header['cashier_name'])],
            [XlsxWriter::stringCell(__('messages.reports.header_status')), XlsxWriter::stringCell($statusLabel)],
            [XlsxWriter::stringCell(__('messages.reports.header_opened_at')), XlsxWriter::stringCell((string) $header['opened_at'].' WIB')],
            [XlsxWriter::stringCell(__('messages.reports.header_closed_at')), XlsxWriter::stringCell($closedAtLabel)],
            [XlsxWriter::stringCell(__('messages.reports.header_generated_at')), XlsxWriter::stringCell((string) $header['generated_at'].' WIB')],
            [
                XlsxWriter::stringCell(__('messages.reports.opening_note_label')),
                XlsxWriter::stringCell($header['opening_note'] !== '' ? (string) $header['opening_note'] : __('messages.reports.no_note')),
            ],
            [XlsxWriter::stringCell(__('messages.reports.opening_cash')), XlsxWriter::numericCell((int) $drawer['opening_cash'])],
            [XlsxWriter::stringCell(__('messages.reports.manual_cash_in')), XlsxWriter::numericCell((int) $drawer['manual_cash_in'])],
            [XlsxWriter::stringCell(__('messages.reports.manual_cash_out')), XlsxWriter::numericCell((int) $drawer['manual_cash_out'])],
            [XlsxWriter::stringCell(__('messages.reports.cash_sales_net')), XlsxWriter::numericCell((int) $drawer['cash_sales'])],
            [XlsxWriter::stringCell(__('messages.export.cash_tendered')), XlsxWriter::numericCell((int) $drawer['cash_tendered'])],
            [XlsxWriter::stringCell(__('messages.export.cash_change')), XlsxWriter::numericCell((int) $drawer['cash_change'])],
            [XlsxWriter::stringCell(__('messages.reports.cash_refunds_out')), XlsxWriter::numericCell((int) $drawer['cash_refunds'])],
            [XlsxWriter::stringCell(__('messages.reports.expected_cash')), XlsxWriter::numericCell((int) $drawer['expected_cash'])],
            [XlsxWriter::stringCell(__('messages.reports.non_cash_sales_info')), XlsxWriter::numericCell((int) $drawer['non_cash_sales'])],
        ];

        if (! $isOpen) {
            $drawerRows[] = [
                XlsxWriter::stringCell(__('messages.reports.counted_cash')),
                XlsxWriter::numericCell((int) ($drawer['counted_cash'] ?? 0)),
            ];
            $drawerRows[] = [
                XlsxWriter::stringCell(__('messages.reports.variance')),
                XlsxWriter::numericCell((int) ($drawer['variance'] ?? 0)),
            ];
            $drawerRows[] = [
                XlsxWriter::stringCell(__('messages.reports.closing_note_label')),
                XlsxWriter::stringCell($drawer['closing_note'] !== '' ? (string) $drawer['closing_note'] : __('messages.reports.no_note')),
            ];
        }

        foreach ($drawer['movements_by_category'] as $mov) {
            $catLabel = match ((string) $mov['category']) {
                'capital_add' => __('messages.shifts.cat_capital_add'),
                'owner_deposit' => __('messages.shifts.cat_owner_deposit'),
                'other_in' => __('messages.shifts.cat_other_in'),
                'expense' => __('messages.shifts.cat_expense'),
                'cash_drop' => __('messages.shifts.cat_cash_drop'),
                'owner_withdrawal' => __('messages.shifts.cat_owner_withdrawal'),
                'other_out' => __('messages.shifts.cat_other_out'),
                'reversal' => __('messages.shifts.cat_reversal'),
                default => __('messages.shifts.cat_other_in'),
            };
            $typeLabel = $mov['type'] === 'in'
                ? __('messages.shifts.badge_type_in')
                : __('messages.shifts.badge_type_out');

            $drawerRows[] = [
                XlsxWriter::stringCell("{$typeLabel} — {$catLabel} ({$mov['count']}×)"),
                XlsxWriter::numericCell((int) $mov['amount']),
            ];
        }

        // Sheet 2: Payments by Method & Refunds
        $paymentsAndRefundsRows = [
            [XlsxWriter::stringCell(__('messages.reports.payment_cash')), XlsxWriter::numericCell((int) $payments['cash'])],
            [XlsxWriter::stringCell(__('messages.reports.payment_qris')), XlsxWriter::numericCell((int) $payments['qris'])],
            [XlsxWriter::stringCell(__('messages.reports.payment_debit')), XlsxWriter::numericCell((int) $payments['debit'])],
            [XlsxWriter::stringCell(__('messages.reports.payment_other')), XlsxWriter::numericCell((int) $payments['other'])],
            [XlsxWriter::stringCell(__('messages.reports.payments_total')), XlsxWriter::numericCell((int) $payments['total'])],
            [XlsxWriter::stringCell(__('messages.reports.refund_count')), XlsxWriter::numericCell((int) $refunds['count'])],
            [XlsxWriter::stringCell(__('messages.reports.refund_method_cash')), XlsxWriter::numericCell((int) $refunds['by_method']['cash'])],
            [XlsxWriter::stringCell(__('messages.reports.refund_method_qris_reversal')), XlsxWriter::numericCell((int) $refunds['by_method']['qris_reversal'])],
            [XlsxWriter::stringCell(__('messages.reports.refund_method_manual_transfer')), XlsxWriter::numericCell((int) $refunds['by_method']['manual_transfer'])],
            [XlsxWriter::stringCell(__('messages.reports.refund_total')), XlsxWriter::numericCell((int) $refunds['total'])],
        ];

        // Sheet 3: Sales Summary
        $salesRows = [
            [XlsxWriter::stringCell(__('messages.reports.subtotal_sales')), XlsxWriter::numericCell((int) $sales['subtotal_sales'])],
            [XlsxWriter::stringCell(__('messages.reports.tax_total')), XlsxWriter::numericCell((int) $sales['tax_total'])],
            [XlsxWriter::stringCell(__('messages.reports.service_charge_total')), XlsxWriter::numericCell((int) $sales['service_charge_total'])],
            [XlsxWriter::stringCell(__('messages.export.col_discounts')), XlsxWriter::numericCell((int) $sales['discount_total'])],
            [XlsxWriter::stringCell(__('messages.reports.gross_sales')), XlsxWriter::numericCell((int) $sales['gross_sales'])],
            [XlsxWriter::stringCell(__('messages.reports.refunds_in_shift')), XlsxWriter::numericCell((int) $sales['refunds_total'])],
            [XlsxWriter::stringCell(__('messages.reports.net_sales')), XlsxWriter::numericCell((int) $sales['net_sales'])],
            [XlsxWriter::stringCell(__('messages.reports.paid_orders_count')), XlsxWriter::numericCell((int) $sales['paid_orders_count'])],
            [XlsxWriter::stringCell(__('messages.reports.avg_ticket')), XlsxWriter::numericCell((int) $sales['avg_ticket'])],
            [XlsxWriter::stringCell(__('messages.reports.unpaid_orders_count')), XlsxWriter::numericCell((int) $excluded['unpaid_count'])],
            [XlsxWriter::stringCell(__('messages.reports.cancelled_orders_count')), XlsxWriter::numericCell((int) $excluded['cancelled_count'])],
        ];

        return [
            [
                'name' => __('messages.export.sheet_shift_drawer'),
                'headers' => [__('messages.export.col_metric'), __('messages.export.col_value')],
                'rows' => $drawerRows,
            ],
            [
                'name' => __('messages.export.sheet_shift_payments'),
                'headers' => [__('messages.export.col_metric'), __('messages.export.col_amount_idr')],
                'rows' => $paymentsAndRefundsRows,
            ],
            [
                'name' => __('messages.export.sheet_shift_sales'),
                'headers' => [__('messages.export.col_metric'), __('messages.export.col_value')],
                'rows' => $salesRows,
            ],
        ];
    }

    /**
     * Build the HTTP download response for either XLSX or CSV.
     *
     * @param  list<array{name: string, headers: list<string>, rows: list<list<array{type: 's'|'n', value: string}>>}>  $sheets
     */
    private function buildDownloadResponse(array $sheets, string $format, string $filenameBase): Response
    {
        if ($format === 'xlsx') {
            $payload = $this->xlsxWriter->write($sheets);
            $filename = "{$filenameBase}.xlsx";

            return response($payload, 200, [
                'Content-Type' => self::MIME_XLSX,
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]);
        }

        $payload = $this->csvWriter->writeSections($sheets);
        $filename = "{$filenameBase}.csv";

        return response($payload, 200, [
            'Content-Type' => self::MIME_CSV,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Validate that $from and $to are strict Y-m-d calendar dates, $from <= $to, and the range is at most 366 days.
     *
     * @return ?array{0: string, 1: string}
     */
    private function validateDateRange(string $from, string $to): ?array
    {
        $fromDate = $this->parseStrictCalendarDate($from);
        $toDate = $this->parseStrictCalendarDate($to);

        if ($fromDate === null || $toDate === null) {
            return null;
        }

        if ($fromDate->gt($toDate)) {
            return null;
        }

        $daysInclusive = (int) $fromDate->diffInDays($toDate) + 1;
        if ($daysInclusive > SalesReport::MAX_RANGE_DAYS) {
            return null;
        }

        return [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')];
    }

    /**
     * Parse a strict YYYY-MM-DD calendar date string, returning null if malformed or non-existent.
     */
    private function parseStrictCalendarDate(string $value): ?CarbonImmutable
    {
        $trimmed = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::createFromDate($year, $month, $day)->startOfDay();
    }

    /**
     * Count total data rows across all worksheets/sections for ActivityLog auditing.
     *
     * @param  list<array{rows: list<mixed>}>  $sheets
     */
    private function countTotalDataRows(array $sheets): int
    {
        $count = 0;
        foreach ($sheets as $sheet) {
            $count += count($sheet['rows']);
        }

        return $count;
    }

    /**
     * Verify that the current user is an Owner.
     */
    private function authorizeOwner(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }
    }

    /**
     * Verify that the current user is either an Owner or the Cashier who opened the shift.
     */
    private function authorizeShift(Shift $shift): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }

        if (! $user->isOwner() && (int) $shift->user_id !== (int) $user->id) {
            abort(403);
        }
    }
}
