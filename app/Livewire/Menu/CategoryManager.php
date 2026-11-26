<?php

namespace App\Livewire\Menu;

use App\Models\ActivityLog;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class CategoryManager extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showModal = false;

    public ?int $editingCategoryId = null;

    public string $nameId = '';

    public string $nameEn = '';

    public string $slug = '';

    public string $descriptionId = '';

    public string $descriptionEn = '';

    public int $sortOrder = 0;

    public bool $isActive = true;

    public bool $showDeleteModal = false;

    public ?int $deletingCategoryId = null;

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
                Rule::unique('categories', 'slug')->ignore($this->editingCategoryId),
            ],
            'descriptionId' => ['nullable', 'string'],
            'descriptionEn' => ['nullable', 'string'],
            'sortOrder' => ['required', 'integer', 'min:0'],
            'isActive' => ['boolean'],
        ];
    }

    public function updatedNameId(string $value): void
    {
        if (! $this->editingCategoryId && empty($this->slug)) {
            $this->slug = Str::slug($value);
        }
    }

    public function openCreateModal(): void
    {
        $this->reset(['editingCategoryId', 'nameId', 'nameEn', 'slug', 'descriptionId', 'descriptionEn', 'sortOrder', 'isActive']);
        $this->isActive = true;
        $this->sortOrder = (Category::max('sort_order') ?? 0) + 1;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $category = Category::with('translations')->findOrFail($id);
        $this->editingCategoryId = $category->id;
        $this->slug = $category->slug;
        $this->sortOrder = $category->sort_order;
        $this->isActive = $category->is_active;

        $this->nameId = $category->translate('id')?->name ?? '';
        $this->nameEn = $category->translate('en')?->name ?? '';
        $this->descriptionId = $category->translate('id')?->description ?? '';
        $this->descriptionEn = $category->translate('en')?->description ?? '';

        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate();

        $isNew = ! $this->editingCategoryId;
        $category = $this->editingCategoryId
            ? Category::findOrFail($this->editingCategoryId)
            : new Category;

        $category->slug = $this->slug;
        $category->sort_order = $this->sortOrder;
        $category->is_active = $this->isActive;
        $category->save();

        // Save translations
        $category->setTranslation('name', 'id', $this->nameId);
        $category->setTranslation('description', 'id', $this->descriptionId ?: null);

        if (! empty($this->nameEn)) {
            $category->setTranslation('name', 'en', $this->nameEn);
            $category->setTranslation('description', 'en', $this->descriptionEn ?: null);
        }

        ActivityLog::record(
            action: $isNew ? 'category.created' : 'category.updated',
            description: ($isNew ? 'Kategori baru ditambahkan: ' : 'Kategori diperbarui: ').$this->nameId,
            subject: $category,
            properties: ['slug' => $category->slug, 'name_id' => $this->nameId],
            module: 'catalog'
        );

        $this->showModal = false;
        $this->dispatch('notify', [
            'type' => 'success',
            'message' => $isNew ? __('messages.categories.created_notification') : __('messages.categories.updated_notification'),
        ]);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingCategoryId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteCategory(): void
    {
        if (! $this->deletingCategoryId) {
            return;
        }

        $category = Category::withCount('products')->findOrFail($this->deletingCategoryId);
        $name = $category->name ?? $category->slug;

        $category->delete();

        ActivityLog::record(
            action: 'category.deleted',
            description: "Kategori '{$name}' dihapus (soft-delete).",
            subject: $category,
            module: 'catalog'
        );

        $this->showDeleteModal = false;
        $this->deletingCategoryId = null;

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => __('messages.categories.deleted_notification'),
        ]);
    }

    public function toggleActive(int $id): void
    {
        $category = Category::findOrFail($id);
        $category->is_active = ! $category->is_active;
        $category->save();

        $statusText = $category->is_active ? 'diaktifkan' : 'dinonaktifkan';
        ActivityLog::record(
            action: 'category.status_toggled',
            description: "Kategori '{$category->name}' {$statusText}.",
            subject: $category,
            module: 'catalog'
        );

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => $category->is_active
                ? __('messages.categories.activated_notification')
                : __('messages.categories.deactivated_notification'),
        ]);
    }

    public function render(): View
    {
        $categories = Category::query()
            ->with(['translations'])
            ->withCount('products')
            ->when($this->search !== '', function ($q) {
                $q->where('slug', 'like', "%{$this->search}%")
                    ->orWhereHas('translations', function ($tq) {
                        $tq->where('name', 'like', "%{$this->search}%");
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(10);

        return view('livewire.menu.category-manager', [
            'categories' => $categories,
        ])->layout('components.layouts.app', [
            'title' => __('messages.categories.page_title'),
        ]);
    }
}
