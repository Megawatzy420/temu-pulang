<?php

namespace App\Services\Printing;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\ShopSetting;
use App\Models\User;

/**
 * Renders a customer receipt as an ESC/POS byte stream.
 * Column width: 48 characters (80mm paper).
 */
class ReceiptRendererService
{
    private const COLS = 48;

    public function render(
        Order $order,
        PaymentTransaction $transaction,
        ShopSetting $setting,
        bool $isReprint = false
    ): string {
        $esc = new EscPosBuilder;
        $esc->initialize();

        $this->renderHeader($esc, $setting);
        $this->renderMeta($esc, $order, $transaction);
        $this->renderCashier($esc, $transaction);
        $esc->rule(self::COLS);
        $this->renderItems($esc, $order);
        $esc->rule(self::COLS);
        $this->renderTotals($esc, $order, $setting);
        $esc->rule(self::COLS);
        $this->renderPayments($esc, $transaction);

        if ($isReprint) {
            $esc->rule(self::COLS);
            $esc->alignCenter();
            $esc->bold(true);
            $esc->text('*** SALINAN / REPRINT ***');
            $esc->bold(false);
            $esc->text(now('Asia/Jakarta')->format('d/m/Y H:i:s'));
            $esc->alignLeft();
        }

        // Footer
        if (! empty($setting->receipt_footer)) {
            $esc->rule(self::COLS);
            $esc->alignCenter();
            foreach (explode("\n", $setting->receipt_footer) as $line) {
                $esc->text(trim($line));
            }
            $esc->alignLeft();
        }

        $esc->feed(5);

        return $esc->getBytes();
    }

    // ── Sections ──────────────────────────────────────────────────────────────

    private function renderHeader(EscPosBuilder $esc, ShopSetting $setting): void
    {
        $esc->alignCenter();
        $esc->bold(true);
        $esc->text(strtoupper($setting->name ?? 'TEMU PULANG'));
        $esc->bold(false);

        if (! empty($setting->address)) {
            $esc->text($setting->address);
        }
        if (! empty($setting->phone)) {
            $esc->text('Tel: '.$setting->phone);
        }

        $esc->alignLeft();
    }

    private function renderMeta(EscPosBuilder $esc, Order $order, PaymentTransaction $transaction): void
    {
        $esc->rule(self::COLS);
        $esc->twoCol('No. Transaksi:', $transaction->payment_transaction_number, self::COLS);

        $paidAt = $order->paid_at
            ? $order->paid_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s')
            : now('Asia/Jakarta')->format('d/m/Y H:i:s');
        $esc->twoCol('Tanggal:', $paidAt, self::COLS);

        $orderType = $order->order_type === Order::TYPE_DINE_IN ? 'Dine-in' : 'Takeaway';
        $esc->twoCol('Jenis Pesanan:', $orderType, self::COLS);
        $esc->twoCol('No. Pesanan:', $order->order_number, self::COLS);
    }

    private function renderCashier(EscPosBuilder $esc, PaymentTransaction $transaction): void
    {
        $cashierName = $this->resolveCashierName($transaction);
        $esc->twoCol('Kasir:', $cashierName, self::COLS);
    }

    private function renderItems(EscPosBuilder $esc, Order $order): void
    {
        $esc->bold(true);
        $esc->text('ITEM');
        $esc->bold(false);

        $items = $order->relationLoaded('items') ? $order->items : $order->items()->with('modifiers')->get();

        foreach ($items as $item) {
            $productName = $item->product_name ?? 'Item';
            $variantName = $item->variant_name ? " ({$item->variant_name})" : '';
            $qty = (int) $item->quantity;
            $unitPrice = (int) $item->unit_price;
            $lineTotal = (int) ($item->subtotal ?? ($item->final_unit_price * $qty));

            // Product line: "2x Nama Produk (Varian)         Rp 20.000"
            $label = "{$qty}x {$productName}{$variantName}";
            $value = $this->formatRupiah($lineTotal);
            $space = self::COLS - mb_strlen($label) - mb_strlen($value);
            if ($space < 1) {
                // Wrap product name
                $esc->text($label);
                $esc->twoCol('  @'.$this->formatRupiah($unitPrice).' x'.$qty, $value, self::COLS);
            } else {
                $esc->twoCol($label, $value, self::COLS);
            }

            // Modifiers
            $modifiers = $item->relationLoaded('modifiers') ? $item->modifiers : $item->modifiers()->get();
            foreach ($modifiers as $modifier) {
                $modName = '  + '.($modifier->modifier_name ?? '');
                $modPrice = (int) ($modifier->unit_price ?? $modifier->price_adjustment ?? 0);
                $modTotal = $modPrice * $qty;
                if ($modPrice > 0) {
                    $esc->twoCol($modName, $this->formatRupiah($modTotal), self::COLS);
                } else {
                    $esc->text($modName);
                }
            }

            // Notes / Special Instructions
            $notes = $item->special_instructions ?? $item->notes ?? null;
            if (! empty($notes)) {
                $esc->text('  Catatan: '.$notes);
            }
        }
    }

    private function renderTotals(EscPosBuilder $esc, Order $order, ShopSetting $setting): void
    {
        $subtotal = (int) $order->subtotal;
        $discount = (int) $order->discount_amount;
        $serviceCharge = (int) $order->service_charge_amount;
        $taxAmount = (int) $order->tax_amount;
        $total = (int) $order->total_amount;

        $esc->twoCol('Subtotal', $this->formatRupiah($subtotal), self::COLS);

        if ($discount > 0) {
            $esc->twoCol('Diskon', '-'.$this->formatRupiah($discount), self::COLS);
        }

        if ($serviceCharge > 0) {
            $scRate = $order->service_charge_rate / 100;
            $esc->twoCol("Service Charge ({$scRate}%)", $this->formatRupiah($serviceCharge), self::COLS);
        }

        // Tax: use frozen order snapshot; only show if setting enabled and amount > 0
        if ($taxAmount > 0 && $setting->receipt_show_tax) {
            $taxRate = $order->tax_rate / 100;
            $esc->twoCol("Pajak ({$taxRate}%)", $this->formatRupiah($taxAmount), self::COLS);
        }

        $esc->bold(true);
        $esc->twoCol('TOTAL', $this->formatRupiah($total), self::COLS);
        $esc->bold(false);
    }

    private function renderPayments(EscPosBuilder $esc, PaymentTransaction $transaction): void
    {
        $esc->bold(true);
        $esc->text('PEMBAYARAN');
        $esc->bold(false);

        $payments = $transaction->relationLoaded('payments') ? $transaction->payments : $transaction->payments()->get();

        foreach ($payments as $payment) {
            $methodLabel = $this->labelMethod($payment->payment_method);
            $esc->twoCol($methodLabel, $this->formatRupiah((int) $payment->amount), self::COLS);

            if ($payment->payment_method === 'cash') {
                $tendered = (int) $payment->tendered_amount;
                $change = (int) $payment->change_amount;
                if ($tendered > 0) {
                    $esc->twoCol('  Tunai Diterima', $this->formatRupiah($tendered), self::COLS);
                }
                if ($change > 0) {
                    $esc->twoCol('  Kembalian', $this->formatRupiah($change), self::COLS);
                }
            }

            if (! empty($payment->reference_number)) {
                $esc->text('  Ref: '.$payment->reference_number);
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve cashier display name with fallback chain.
     * Historical records may have cashier_name=NULL; we fall back gracefully.
     */
    private function resolveCashierName(PaymentTransaction $transaction): string
    {
        if (! empty($transaction->cashier_name)) {
            return $transaction->cashier_name;
        }

        // Try eager-loaded relationship
        if ($transaction->relationLoaded('user') && $transaction->user !== null) {
            return $transaction->user->name;
        }

        // Fresh load (handles non-loaded relationship)
        if ($transaction->user_id !== null) {
            $user = User::withTrashed()->find($transaction->user_id);
            if ($user !== null) {
                return $user->name;
            }
        }

        return 'Kasir Tidak Diketahui';
    }

    private function formatRupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    private function labelMethod(string $method): string
    {
        return match ($method) {
            'cash' => 'Tunai',
            'qris' => 'QRIS',
            'debit' => 'Kartu Debit',
            'credit' => 'Kartu Kredit',
            'transfer' => 'Transfer Bank',
            default => strtoupper($method),
        };
    }
}
