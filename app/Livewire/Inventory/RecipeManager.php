<?php

namespace App\Livewire\Inventory;

use App\Models\Category;
use App\Models\Product;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class RecipeManager extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $selectedCategoryId = null;

    public string $recipeStatusFilter = 'all'; // 'all', 'configured', 'missing'

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedRecipeStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $categories = Category::where('is_active', true)->orderBy('sort_order')->get();

        $query = Product::query()
            ->with([
                'category',
                'translations',
                'variants.translations',
                'variants.recipeItems.ingredient',
                'recipeItems.ingredient',
            ])
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('sku', 'like', '%'.$this->search.'%')
                        ->orWhereHas('translations', function ($t) {
                            $t->where('name', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when($this->selectedCategoryId, function ($q) {
                $q->where('category_id', $this->selectedCategoryId);
            })
            ->when($this->recipeStatusFilter === 'configured', function ($q) {
                $q->has('recipeItems');
            })
            ->when($this->recipeStatusFilter === 'missing', function ($q) {
                $q->doesntHave('recipeItems');
            })
            ->orderBy('sort_order');

        $products = $query->paginate(12);

        $totalProducts = Product::count();
        $configuredCount = Product::has('recipeItems')->count();
        $missingCount = $totalProducts - $configuredCount;

        return view('livewire.inventory.recipe-manager', [
            'products' => $products,
            'categories' => $categories,
            'totalProducts' => $totalProducts,
            'configuredCount' => $configuredCount,
            'missingCount' => $missingCount,
        ])->layout('components.layouts.app', ['title' => __('messages.recipes.page_title')]);
    }
}
