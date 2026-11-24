<?php

namespace Tests\Unit\Printing;

use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\Printing\ReceiptRendererService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptRendererServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ReceiptRendererService $renderer;

    protected ShopSetting $setting;

    protected User $cashier;

    protected Shift $shift;

    protected static int $sequenceCounter = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->renderer = new ReceiptRendererService;

        $this->cashier = User::where('email', 'cashier@temupulang.local')->first()
            ?? User::factory()->create(['name' => 'Budi Santoso']);

        $this->shift = Shift::factory()->create([
            'user_id' => $this->cashier->id,
        ]);

        $this->setting = ShopSetting::current() ?? ShopSetting::factory()->create();
        $this->setting->update([
            'name' => 'Temu Pulang Coffee',
            'address' => 'Jl. Merdeka No. 123, Bandung',
            'phone' => '081234567890',
            'receipt_footer' => 'Terima Kasih Atas Kunjungan Anda',
            'receipt_show_tax' => true,
        ]);
        $this->setting->refresh();
    }

    protected function createMockOrderAndTransaction(array $orderOverrides = [], array $txOverrides = []): array
    {
        $num = str_pad((string) self::$sequenceCounter++, 4, '0', STR_PAD_LEFT);

        $order = Order::create(array_merge([
            'ulid' => (string) Str::ulid(),
            'order_number' => "ORD-20261114-{$num}",
            'daily_order_number' => $num,
            'daily_sequence' => (int) $num,
            'business_date' => '2026-11-14',
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'order_type' => Order::TYPE_DINE_IN,
            'status' => Order::STATUS_PAID,
            'subtotal' => 50000,
            'tax_rate' => 1000,
            'tax_amount' => 5000,
            'service_charge_rate' => 0,
            'service_charge_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 55000,
            'customer_name' => 'Pelanggan 1',
            'paid_at' => now('Asia/Jakarta'),
        ], $orderOverrides));

        $product = Product::first();
        $modifier = Modifier::with('group')->first();

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product?->id,
            'sku' => $product?->sku ?? 'PRD-TEST-001',
            'product_name_id' => 'Kopi Tubruk',
            'product_name_en' => 'Brewed Coffee',
            'variant_name_id' => 'Panas',
            'variant_name_en' => 'Hot',
            'unit_price' => 25000,
            'modifiers_unit_price' => 5000,
            'final_unit_price' => 30000,
            'quantity' => 2,
            'subtotal' => 60000,
            'special_instructions' => 'Kurang manis',
        ]);

        if ($modifier) {
            $item->modifiers()->create([
                'modifier_id' => $modifier->id,
                'modifier_group_id' => $modifier->modifier_group_id,
                'group_name_id' => 'Tambahan',
                'group_name_en' => 'Extras',
                'modifier_name_id' => 'Extra Shot',
                'modifier_name_en' => 'Extra Shot',
                'unit_price' => 5000,
            ]);
        }

        $transaction = PaymentTransaction::create(array_merge([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => "TRX-20261114-{$num}",
            'daily_sequence' => (int) $num,
            'business_date' => '2026-11-14',
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'cashier_name' => 'Budi Santoso',
            'total_amount' => 55000,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => 'test-fingerprint-'.$num,
            'status' => 'completed',
        ], $txOverrides));

        $payment = new Payment([
            'payment_transaction_id' => $transaction->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 55000,
            'tendered_amount' => 60000,
            'change_amount' => 5000,
        ]);
        $payment->order_id = $order->id;
        $payment->save();

        return [$order->fresh(['items.modifiers']), $transaction->fresh(['payments'])];
    }

    public function test_renders_standard_receipt_correctly(): void
    {
        [$order, $transaction] = $this->createMockOrderAndTransaction();

        $bytes = $this->renderer->render($order, $transaction, $this->setting, false);

        $this->assertStringContainsString('TEMU PULANG COFFEE', $bytes);
        $this->assertStringContainsString('Jl. Merdeka No. 123, Bandung', $bytes);
        $this->assertStringContainsString('081234567890', $bytes);
        $this->assertStringContainsString($transaction->payment_transaction_number, $bytes);
        $this->assertStringContainsString($order->order_number, $bytes);
        $this->assertStringContainsString('Kasir:', $bytes);
        $this->assertStringContainsString('Budi Santoso', $bytes);
        $this->assertStringContainsString('2x Kopi Tubruk (Panas)', $bytes);
        $this->assertStringContainsString('Extra Shot', $bytes);
        $this->assertStringContainsString('Kurang manis', $bytes);
        $this->assertStringContainsString('Subtotal', $bytes);
        $this->assertStringContainsString('TOTAL', $bytes);
        $this->assertStringContainsString('Tunai', $bytes);
        $this->assertStringContainsString('Tunai Diterima', $bytes);
        $this->assertStringContainsString('Kembalian', $bytes);
        $this->assertStringContainsString('Terima Kasih Atas Kunjungan Anda', $bytes);
        $this->assertStringNotContainsString('*** SALINAN / REPRINT ***', $bytes);
    }

    public function test_reprint_watermark_is_rendered_when_flagged(): void
    {
        [$order, $transaction] = $this->createMockOrderAndTransaction();

        $bytes = $this->renderer->render($order, $transaction, $this->setting, true);

        $this->assertStringContainsString('*** SALINAN / REPRINT ***', $bytes);
    }

    public function test_cashier_name_fallback_chain_when_cashier_name_is_null(): void
    {
        // 1. cashier_name null, fallback to user relation
        [$order, $transaction] = $this->createMockOrderAndTransaction([], [
            'cashier_name' => null,
        ]);

        $bytes = $this->renderer->render($order, $transaction, $this->setting);
        $this->assertStringContainsString($this->cashier->name, $bytes);

        // 2. cashier_name null and user_id is non-existent
        $num = str_pad((string) self::$sequenceCounter++, 4, '0', STR_PAD_LEFT);
        $orphanOrder = Order::create([
            'ulid' => (string) Str::ulid(),
            'order_number' => "ORD-20261114-{$num}",
            'daily_order_number' => $num,
            'daily_sequence' => (int) $num,
            'business_date' => '2026-11-14',
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'order_type' => Order::TYPE_DINE_IN,
            'status' => Order::STATUS_PAID,
            'subtotal' => 50000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'service_charge_rate' => 0,
            'service_charge_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 50000,
            'paid_at' => now('Asia/Jakarta'),
        ]);

        // 2. cashier_name null and user_id is null
        $orphanTx = new PaymentTransaction([
            'payment_transaction_number' => "TRX-20261114-{$num}",
            'cashier_name' => null,
            'user_id' => null,
        ]);
        $orphanTx->setRelation('payments', collect([]));

        $bytesOrphan = $this->renderer->render($orphanOrder, $orphanTx, $this->setting);
        $this->assertStringContainsString('Kasir Tidak Diketahui', $bytesOrphan);
    }

    public function test_tax_line_omitted_when_zero_or_disabled(): void
    {
        // When tax_amount is 0
        [$orderZeroTax, $txZeroTax] = $this->createMockOrderAndTransaction([
            'tax_amount' => 0,
            'tax_rate' => 0,
        ]);

        $bytesZero = $this->renderer->render($orderZeroTax, $txZeroTax, $this->setting);
        $this->assertStringNotContainsString('Pajak (', $bytesZero);

        // When setting disables tax display
        $this->setting->receipt_show_tax = false;
        [$orderWithTax, $txWithTax] = $this->createMockOrderAndTransaction([
            'tax_amount' => 5000,
            'tax_rate' => 1000,
        ]);

        $bytesDisabled = $this->renderer->render($orderWithTax, $txWithTax, $this->setting);
        $this->assertStringNotContainsString('Pajak (', $bytesDisabled);
    }
}
