<?php

namespace App\Livewire\Menu;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ProductIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $selectedCategory = 'all';

    public string $filterAvailability = 'all'; // all, available, sold_out

    public bool $showDeleteModal = false;

    public ?int $deletingProductId = null;

    public function toggleSoldOut(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $product->is_sold_out = ! $product->is_sold_out;
        $product->save();

        $statusText = $product->is_sold_out ? 'HABIS (Sold Out)' : 'TERSEDIA';
        ActivityLog::record(
            action: 'product.availability_toggled',
            description: "Status produk '{$product->name}' diubah menjadi {$statusText}.",
            subject: $product,
            module: 'catalog'
        );

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => "Status produk berhasil diubah menjadi {$statusText}.",
        ]);
    }

    public function toggleActive(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $product->is_active = ! $product->is_active;
        $product->save();

        $statusText = $product->is_active ? 'diaktifkan' : 'dinonaktifkan';
        ActivityLog::record(
            action: 'product.status_toggled',
            description: "Produk '{$product->name}' {$statusText}.",
            subject: $product,
            module: 'catalog'
        );

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => "Produk berhasil {$statusText}.",
        ]);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingProductId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteProduct(): void
    {
        if (! $this->deletingProductId) {
            return;
        }

        $product = Product::findOrFail($this->deletingProductId);
        $name = $product->name ?? $product->sku;

        $product->delete();

        ActivityLog::record(
            action: 'product.deleted',
            description: "Produk '{$name}' dihapus (soft-delete).",
            subject: $product,
            module: 'catalog'
        );

        $this->showDeleteModal = false;
        $this->deletingProductId = null;

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Produk berhasil dihapus.',
        ]);
    }

    public function render(): View
    {
        $categories = Category::with('translations')->orderBy('sort_order')->get();

        $products = Product::query()
            ->with(['category.translations', 'translations', 'variants.translations', 'modifierGroups'])
            ->when($this->selectedCategory !== 'all', function ($q) {
                $q->whereHas('category', fn ($cq) => $cq->where('slug', $this->selectedCategory));
            })
            ->when($this->filterAvailability === 'available', fn ($q) => $q->where('is_sold_out', false))
            ->when($this->filterAvailability === 'sold_out', fn ($q) => $q->where('is_sold_out', true))
            ->when($this->search !== '', function ($q) {
                $q->where('sku', 'like', "%{$this->search}%")
                    ->orWhereHas('translations', fn ($tq) => $tq->where('name', 'like', "%{$this->search}%"));
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(12);

        return view('livewire.menu.product-index', [
            'products' => $products,
            'categories' => $categories,
        ])->layout('components.layouts.app', [
            'title' => 'Daftar Menu & Produk',
        ]);
    }
}
