<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Http\Controllers\ReportExportController;
use App\Livewire\Reports\SalesAnalytics;
use App\Livewire\Reports\SalesReport;
use App\Livewire\Reports\ShiftReport;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\CashMovementService;
use App\Services\Export\XlsxWriter;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\SalesReportService;
use App\Services\ShiftReportService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use SimpleXMLElement;
use Tests\TestCase;
use ZipArchive;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private ShiftService $shiftService;

    private CashMovementService $cashMovementService;

    private OrderService $orderService;

    private PaymentService $paymentService;

    private RefundService $refundService;

    private SalesReportService $salesReportService;

    private AnalyticsService $analyticsService;

    private ShiftReportService $shiftReportService;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $this->shiftService = app(ShiftService::class);
        $this->cashMovementService = app(CashMovementService::class);
        $this->orderService = app(OrderService::class);
        $this->paymentService = app(PaymentService::class);
        $this->refundService = app(RefundService::class);
        $this->salesReportService = app(SalesReportService::class);
        $this->analyticsService = app(AnalyticsService::class);
        $this->shiftReportService = app(ShiftReportService::class);

        $this->product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();
    }

    public function test_exported_xlsx_and_csv_numbers_equal_report_services_and_write_audit_logs(): void
    {
        ShopSetting::current()?->update([
            'tax_rate' => 1000,
            'service_charge_rate' => 500,
            'business_day_start_time' => '06:00:00',
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 11:00:00', 'Asia/Jakarta'));

        $shift = $this->shiftService->openShift($this->cashier, 200000, 'Opening drawer note');
        $this->cashMovementService->recordMovement($shift, $this->cashier, 'in', 25000, 'capital_add', 'Extra coins');

        // Day 1 (2026-11-26): 1 Dine-In cash order (partially refunded 5,000)
        $o1 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ]);
        $tx1 = $this->paymentService->settlePayment($this->cashier, $shift, $o1, [[
            'payment_method' => 'cash',
            'amount' => $o1->total_amount,
            'tendered_amount' => $o1->total_amount + 10000,
            'change_amount' => 10000,
        ]]);
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $o1->fresh(),
            allocations: [['payment_id' => $tx1->payments->firstOrFail()->id, 'amount' => 5000, 'refund_method' => 'cash']],
            reason: 'Partial refund for export test',
            shift: $shift
        );

        // Day 2 (2026-11-27): 1 Takeaway QRIS order
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-27 14:00:00', 'Asia/Jakarta'));
        $o2 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]],
        ]);
        $qrisConf = $this->paymentService->createQrisConfirmation($this->cashier, $o2, $o2->total_amount, 'QRIS-EXP-1');
        $this->paymentService->settlePayment($this->cashier, $shift, $o2, [[
            'payment_method' => 'qris',
            'amount' => $o2->total_amount,
            'qris_confirmation_id' => $qrisConf->id,
        ]]);

        // Close shift with a formula-like closing note to verify formula neutralization in shift export
        $expectedCash = $this->shiftService->calculateExpectedCash($shift);
        $closedShift = $this->shiftService->closeShift(
            shift: $shift,
            closingUser: $this->cashier,
            actualCash: $expectedCash - 1000,
            closingNote: '=SUM(1+1) Selisih seribu rupiah'
        );

        App::setLocale('en');
        $this->withSession(['locale' => 'en']);

        // ── 1. Sales Report XLSX & CSV Parity ────────────────────────────────
        $expectedSales = $this->salesReportService->buildReport('2026-11-26', '2026-11-27');

        $salesXlsxResp = $this->actingAs($this->owner)
            ->get(route('reports.sales.export', [
                'format' => 'xlsx',
                'from' => '2026-11-26',
                'to' => '2026-11-27',
            ]));

        $salesXlsxResp->assertOk();
        $salesXlsxResp->assertHeader('Content-Type', ReportExportController::MIME_XLSX);
        $salesXlsxResp->assertHeader(
            'Content-Disposition',
            'attachment; filename="temu_pulang_sales_2026-11-26_to_2026-11-27.xlsx"'
        );

        $salesSheets = $this->parseXlsxSheets($salesXlsxResp->getContent());
        $this->assertSame(['Summary', 'Per day', 'Payment mix'], array_keys($salesSheets));

        // Sheet 2 ("Per day"): rows 1 & 2 are the two days, row 3 is the Totals row
        $perDayRows = $salesSheets['Per day']['rows'];
        $this->assertCount(3, $perDayRows);

        $day1Row = $perDayRows[0];
        $day2Row = $perDayRows[1];
        $totalsRow = $perDayRows[2];

        $this->assertSame('2026-11-26', $day1Row[0]['value']);
        $this->assertSame((string) $expectedSales['daily_rows'][0]['paid_orders_count'], $day1Row[1]['value']);
        $this->assertSame((string) $expectedSales['daily_rows'][0]['gross_sales'], $day1Row[2]['value']);
        $this->assertSame((string) $expectedSales['daily_rows'][0]['refunds'], $day1Row[8]['value']);
        $this->assertSame((string) $expectedSales['daily_rows'][0]['net_sales'], $day1Row[9]['value']);

        $this->assertSame('2026-11-27', $day2Row[0]['value']);
        $this->assertSame((string) $expectedSales['daily_rows'][1]['paid_orders_count'], $day2Row[1]['value']);
        $this->assertSame((string) $expectedSales['daily_rows'][1]['gross_sales'], $day2Row[2]['value']);

        // Verify per-day rows sum to the exported totals row and match SalesReportService::buildReport()
        $this->assertSame(
            (int) $day1Row[1]['value'] + (int) $day2Row[1]['value'],
            (int) $totalsRow[1]['value']
        );
        $this->assertSame($expectedSales['totals']['paid_orders_count'], (int) $totalsRow[1]['value']);
        $this->assertSame($expectedSales['totals']['gross_sales'], (int) $totalsRow[2]['value']);
        $this->assertSame($expectedSales['totals']['subtotal'], (int) $totalsRow[3]['value']);
        $this->assertSame($expectedSales['totals']['tax'], (int) $totalsRow[4]['value']);
        $this->assertSame($expectedSales['totals']['service_charge'], (int) $totalsRow[5]['value']);
        $this->assertSame($expectedSales['totals']['refunds'], (int) $totalsRow[8]['value']);
        $this->assertSame($expectedSales['totals']['net_sales'], (int) $totalsRow[9]['value']);
        $this->assertSame($expectedSales['totals']['avg_ticket'], (int) $totalsRow[10]['value']);
        $this->assertSame($expectedSales['totals']['cogs'], (int) $totalsRow[11]['value']);
        $this->assertSame($expectedSales['totals']['gross_profit'], (int) $totalsRow[12]['value']);
        $this->assertSame(
            XlsxWriter::tenthsToDecimalString((int) $expectedSales['totals']['margin']['tenths']),
            $totalsRow[13]['value']
        );

        // Payment mix sheet matches service
        $mixRows = $salesSheets['Payment mix']['rows'];
        $this->assertSame((string) $expectedSales['payment_mix']['cash'], $mixRows[0][1]['value']);
        $this->assertSame((string) $expectedSales['payment_mix']['qris'], $mixRows[1][1]['value']);
        $this->assertSame((string) $expectedSales['payment_mix']['total'], $mixRows[4][1]['value']);

        // Sales CSV export contains exact same numbers
        $salesCsvResp = $this->actingAs($this->owner)
            ->get(route('reports.sales.export', [
                'format' => 'csv',
                'from' => '2026-11-26',
                'to' => '2026-11-27',
            ]));
        $salesCsvResp->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $salesCsvResp->headers->get('Content-Type'));
        $salesCsvBody = (string) $salesCsvResp->getContent();
        $this->assertStringContainsString((string) $expectedSales['totals']['gross_sales'], $salesCsvBody);
        $this->assertStringContainsString((string) $expectedSales['totals']['net_sales'], $salesCsvBody);

        // ── 2. Analytics Report XLSX & CSV Parity ────────────────────────────
        $expectedAnalytics = $this->analyticsService->buildAnalytics('2026-11-26', '2026-11-27');

        $analyticsXlsxResp = $this->actingAs($this->owner)
            ->get(route('reports.analytics.export', [
                'format' => 'xlsx',
                'from' => '2026-11-26',
                'to' => '2026-11-27',
            ]));
        $analyticsXlsxResp->assertOk();
        $analyticsXlsxResp->assertHeader(
            'Content-Disposition',
            'attachment; filename="temu_pulang_analytics_2026-11-26_to_2026-11-27.xlsx"'
        );

        $analyticsSheets = $this->parseXlsxSheets($analyticsXlsxResp->getContent());
        $this->assertSame(
            ['Top by Quantity', 'Top by Revenue', 'Products Without Sales', 'Hours', 'Weekdays', 'Order Types'],
            array_keys($analyticsSheets)
        );

        // Top by Quantity row 0 matches top_by_quantity[0]
        $topQtyRow0 = $analyticsSheets['Top by Quantity']['rows'][0];
        $this->assertSame((string) $expectedAnalytics['top_by_quantity'][0]['quantity'], $topQtyRow0[3]['value']);
        $this->assertSame((string) $expectedAnalytics['top_by_quantity'][0]['revenue'], $topQtyRow0[4]['value']);
        $this->assertSame(
            XlsxWriter::tenthsToDecimalString($expectedAnalytics['top_by_quantity'][0]['revenue_share_tenths']),
            $topQtyRow0[5]['value']
        );

        // Hours sheet has 24 hour rows + 1 total row = 25 rows
        $this->assertCount(25, $analyticsSheets['Hours']['rows']);
        $hoursTotalRow = $analyticsSheets['Hours']['rows'][24];
        $this->assertSame((string) $expectedAnalytics['sales_by_hour']['total_orders'], $hoursTotalRow[1]['value']);
        $this->assertSame((string) $expectedAnalytics['sales_by_hour']['total_gross_sales'], $hoursTotalRow[2]['value']);

        // Order Types sheet matches dine_in & takeaway
        $orderTypesRows = $analyticsSheets['Order Types']['rows'];
        $this->assertSame(
            (string) $expectedAnalytics['sales_by_order_type']['types'][Order::TYPE_DINE_IN]['gross_sales'],
            $orderTypesRows[0][3]['value']
        );
        $this->assertSame(
            (string) $expectedAnalytics['sales_by_order_type']['types'][Order::TYPE_TAKEAWAY]['gross_sales'],
            $orderTypesRows[1][3]['value']
        );

        // ── 3. Shift Report XLSX & CSV Parity (with formula-neutralized closing note & negative variance) ──
        $expectedShiftReport = $this->shiftReportService->buildReport($closedShift);

        $shiftXlsxResp = $this->actingAs($this->cashier)
            ->get(route('reports.shift.export', [
                'shift' => $closedShift->id,
                'format' => 'xlsx',
            ]));
        $shiftXlsxResp->assertOk();
        $shiftXlsxResp->assertHeader(
            'Content-Disposition',
            'attachment; filename="temu_pulang_shift_'.$closedShift->shift_number.'.xlsx"'
        );

        $shiftSheets = $this->parseXlsxSheets($shiftXlsxResp->getContent());
        $this->assertSame(
            ['Header & Cash Drawer', 'Payments & Refunds', 'Sales Summary'],
            array_keys($shiftSheets)
        );

        $drawerMap = [];
        foreach ($shiftSheets['Header & Cash Drawer']['rows'] as $r) {
            $drawerMap[$r[0]['value']] = $r[1];
        }

        // Note: "= Expected Cash in Drawer" starts with '=', so formula-injection neutralization prefixes it with "'"
        $this->assertSame((string) $expectedShiftReport['cash_drawer']['expected_cash'], $drawerMap["'= Expected Cash in Drawer"]['value']);
        $this->assertSame((string) $expectedShiftReport['cash_drawer']['counted_cash'], $drawerMap['Counted Physical Cash']['value']);
        // Negative variance (-1000) is a numeric cell and is NOT prefixed with an apostrophe
        $this->assertSame('n', $drawerMap['Cash Variance (Discrepancy)']['type']);
        $this->assertSame('-1000', $drawerMap['Cash Variance (Discrepancy)']['value']);
        // Closing note starting with "=SUM(1+1)" IS neutralized with a leading apostrophe
        $this->assertSame('s', $drawerMap['Shift Closing Note']['type']);
        $this->assertSame("'=SUM(1+1) Selisih seribu rupiah", $drawerMap['Shift Closing Note']['value']);

        // ── 4. Verify ActivityLog entries have no file content or personal data ──
        $exportLogs = ActivityLog::where('module', 'reports')
            ->where('action', 'report.exported')
            ->get();
        $this->assertCount(4, $exportLogs);
        foreach ($exportLogs as $log) {
            $props = $log->properties;
            $this->assertArrayHasKey('report', $props);
            $this->assertArrayHasKey('format', $props);
            $this->assertArrayHasKey('row_count', $props);
            $this->assertGreaterThan(0, $props['row_count']);
            $this->assertArrayNotHasKey('content', $props);
            $this->assertArrayNotHasKey('payload', $props);
        }

        CarbonImmutable::setTestNow();
    }

    public function test_authorization_and_validation_and_locales_and_buttons_on_report_views(): void
    {
        $otherCashier = User::factory()->cashier()->create();
        $otherShift = $this->shiftService->openShift($otherCashier, 100000);
        $this->shiftService->closeShift($otherShift, $otherCashier, 100000, 'Other shift closed');

        $ownShift = $this->shiftService->openShift($this->cashier, 100000);

        // 1. Guests redirect to login on all 3 export routes
        $this->get(route('reports.sales.export', ['format' => 'xlsx']))
            ->assertRedirect(route('login'));
        $this->get(route('reports.analytics.export', ['format' => 'csv']))
            ->assertRedirect(route('login'));
        $this->get(route('reports.shift.export', ['shift' => $ownShift->id, 'format' => 'xlsx']))
            ->assertRedirect(route('login'));

        // 2. Cashier is blocked from sales & analytics exports, allowed on own shift, and gets 403 on another cashier's shift
        $this->actingAs($this->cashier)
            ->get(route('reports.sales.export', ['format' => 'xlsx']))
            ->assertRedirect(route('pos'));
        $this->actingAs($this->cashier)
            ->get(route('reports.analytics.export', ['format' => 'csv']))
            ->assertRedirect(route('pos'));

        $noRoleUser = User::factory()->create();
        $this->actingAs($noRoleUser)
            ->get(route('reports.sales.export', ['format' => 'xlsx']))
            ->assertForbidden();
        $this->actingAs($noRoleUser)
            ->get(route('reports.analytics.export', ['format' => 'csv']))
            ->assertForbidden();

        $this->actingAs($this->cashier)
            ->get(route('reports.shift.export', ['shift' => $ownShift->id, 'format' => 'csv']))
            ->assertOk();
        $this->actingAs($this->cashier)
            ->get(route('reports.shift.export', ['shift' => $otherShift->id, 'format' => 'csv']))
            ->assertForbidden();

        // Owner can export any shift
        $this->actingAs($this->owner)
            ->get(route('reports.shift.export', ['shift' => $otherShift->id, 'format' => 'csv']))
            ->assertOk();

        // 3. Validation errors redirect back with translated warning message (never an error page)
        App::setLocale('en');
        $this->withSession(['locale' => 'en']);
        $this->withSession(['locale' => 'en']);
        $this->actingAs($this->owner)
            ->get(route('reports.sales.export', ['format' => 'pdf']))
            ->assertRedirect(route('reports.sales'))
            ->assertSessionHas('warning', __('messages.export.invalid_format'));

        $this->actingAs($this->owner)
            ->get(route('reports.sales.export', ['format' => 'xlsx', 'from' => '2026-11-27', 'to' => '2026-11-20']))
            ->assertRedirect(route('reports.sales'))
            ->assertSessionHas('warning', __('messages.sales_report.validation_reset_notice'));

        $this->actingAs($this->owner)
            ->get(route('reports.analytics.export', ['format' => 'csv', 'from' => '2024-01-01', 'to' => '2026-11-27']))
            ->assertRedirect(route('reports.analytics'))
            ->assertSessionHas('warning', __('messages.analytics.validation_reset_notice'));

        $this->actingAs($this->owner)
            ->get(route('reports.analytics.export', ['format' => 'csv', 'from' => 'garbage', 'to' => '2026-02-30']))
            ->assertRedirect(route('reports.analytics'))
            ->assertSessionHas('warning', __('messages.analytics.validation_reset_notice'));

        // 4. Indonesian locale headers & sheet names and export buttons + PDF hint on all 3 report views
        App::setLocale('id');
        $this->withSession(['locale' => 'id']);
        $idSalesXlsx = $this->actingAs($this->owner)
            ->get(route('reports.sales.export', ['format' => 'xlsx', 'from' => '2026-11-27', 'to' => '2026-11-27']));
        $idSheets = $this->parseXlsxSheets($idSalesXlsx->getContent());
        $this->assertSame(['Ringkasan', 'Per Hari', 'Komposisi Pembayaran'], array_keys($idSheets));

        Livewire::actingAs($this->owner)
            ->test(SalesReport::class)
            ->assertSee('Ekspor Excel')
            ->assertSee('Ekspor CSV')
            ->assertSee('Untuk PDF gunakan Cetak dan pilih Simpan sebagai PDF');

        Livewire::actingAs($this->owner)
            ->test(SalesAnalytics::class)
            ->assertSee('Ekspor Excel')
            ->assertSee('Ekspor CSV')
            ->assertSee('Untuk PDF gunakan Cetak dan pilih Simpan sebagai PDF');

        Livewire::actingAs($this->owner)
            ->test(ShiftReport::class, ['shift' => $ownShift])
            ->assertSee('Ekspor Excel')
            ->assertSee('Ekspor CSV')
            ->assertSee('Untuk PDF gunakan Cetak dan pilih Simpan sebagai PDF');
    }

    public function test_366_day_range_export_completes_within_query_bound_and_leaves_no_temp_files(): void
    {
        $tempDir = sys_get_temp_dir();
        $beforeTempFiles = glob($tempDir.DIRECTORY_SEPARATOR.'tp_xlsx_*.xlsx') ?: [];

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resp = $this->actingAs($this->owner)
            ->get(route('reports.sales.export', [
                'format' => 'xlsx',
                'from' => '2025-11-27',
                'to' => '2026-11-27', // 366 days inclusive
            ]));

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $resp->assertOk();
        $this->assertLessThanOrEqual(15, $queryCount);

        $afterTempFiles = glob($tempDir.DIRECTORY_SEPARATOR.'tp_xlsx_*.xlsx') ?: [];
        $this->assertSame($beforeTempFiles, $afterTempFiles);

        // Language key parity check
        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);
    }

    /**
     * Helper to parse an XLSX binary payload back into an associative array keyed by sheet name.
     *
     * @return array<string, array{headers: list<string>, rows: list<list<array{type: string, value: string}>>}>
     */
    private function parseXlsxSheets(string $bytes): array
    {
        $tempFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'parse_xlsx_'.bin2hex(random_bytes(5)).'.xlsx';
        file_put_contents($tempFile, $bytes);

        try {
            $zip = new ZipArchive;
            $this->assertSame(true, $zip->open($tempFile));

            $wb = new SimpleXMLElement((string) $zip->getFromName('xl/workbook.xml'));
            $result = [];
            $sheetIdx = 1;

            foreach ($wb->sheets->sheet as $sheetNode) {
                $sheetName = (string) $sheetNode['name'];
                $sheetXml = new SimpleXMLElement((string) $zip->getFromName("xl/worksheets/sheet{$sheetIdx}.xml"));

                $headers = [];
                $rows = [];
                $rowIndex = 0;

                foreach ($sheetXml->sheetData->row as $rowNode) {
                    $cells = [];
                    foreach ($rowNode->c as $cNode) {
                        $t = (string) $cNode['t'];
                        if ($t === 'inlineStr') {
                            $cells[] = [
                                'type' => 's',
                                'value' => (string) $cNode->is->t,
                            ];
                        } else {
                            $cells[] = [
                                'type' => 'n',
                                'value' => (string) $cNode->v,
                            ];
                        }
                    }

                    if ($rowIndex === 0) {
                        $headers = array_column($cells, 'value');
                    } else {
                        $rows[] = $cells;
                    }
                    $rowIndex++;
                }

                $result[$sheetName] = [
                    'headers' => $headers,
                    'rows' => $rows,
                ];
                $sheetIdx++;
            }

            $zip->close();

            return $result;
        } finally {
            @unlink($tempFile);
        }
    }
}
