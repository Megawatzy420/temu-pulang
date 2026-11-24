<?php

namespace App\Services\Printing;

use App\Models\Order;

/**
 * Renders a kitchen ticket as an ESC/POS byte stream.
 * Column width: 48 characters (80mm paper).
 * Kitchen tickets show no prices, only items and instructions.
 */
class KitchenTicketRendererService
{
    private const COLS = 48;

    public function render(Order $order): string
    {
        $esc = new EscPosBuilder;
        $esc->initialize();

        // Header
        $esc->alignCenter();
        $esc->bold(true);
        $esc->rule(self::COLS, '*');
        $esc->text('PESANAN DAPUR / BAR');
        $esc->rule(self::COLS, '*');
        $esc->bold(false);
        $esc->alignLeft();

        // Order info
        $esc->bold(true);
        $esc->text('No: '.$order->order_number);
        $esc->bold(false);

        $orderType = $order->order_type === Order::TYPE_DINE_IN ? 'Dine-in' : 'Takeaway';
        $esc->twoCol('Jenis:', $orderType, self::COLS);

        $paidAt = $order->paid_at
            ? $order->paid_at->setTimezone('Asia/Jakarta')->format('H:i:s')
            : now('Asia/Jakarta')->format('H:i:s');
        $esc->twoCol('Waktu:', $paidAt, self::COLS);

        $esc->rule(self::COLS);

        // Items (no prices)
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->with('modifiers')->get();

        foreach ($items as $item) {
            $qty = (int) $item->quantity;
            $productName = $item->product_name ?? 'Item';
            $variantName = $item->variant_name ? " ({$item->variant_name})" : '';

            $esc->bold(true);
            $esc->text("{$qty}x {$productName}{$variantName}");
            $esc->bold(false);

            $modifiers = $item->relationLoaded('modifiers') ? $item->modifiers : $item->modifiers()->get();
            foreach ($modifiers as $modifier) {
                $esc->text('  + '.($modifier->modifier_name ?? ''));
            }

            $notes = $item->special_instructions ?? $item->notes ?? null;
            if (! empty($notes)) {
                $esc->text('  ** '.$notes.' **');
            }
        }

        $esc->rule(self::COLS);

        // Order notes
        if (! empty($order->notes)) {
            $esc->bold(true);
            $esc->text('Catatan Pesanan:');
            $esc->bold(false);
            $esc->text($order->notes);
        }

        $esc->feed(5);

        return $esc->getBytes();
    }
}
