<?php

namespace App\Livewire\Menu;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\VariantTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProductForm extends Component
{
    use WithFileUploads;

    public ?int $productId = null;

    // Basic & Status
    public ?int $categoryId = null;

    public string $sku = '';

    public int $basePrice = 0;

    public bool $hasVariants = false;

    public bool $isActive = true;

    public bool $isSoldOut = false;

    public bool $dineInAvailable = true;

    public bool $takeawayAvailable = true;

    public int $sortOrder = 0;

    // Image
    public $image;

    public ?string $existingImagePath = null;

    // Bilingual Content
    public string $nameId = '';

    public string $nameEn = '';

    public string $descriptionId = '';

    public string $descriptionEn = '';

    // Variants Repeater
    /**
     * @var array<int, array{id: ?int, sku: string, name_id: string, name_en: string, price: int, is_active: bool, is_sold_out: bool, sort_order: int}>
     */
    public array $variants = [];

    // Modifier Groups Selection
    /**
     * @var array<int, int>
     */
    public array $selectedModifierGroupIds = [];

    // Recipe (Bill of Materials) Items
    /**
     * @var array<int, array{id: ?int, ingredient_id: ?int, quantity: string}>
     */
    public array $baseRecipeItems = [];

    /**
     * @var array<int, array<int, array{id: ?int, ingredient_id: ?int, quantity: string}>>
     */
    public array $variantRecipeItems = [];

    // Tab state: 'basic', 'content', 'variants', 'modifiers', 'recipe'
    public string $activeTab = 'basic';

    public function mount(?int $id = null): void
    {
        $this->productId = $id;

        if ($this->productId) {
            $product = Product::with(['translations', 'variants.translations', 'modifierGroups', 'recipeItems.ingredient'])->findOrFail($this->productId);

            $this->categoryId = $product->category_id;
            $this->sku = $product->sku ?? '';
            $this->basePrice = (int) $product->base_price;
            $this->hasVariants = (bool) $product->has_variants;
            $this->isActive = (bool) $product->is_active;
            $this->isSoldOut = (bool) $product->is_sold_out;
            $this->dineInAvailable = (bool) $product->dine_in_available;
            $this->takeawayAvailable = (bool) $product->takeaway_available;
            $this->sortOrder = (int) $product->sort_order;
            $this->existingImagePath = $product->image_path;

            // Translations
            $this->nameId = $product->translations->firstWhere('locale', 'id')?->name ?? '';
            $this->nameEn = $product->translations->firstWhere('locale', 'en')?->name ?? '';
            $this->descriptionId = $product->translations->firstWhere('locale', 'id')?->description ?? '';
            $this->descriptionEn = $product->translations->firstWhere('locale', 'en')?->description ?? '';

            // Variants
            foreach ($product->variants as $variant) {
                $this->variants[] = [
                    'id' => $variant->id,
                    'sku' => $variant->sku ?? '',
                    'name_id' => $variant->translations->firstWhere('locale', 'id')?->name ?? '',
                    'name_en' => $variant->translations->firstWhere('locale', 'en')?->name ?? '',
                    'price' => (int) $variant->price,
                    'is_active' => (bool) $variant->is_active,
                    'is_sold_out' => (bool) $variant->is_sold_out,
                    'sort_order' => (int) $variant->sort_order,
                ];
            }

            // Modifier Groups
            $this->selectedModifierGroupIds = $product->modifierGroups->pluck('id')->all();

            // Recipe Items (BOM)
            $baseItems = $product->recipeItems->whereNull('product_variant_id');
            foreach ($baseItems as $bItem) {
                $this->baseRecipeItems[] = [
                    'id' => $bItem->id,
                    'ingredient_id' => $bItem->ingredient_id,
                    'quantity' => (string) $bItem->quantity,
                ];
            }

            foreach ($this->variants as $vIdx => $vData) {
                $vItems = [];
                if (! empty($vData['id'])) {
                    $variantItems = $product->recipeItems->where('product_variant_id', $vData['id']);
                    foreach ($variantItems as $vItem) {
                        $vItems[] = [
                            'id' => $vItem->id,
                            'ingredient_id' => $vItem->ingredient_id,
                            'quantity' => (string) $vItem->quantity,
                        ];
                    }
                }
                $this->variantRecipeItems[$vIdx] = $vItems;
            }
        } else {
            // New product defaults
            $this->categoryId = Category::where('is_active', true)->orderBy('sort_order')->value('id');
            $this->addVariant(); // 1 default variant row ready if toggled
        }
    }

    public function addBaseRecipeItem(): void
    {
        $this->baseRecipeItems[] = [
            'id' => null,
            'ingredient_id' => null,
            'quantity' => '1.000',
        ];
    }

    public function removeBaseRecipeItem(int $index): void
    {
        if (isset($this->baseRecipeItems[$index])) {
            unset($this->baseRecipeItems[$index]);
            $this->baseRecipeItems = array_values($this->baseRecipeItems);
        }
    }

    public function addVariantRecipeItem(int $variantIndex): void
    {
        if (! isset($this->variantRecipeItems[$variantIndex])) {
            $this->variantRecipeItems[$variantIndex] = [];
        }

        $this->variantRecipeItems[$variantIndex][] = [
            'id' => null,
            'ingredient_id' => null,
            'quantity' => '1.000',
        ];
    }

    public function removeVariantRecipeItem(int $variantIndex, int $itemIndex): void
    {
        if (isset($this->variantRecipeItems[$variantIndex][$itemIndex])) {
            unset($this->variantRecipeItems[$variantIndex][$itemIndex]);
            $this->variantRecipeItems[$variantIndex] = array_values($this->variantRecipeItems[$variantIndex]);
        }
    }

    public function addVariant(): void
    {
        $this->variants[] = [
            'id' => null,
            'sku' => '',
            'name_id' => '',
            'name_en' => '',
            'price' => $this->basePrice > 0 ? $this->basePrice : 0,
            'is_active' => true,
            'is_sold_out' => false,
            'sort_order' => count($this->variants),
        ];
    }

    public function removeVariant(int $index): void
    {
        if (isset($this->variants[$index])) {
            unset($this->variants[$index]);
            $this->variants = array_values($this->variants);
        }
    }

    public function rules(): array
    {
        $rules = [
            'categoryId' => ['required', 'exists:categories,id'],
            'sku' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'sku')->ignore($this->productId),
            ],
            'basePrice' => ['required', 'integer', 'min:0'],
            'hasVariants' => ['boolean'],
            'isActive' => ['boolean'],
            'isSoldOut' => ['boolean'],
            'dineInAvailable' => ['boolean'],
            'takeawayAvailable' => ['boolean'],
            'sortOrder' => ['required', 'integer', 'min:0'],
            'nameId' => ['required', 'string', 'max:255'],
            'nameEn' => ['nullable', 'string', 'max:255'],
            'descriptionId' => ['nullable', 'string', 'max:1000'],
            'descriptionEn' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'max:2048'], // max 2MB
            'selectedModifierGroupIds' => ['array'],
            'selectedModifierGroupIds.*' => ['exists:modifier_groups,id'],
        ];

        if ($this->hasVariants) {
            $rules['variants'] = ['required', 'array', 'min:1'];
            $rules['variants.*.name_id'] = ['required', 'string', 'max:255'];
            $rules['variants.*.name_en'] = ['nullable', 'string', 'max:255'];
            $rules['variants.*.price'] = ['required', 'integer', 'min:0'];
            $rules['variants.*.sku'] = ['nullable', 'string', 'max:50'];
        }

        return $rules;
    }

    public function save()
    {
        $this->validate();

        DB::transaction(function () {
            $imagePath = $this->existingImagePath;

            if ($this->image) {
                // Delete old image if exists
                if ($imagePath && Storage::disk('public')->exists($imagePath)) {
                    Storage::disk('public')->delete($imagePath);
                }
                $imagePath = $this->image->store('products', 'public');
            }

            $product = Product::updateOrCreate(
                ['id' => $this->productId],
                [
                    'category_id' => $this->categoryId,
                    'sku' => $this->sku ?: null,
                    'base_price' => $this->basePrice,
                    'image_path' => $imagePath,
                    'has_variants' => $this->hasVariants,
                    'is_active' => $this->isActive,
                    'is_sold_out' => $this->isSoldOut,
                    'dine_in_available' => $this->dineInAvailable,
                    'takeaway_available' => $this->takeawayAvailable,
                    'sort_order' => $this->sortOrder,
                ]
            );

            // Translations
            ProductTranslation::updateOrCreate(
                ['product_id' => $product->id, 'locale' => 'id'],
                ['name' => $this->nameId, 'description' => $this->descriptionId ?: null]
            );

            ProductTranslation::updateOrCreate(
                ['product_id' => $product->id, 'locale' => 'en'],
                ['name' => $this->nameEn ?: $this->nameId, 'description' => $this->descriptionEn ?: null]
            );

            // Handle Variants
            if ($this->hasVariants) {
                $keptVariantIds = [];
                foreach ($this->variants as $idx => $vData) {
                    $variant = ProductVariant::updateOrCreate(
                        ['id' => $vData['id'] ?? null, 'product_id' => $product->id],
                        [
                            'sku' => ! empty($vData['sku']) ? $vData['sku'] : null,
                            'price' => (int) $vData['price'],
                            'is_active' => $vData['is_active'] ?? true,
                            'is_sold_out' => $vData['is_sold_out'] ?? false,
                            'sort_order' => $idx,
                        ]
                    );

                    $keptVariantIds[] = $variant->id;

                    VariantTranslation::updateOrCreate(
                        ['product_variant_id' => $variant->id, 'locale' => 'id'],
                        ['name' => $vData['name_id']]
                    );

                    VariantTranslation::updateOrCreate(
                        ['product_variant_id' => $variant->id, 'locale' => 'en'],
                        ['name' => ! empty($vData['name_en']) ? $vData['name_en'] : $vData['name_id']]
                    );
                }

                // Delete variants removed by user
                ProductVariant::where('product_id', $product->id)
                    ->whereNotIn('id', $keptVariantIds)
                    ->delete();
            } else {
                // If variants toggled off, soft delete existing variants
                ProductVariant::where('product_id', $product->id)->delete();
            }

            // Sync Modifier Groups
            $syncData = [];
            foreach ($this->selectedModifierGroupIds as $sort => $groupId) {
                $syncData[$groupId] = ['sort_order' => $sort];
            }
            $product->modifierGroups()->sync($syncData);

            // Sync Recipe Items (Bill of Materials)
            $keptRecipeItemIds = [];

            // 1. Base recipe items
            foreach ($this->baseRecipeItems as $bItem) {
                if (! empty($bItem['ingredient_id']) && (float) ($bItem['quantity'] ?? 0) > 0) {
                    $qty = number_format((float) $bItem['quantity'], 3, '.', '');
                    $recipeItem = RecipeItem::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'product_variant_id' => null,
                            'ingredient_id' => (int) $bItem['ingredient_id'],
                        ],
                        [
                            'quantity' => $qty,
                        ]
                    );
                    $keptRecipeItemIds[] = $recipeItem->id;
                }
            }

            // 2. Variant recipe items (if has variants)
            if ($this->hasVariants) {
                foreach ($this->variants as $idx => $vData) {
                    $variantId = $keptVariantIds[$idx] ?? null;
                    if ($variantId && isset($this->variantRecipeItems[$idx])) {
                        foreach ($this->variantRecipeItems[$idx] as $vItem) {
                            if (! empty($vItem['ingredient_id']) && (float) ($vItem['quantity'] ?? 0) > 0) {
                                $qty = number_format((float) $vItem['quantity'], 3, '.', '');
                                $recipeItem = RecipeItem::updateOrCreate(
                                    [
                                        'product_id' => $product->id,
                                        'product_variant_id' => $variantId,
                                        'ingredient_id' => (int) $vItem['ingredient_id'],
                                    ],
                                    [
                                        'quantity' => $qty,
                                    ]
                                );
                                $keptRecipeItemIds[] = $recipeItem->id;
                            }
                        }
                    }
                }
            }

            // Remove any recipe items that were deleted
            RecipeItem::where('product_id', $product->id)
                ->whereNotIn('id', $keptRecipeItemIds)
                ->delete();
        });

        session()->flash('message', $this->productId ? 'Produk berhasil diperbarui.' : 'Produk baru berhasil ditambahkan.');

        return redirect()->route('menu.products');
    }

    public function render()
    {
        return view('livewire.menu.product-form', [
            'categories' => Category::where('is_active', true)->orderBy('sort_order')->get(),
            'availableModifierGroups' => ModifierGroup::with('translations')->where('is_active', true)->orderBy('sort_order')->get(),
            'availableIngredients' => Ingredient::where('is_active', true)->orderBy('name')->get(),
        ])->layout('components.layouts.app', ['title' => $this->productId ? 'Edit Produk' : 'Tambah Produk']);
    }
}
