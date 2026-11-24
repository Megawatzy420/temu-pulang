<?php

namespace App\Livewire\Orders;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class OrderIndex extends Component
{
    use WithPagination;

    public string $currentTab = 'active'; // 'active', 'awaiting_payment', 'queued', 'preparing', 'ready', 'completed', 'cancelled', 'all'

    public string $search = '';

    public string $orderTypeFilter = 'all'; // 'all', 'dine_in', 'takeaway'

    // Cancellation modal state
    public bool $showCancelModal = false;

    public ?int $cancellingOrderId = null;

    public string $cancellationReason = '';

    // Order detail modal
    public bool $showDetailModal = false;

    public ?int $selectedOrderId = null;

    protected $queryString = [
        'currentTab' => ['except' => 'active'],
        'search' => ['except' => ''],
    ];

    public function setTab(string $tab): void
    {
        $this->currentTab = $tab;
        $this->resetPage();
    }

    public function advanceStatus(int $orderId, string $targetStatus): void
    {
        $order = Order::findOrFail($orderId);
        $orderService = app(OrderService::class);

        try {
            $orderService->advanceStatus($order, $targetStatus, Auth::user());
            $this->dispatch('notify', message: "Status pesanan {$order->call_number} berhasil diperbarui ke {$targetStatus}.", type: 'success');
        } catch (\Exception $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');
        }
    }

    public function openCancelModal(int $orderId): void
    {
        if (! Auth::user()->isOwner()) {
            $this->dispatch('notify', message: 'Hanya Pemilik (Owner) yang dapat membatalkan pesanan.', type: 'error');

            return;
        }

        $this->cancellingOrderId = $orderId;
        $this->cancellationReason = '';
        $this->showCancelModal = true;
    }

    public function confirmCancel(): void
    {
        if (! Auth::user()->isOwner()) {
            $this->dispatch('notify', message: 'Hanya Pemilik (Owner) yang dapat membatalkan pesanan.', type: 'error');

            return;
        }

        if (trim($this->cancellationReason) === '') {
            $this->dispatch('notify', message: 'Alasan pembatalan wajib diisi.', type: 'error');

            return;
        }

        $order = Order::findOrFail($this->cancellingOrderId);
        $orderService = app(OrderService::class);

        try {
            $orderService->cancelOrder($order, Auth::user(), $this->cancellationReason);
            $this->dispatch('notify', message: "Pesanan {$order->call_number} berhasil dibatalkan.", type: 'warning');
            $this->showCancelModal = false;
            $this->cancellingOrderId = null;
            $this->cancellationReason = '';
        } catch (\Exception $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');
        }
    }

    public function openDetailModal(int $orderId): void
    {
        $this->selectedOrderId = $orderId;
        $this->showDetailModal = true;
    }

    public function render()
    {
        $query = Order::with(['items.modifiers', 'user', 'shift'])
            ->latest('id');

        // Tab filtering
        if ($this->currentTab === 'active') {
            $query->whereIn('status', [Order::STATUS_QUEUED, Order::STATUS_PREPARING, Order::STATUS_READY]);
        } elseif ($this->currentTab !== 'all') {
            $query->where('status', $this->currentTab);
        }

        // Dining mode filter
        if ($this->orderTypeFilter !== 'all') {
            $query->where('order_type', $this->orderTypeFilter);
        }

        // Search filter
        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('order_number', 'like', $term)
                    ->orWhere('call_number', 'like', $term)
                    ->orWhere('customer_name', 'like', $term);
            });
        }

        $orders = $query->paginate(12);

        $selectedOrder = $this->selectedOrderId
            ? Order::with(['items.modifiers', 'statusHistories.user', 'user', 'cancelledByUser'])->find($this->selectedOrderId)
            : null;

        // Count stats for queue board badges
        $stats = [
            'awaiting_payment' => Order::where('status', Order::STATUS_AWAITING_PAYMENT)->count(),
            'queued' => Order::where('status', Order::STATUS_QUEUED)->count(),
            'preparing' => Order::where('status', Order::STATUS_PREPARING)->count(),
            'ready' => Order::where('status', Order::STATUS_READY)->count(),
            'active_total' => Order::whereIn('status', [Order::STATUS_QUEUED, Order::STATUS_PREPARING, Order::STATUS_READY])->count(),
        ];

        return view('livewire.orders.order-index', [
            'orders' => $orders,
            'selectedOrder' => $selectedOrder,
            'stats' => $stats,
        ])->layout('components.layouts.app', [
            'title' => 'Antrean & Pesanan',
            'headerTitle' => 'Manajemen Pesanan & Antrean Dapur',
            'headerSubtitle' => 'Pantau status pesanan, antrean kitchen, dan riwayat pesanan hari ini',
        ]);
    }
}
