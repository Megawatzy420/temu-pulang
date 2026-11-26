<?php

namespace App\Livewire\Menu;

use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\ModifierRecipeItem;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ModifierManager extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showModal = false;

    public ?int $editingGroupId = null;

    public string $nameId = '';

    public string $nameEn = '';

    public string $slug = '';

    public int $minSelections = 0;

    public int $maxSelections = 1;

    public bool $isRequired = false;

    public int $sortOrder = 0;

    public bool $isActive = true;

    /**
     * Nested modifier options repeater.
     *
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public bool $showDeleteModal = false;

    public ?int $deletingGroupId = null;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'nameId' => ['required', 'string', 'max:150'],
            'nameEn' => ['nullable', 'string', 'max:150'],
            'slug' => [
                'required',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('modifier_groups', 'slug')->ignore($this->editingGroupId),
            ],
            'minSelections' => ['required', 'integer', 'min:0'],
            'maxSelections' => ['required', 'integer', 'gte:minSelections'],
            'isRequired' => ['boolean'],
            'sortOrder' => ['required', 'integer', 'min:0'],
            'isActive' => ['boolean'],
            'options' => ['array'],
            'options.*.nameId' => ['required', 'string', 'max:150'],
            'options.*.nameEn' => ['nullable', 'string', 'max:150'],
            'options.*.price' => ['required', 'integer', 'min:0'],
            'options.*.isActive' => ['boolean'],
            'options.*.isSoldOut' => ['boolean'],
        ];
    }

    public function updatedNameId(string $value): void
    {
        if (! $this->editingGroupId && empty($this->slug)) {
            $this->slug = Str::slug($value);
        }
    }

    public function openCreateModal(): void
    {
        $this->reset(['editingGroupId', 'nameId', 'nameEn', 'slug', 'minSelections', 'maxSelections', 'isRequired', 'sortOrder', 'isActive', 'options']);
        $this->isActive = true;
        $this->isRequired = false;
        $this->minSelections = 0;
        $this->maxSelections = 1;
        $this->sortOrder = (ModifierGroup::max('sort_order') ?? 0) + 1;
        $this->options = [
            [
                'id' => null,
                'nameId' => '',
                'nameEn' => '',
                'price' => 0,
                'isActive' => true,
                'isSoldOut' => false,
                'ingredientId' => null,
                'recipeAction' => 'add',
                'replacesIngredientId' => null,
                'quantity' => '0.000',
            ],
        ];
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $group = ModifierGroup::with(['translations', 'modifiers.translations', 'modifiers.modifierRecipeItems'])->findOrFail($id);
        $this->editingGroupId = $group->id;
        $this->slug = $group->slug;
        $this->minSelections = $group->min_selections;
        $this->maxSelections = $group->max_selections;
        $this->isRequired = $group->is_required;
        $this->sortOrder = $group->sort_order;
        $this->isActive = $group->is_active;

        $this->nameId = $group->translate('id')?->name ?? '';
        $this->nameEn = $group->translate('en')?->name ?? '';

        $this->options = [];
        foreach ($group->modifiers as $mod) {
            $recipeItem = $mod->modifierRecipeItems->first();

            $this->options[] = [
                'id' => $mod->id,
                'nameId' => $mod->translate('id')?->name ?? '',
                'nameEn' => $mod->translate('en')?->name ?? '',
                'price' => (int) $mod->price,
                'isActive' => (bool) $mod->is_active,
                'isSoldOut' => (bool) $mod->is_sold_out,
                'ingredientId' => $recipeItem?->ingredient_id,
                'recipeAction' => $recipeItem?->action ?? 'add',
                'replacesIngredientId' => $recipeItem?->replaces_ingredient_id,
                'quantity' => $recipeItem ? (string) $recipeItem->quantity : '0.000',
            ];
        }

        if (empty($this->options)) {
            $this->options[] = [
                'id' => null,
                'nameId' => '',
                'nameEn' => '',
                'price' => 0,
                'isActive' => true,
                'isSoldOut' => false,
                'ingredientId' => null,
                'recipeAction' => 'add',
                'replacesIngredientId' => null,
                'quantity' => '0.000',
            ];
        }

        $this->resetValidation();
        $this->showModal = true;
    }

    public function addOption(): void
    {
        $this->options[] = [
            'id' => null,
            'nameId' => '',
            'nameEn' => '',
            'price' => 0,
            'isActive' => true,
            'isSoldOut' => false,
            'ingredientId' => null,
            'recipeAction' => 'add',
            'replacesIngredientId' => null,
            'quantity' => '0.000',
        ];
    }

    public function removeOption(int $index): void
    {
        if (isset($this->options[$index])) {
            $option = $this->options[$index];
            if (! empty($option['id'])) {
                Modifier::find($option['id'])?->delete();
            }
            unset($this->options[$index]);
            $this->options = array_values($this->options);
        }
    }

    public function save(): void
    {
        $this->validate();

        $isNew = ! $this->editingGroupId;
        $group = $this->editingGroupId
            ? ModifierGroup::findOrFail($this->editingGroupId)
            : new ModifierGroup;

        $group->slug = $this->slug;
        $group->min_selections = $this->minSelections;
        $group->max_selections = $this->maxSelections;
        $group->is_required = $this->isRequired;
        $group->sort_order = $this->sortOrder;
        $group->is_active = $this->isActive;
        $group->save();

        $group->setTranslation('name', 'id', $this->nameId);
        if (! empty($this->nameEn)) {
            $group->setTranslation('name', 'en', $this->nameEn);
        }

        // Save nested options
        foreach ($this->options as $index => $opt) {
            if (empty(trim($opt['nameId']))) {
                continue;
            }

            $mod = ! empty($opt['id'])
                ? Modifier::find($opt['id'])
                : new Modifier(['modifier_group_id' => $group->id]);

            if (! $mod) {
                $mod = new Modifier(['modifier_group_id' => $group->id]);
            }

            $mod->price = (int) $opt['price'];
            $mod->sort_order = $index;
            $mod->is_active = $opt['isActive'] ?? true;
            $mod->is_sold_out = $opt['isSoldOut'] ?? false;
            $mod->save();

            $mod->setTranslation('name', 'id', $opt['nameId']);
            if (! empty($opt['nameEn'])) {
                $mod->setTranslation('name', 'en', $opt['nameEn']);
            }

            // Sync modifier recipe rule if ingredient is specified
            if (! empty($opt['ingredientId']) && (float) ($opt['quantity'] ?? 0) > 0) {
                ModifierRecipeItem::updateOrCreate(
                    [
                        'modifier_id' => $mod->id,
                        'ingredient_id' => (int) $opt['ingredientId'],
                    ],
                    [
                        'action' => $opt['recipeAction'] ?? 'add',
                        'replaces_ingredient_id' => ($opt['recipeAction'] ?? 'add') === 'replace' ? ($opt['replacesIngredientId'] ?? null) : null,
                        'quantity' => number_format((float) $opt['quantity'], 3, '.', ''),
                    ]
                );
            } else {
                ModifierRecipeItem::where('modifier_id', $mod->id)->delete();
            }
        }

        ActivityLog::record(
            action: $isNew ? 'modifier_group.created' : 'modifier_group.updated',
            description: ($isNew ? 'Grup modifier baru ditambahkan: ' : 'Grup modifier diperbarui: ').$this->nameId,
            subject: $group,
            properties: ['slug' => $group->slug, 'name_id' => $this->nameId],
            module: 'catalog'
        );

        $this->showModal = false;
        $this->dispatch('notify', [
            'type' => 'success',
            'message' => $isNew ? __('messages.modifiers.group_created_notification') : __('messages.modifiers.group_updated_notification'),
        ]);
    }

    public function toggleSoldOut(int $modifierId): void
    {
        $mod = Modifier::findOrFail($modifierId);
        $mod->is_sold_out = ! $mod->is_sold_out;
        $mod->save();

        $statusText = $mod->is_sold_out ? 'ditandai HABIS' : 'ditandai TERSEDIA';
        ActivityLog::record(
            action: 'modifier.availability_toggled',
            description: "Opsi modifier '{$mod->name}' {$statusText}.",
            subject: $mod,
            module: 'catalog'
        );

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => $mod->is_sold_out
                ? __('messages.modifiers.option_marked_sold_out_notification')
                : __('messages.modifiers.option_marked_available_notification'),
        ]);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingGroupId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteGroup(): void
    {
        if (! $this->deletingGroupId) {
            return;
        }

        $group = ModifierGroup::findOrFail($this->deletingGroupId);
        $name = $group->name ?? $group->slug;

        $group->delete();

        ActivityLog::record(
            action: 'modifier_group.deleted',
            description: "Grup modifier '{$name}' dihapus (soft-delete).",
            subject: $group,
            module: 'catalog'
        );

        $this->showDeleteModal = false;
        $this->deletingGroupId = null;

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => __('messages.modifiers.group_deleted_notification'),
        ]);
    }

    public function render(): View
    {
        $groups = ModifierGroup::query()
            ->with(['translations', 'modifiers.translations'])
            ->withCount('modifiers')
            ->when($this->search !== '', function ($q) {
                $q->where('slug', 'like', "%{$this->search}%")
                    ->orWhereHas('translations', function ($tq) {
                        $tq->where('name', 'like', "%{$this->search}%");
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(10);

        return view('livewire.menu.modifier-manager', [
            'groups' => $groups,
            'availableIngredients' => Ingredient::where('is_active', true)->orderBy('name')->get(),
        ])->layout('components.layouts.app', [
            'title' => __('messages.modifiers.page_title'),
        ]);
    }
}
