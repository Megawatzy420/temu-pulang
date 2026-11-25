<?php

namespace App\Livewire\Inventory;

use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientCategory;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class IngredientManager extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';

    public ?int $selectedCategoryId = null;

    public bool $filterLowStock = false;

    #[Url(as: 'stock', except: 'all')]
    public string $stockFilter = 'all'; // 'all', 'low_or_out', 'out'

    public string $activeSection = 'ingredients'; // 'ingredients' or 'categories'

    // Ingredient Form Modal
    public bool $showIngredientModal = false;

    public ?int $editingIngredientId = null;

    public ?int $categoryId = null;

    public string $sku = '';

    public string $name = '';

    public string $unit = 'g';

    public int $costPerUnit = 0;

    public string $minStockAlert = '0.000';

    public bool $isActive = true;

    public string $initialStock = '0.000'; // Only on create

    // Delete Ingredient Modal
    public bool $showDeleteIngredientModal = false;

    public ?int $deletingIngredientId = null;

    public string $deletingIngredientName = '';

    // Category Form Modal
    public bool $showCategoryModal = false;

    public ?int $editingCategoryId = null;

    public string $categoryName = '';

    public string $categorySlug = '';

    public int $categorySortOrder = 0;

    // Delete Category Modal
    public bool $showDeleteCategoryModal = false;

    public ?int $deletingCategoryId = null;

    public string $deletingCategoryName = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'categoryId' => ['nullable', 'exists:ingredient_categories,id'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ingredients', 'sku')->ignore($this->editingIngredientId),
            ],
            'name' => ['required', 'string', 'max:200'],
            'unit' => ['required', 'string', 'max:20'],
            'costPerUnit' => ['required', 'integer', 'min:0'],
            'minStockAlert' => ['required', 'numeric', 'min:0'],
            'isActive' => ['boolean'],
            'initialStock' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedFilterLowStock(): void
    {
        $this->resetPage();
    }

    public function updatedStockFilter(): void
    {
        $this->resetPage();
    }

    // -------------------------------------------------------------------------
    // Ingredient CRUD Operations
    // -------------------------------------------------------------------------

    public function openCreateIngredientModal(): void
    {
        $this->reset([
            'editingIngredientId',
            'categoryId',
            'sku',
            'name',
            'costPerUnit',
            'minStockAlert',
            'initialStock',
        ]);

        $this->unit = 'g';
        $this->isActive = true;
        $this->minStockAlert = '0.000';
        $this->initialStock = '0.000';
        $this->sku = 'ING-'.strtoupper(Str::random(6));

        $this->resetValidation();
        $this->showIngredientModal = true;
    }

    public function openEditIngredientModal(int $id): void
    {
        $ingredient = Ingredient::with('balances')->findOrFail($id);

        $this->editingIngredientId = $ingredient->id;
        $this->categoryId = $ingredient->category_id;
        $this->sku = $ingredient->sku;
        $this->name = $ingredient->name;
        $this->unit = $ingredient->unit;
        $this->costPerUnit = (int) $ingredient->cost_per_unit;
        $this->minStockAlert = (string) $ingredient->min_stock_alert;
        $this->isActive = (bool) $ingredient->is_active;
        $this->initialStock = '0.000';

        $this->resetValidation();
        $this->showIngredientModal = true;
    }

    public function saveIngredient(): void
    {
        $this->validate();

        $formattedMinStock = number_format((float) $this->minStockAlert, 3, '.', '');
        $isNew = ! $this->editingIngredientId;

        DB::transaction(function () use ($isNew, $formattedMinStock) {
            $ingredient = Ingredient::updateOrCreate(
                ['id' => $this->editingIngredientId],
                [
                    'category_id' => $this->categoryId,
                    'sku' => $this->sku,
                    'name' => $this->name,
                    'unit' => $this->unit,
                    'cost_per_unit' => $this->costPerUnit,
                    'min_stock_alert' => $formattedMinStock,
                    'is_active' => $this->isActive,
                ]
            );

            // Ensure IngredientBalance row exists for default branch (branch_id = null)
            $balance = IngredientBalance::firstOrCreate(
                ['branch_id' => null, 'ingredient_id' => $ingredient->id],
                ['current_stock' => '0.000']
            );

            // If creating new ingredient with opening stock > 0
            if ($isNew && (float) $this->initialStock > 0) {
                $formattedInitialStock = number_format((float) $this->initialStock, 3, '.', '');
                $balance->current_stock = $formattedInitialStock;
                $balance->save();

                StockMovement::create([
                    'ulid' => (string) Str::ulid(),
                    'branch_id' => null,
                    'ingredient_id' => $ingredient->id,
                    'user_id' => auth()->id() ?? 1,
                    'movement_type' => StockMovement::TYPE_OPENING_STOCK,
                    'quantity' => $formattedInitialStock,
                    'balance_before' => '0.000',
                    'balance_after' => $formattedInitialStock,
                    'unit_cost' => $this->costPerUnit,
                    'notes' => 'Stok awal saat pendaftaran bahan baku baru.',
                ]);
            }

            ActivityLog::record(
                action: $isNew ? 'ingredient.created' : 'ingredient.updated',
                description: ($isNew ? 'Bahan baku baru ditambahkan: ' : 'Bahan baku diperbarui: ').$ingredient->name,
                subject: $ingredient,
                module: 'inventory'
            );
        });

        $this->showIngredientModal = false;
        session()->flash('message', $isNew ? __('messages.ingredients.flash_ingredient_created') : __('messages.ingredients.flash_ingredient_updated'));
    }

    public function toggleIngredientStatus(int $id): void
    {
        $ingredient = Ingredient::findOrFail($id);
        $ingredient->is_active = ! $ingredient->is_active;
        $ingredient->save();

        $statusText = $ingredient->is_active ? 'diaktifkan' : 'dinonaktifkan';

        ActivityLog::record(
            action: 'ingredient.status_toggled',
            description: "Bahan baku '{$ingredient->name}' {$statusText}.",
            subject: $ingredient,
            module: 'inventory'
        );

        session()->flash('message', $ingredient->is_active ? __('messages.ingredients.flash_status_activated') : __('messages.ingredients.flash_status_deactivated'));
    }

    public function confirmDeleteIngredient(int $id): void
    {
        $ingredient = Ingredient::findOrFail($id);
        $this->deletingIngredientId = $ingredient->id;
        $this->deletingIngredientName = $ingredient->name;
        $this->showDeleteIngredientModal = true;
    }

    public function deleteIngredient(): void
    {
        if ($this->deletingIngredientId) {
            $ingredient = Ingredient::findOrFail($this->deletingIngredientId);
            $name = $ingredient->name;
            $ingredient->delete();

            ActivityLog::record(
                action: 'ingredient.deleted',
                description: "Bahan baku '{$name}' dihapus (soft-delete).",
                subject: $ingredient,
                module: 'inventory'
            );

            session()->flash('message', __('messages.ingredients.flash_ingredient_deleted', ['name' => $name]));
        }

        $this->showDeleteIngredientModal = false;
        $this->reset(['deletingIngredientId', 'deletingIngredientName']);
    }

    // -------------------------------------------------------------------------
    // Category CRUD Operations
    // -------------------------------------------------------------------------

    public function openCreateCategoryModal(): void
    {
        $this->reset(['editingCategoryId', 'categoryName', 'categorySlug']);
        $this->categorySortOrder = (IngredientCategory::max('sort_order') ?? 0) + 1;
        $this->resetValidation();
        $this->showCategoryModal = true;
    }

    public function updatedCategoryName(string $value): void
    {
        if (! $this->editingCategoryId && empty($this->categorySlug)) {
            $this->categorySlug = Str::slug($value);
        }
    }

    public function openEditCategoryModal(int $id): void
    {
        $category = IngredientCategory::findOrFail($id);
        $this->editingCategoryId = $category->id;
        $this->categoryName = $category->name;
        $this->categorySlug = $category->slug;
        $this->categorySortOrder = (int) $category->sort_order;
        $this->resetValidation();
        $this->showCategoryModal = true;
    }

    public function saveCategory(): void
    {
        $this->validate([
            'categoryName' => ['required', 'string', 'max:100'],
            'categorySlug' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ingredient_categories', 'slug')->ignore($this->editingCategoryId),
            ],
            'categorySortOrder' => ['required', 'integer', 'min:0'],
        ]);

        $isNew = ! $this->editingCategoryId;

        $category = IngredientCategory::updateOrCreate(
            ['id' => $this->editingCategoryId],
            [
                'name' => $this->categoryName,
                'slug' => $this->categorySlug,
                'sort_order' => $this->categorySortOrder,
            ]
        );

        ActivityLog::record(
            action: $isNew ? 'ingredient_category.created' : 'ingredient_category.updated',
            description: ($isNew ? 'Kategori bahan baru ditambahkan: ' : 'Kategori bahan diperbarui: ').$category->name,
            subject: $category,
            module: 'inventory'
        );

        $this->showCategoryModal = false;
        session()->flash('message', $isNew ? __('messages.ingredients.flash_category_created') : __('messages.ingredients.flash_category_updated'));
    }

    public function confirmDeleteCategory(int $id): void
    {
        $category = IngredientCategory::withCount('ingredients')->findOrFail($id);
        $this->deletingCategoryId = $category->id;
        $this->deletingCategoryName = $category->name;
        $this->showDeleteCategoryModal = true;
    }

    public function deleteCategory(): void
    {
        if ($this->deletingCategoryId) {
            $category = IngredientCategory::findOrFail($this->deletingCategoryId);
            $name = $category->name;
            $category->delete();

            ActivityLog::record(
                action: 'ingredient_category.deleted',
                description: "Kategori bahan '{$name}' dihapus.",
                subject: $category,
                module: 'inventory'
            );

            session()->flash('message', __('messages.ingredients.flash_category_deleted', ['name' => $name]));
        }

        $this->showDeleteCategoryModal = false;
        $this->reset(['deletingCategoryId', 'deletingCategoryName']);
    }

    public function render(): View
    {
        $categories = IngredientCategory::ordered()->withCount('ingredients')->get();

        $ingredientsQuery = Ingredient::query()
            ->select('ingredients.*')
            ->with(['category', 'balances'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('ingredients.name', 'like', '%'.$this->search.'%')
                        ->orWhere('ingredients.sku', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->selectedCategoryId, function ($query) {
                $query->where('ingredients.category_id', $this->selectedCategoryId);
            })
            ->when(in_array($this->stockFilter, ['low_or_out', 'out'], true), function ($query) {
                $query->leftJoin('ingredient_balances', function ($join) {
                    $join->on('ingredient_balances.ingredient_id', '=', 'ingredients.id')
                        ->whereNull('ingredient_balances.branch_id');
                })
                    ->where('ingredients.is_active', true)
                    ->where('ingredients.min_stock_alert', '>', 0);

                if ($this->stockFilter === 'out') {
                    $query->whereRaw('COALESCE(ingredient_balances.current_stock, 0) <= 0');
                } else {
                    $query->whereRaw('COALESCE(ingredient_balances.current_stock, 0) <= ingredients.min_stock_alert');
                }
            })
            ->orderBy('ingredients.name');

        $ingredients = $ingredientsQuery->paginate(15);

        return view('livewire.inventory.ingredient-manager', [
            'ingredients' => $ingredients,
            'categories' => $categories,
        ])->layout('components.layouts.app', ['title' => __('messages.ingredients.page_title')]);
    }
}
