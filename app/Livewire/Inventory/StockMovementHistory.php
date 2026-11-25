<?php

namespace App\Livewire\Inventory;

use App\Models\Ingredient;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class StockMovementHistory extends Component
{
    use WithPagination;

    public const MOVEMENT_TYPES = [
        StockMovement::TYPE_STOCK_IN,
        StockMovement::TYPE_ADJUSTMENT,
        StockMovement::TYPE_ORDER_DEDUCTION,
        StockMovement::TYPE_REFUND_CREDIT,
        StockMovement::TYPE_WASTAGE,
        StockMovement::TYPE_OPENING_STOCK,
    ];

    #[Url(as: 'ingredient', except: '')]
    public string $ingredientId = '';

    #[Url(as: 'type', except: 'all')]
    public string $movementType = 'all';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'actor', except: 'all')]
    public string $actorId = 'all';

    public string $ingredientSearch = '';

    public function mount(): void
    {
        if (! Auth::user()?->isOwner()) {
            abort(403);
        }
    }

    public function updatingIngredientId(): void
    {
        $this->resetPage();
    }

    public function updatingMovementType(): void
    {
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->resetPage();
    }

    public function updatingActorId(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->ingredientId = '';
        $this->movementType = 'all';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->actorId = 'all';
        $this->ingredientSearch = '';
        $this->resetPage();
    }

    private function parseValidDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $trimmed, 'Asia/Jakarta');
            if ($parsed && $parsed->format('Y-m-d') === $trimmed) {
                return $trimmed;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function render(): View
    {
        if (! Auth::user()?->isOwner()) {
            abort(403);
        }

        // Sanitize filter inputs gracefully (never 500 on invalid URL/query values)
        $validIngredient = ctype_digit(trim($this->ingredientId))
            ? Ingredient::withTrashed()->find((int) trim($this->ingredientId))
            : null;

        $validType = in_array($this->movementType, self::MOVEMENT_TYPES, true)
            ? $this->movementType
            : null;

        $validFrom = $this->parseValidDate($this->dateFrom);
        $validTo = $this->parseValidDate($this->dateTo);

        $validActorId = ctype_digit(trim($this->actorId))
            ? (int) trim($this->actorId)
            : null;

        $baseQuery = StockMovement::query()
            ->when($validIngredient !== null, static fn (Builder $q) => $q->where('ingredient_id', $validIngredient->id))
            ->when($validType !== null, static fn (Builder $q) => $q->where('movement_type', $validType))
            ->when($validFrom !== null, static fn (Builder $q) => $q->whereDate('created_at', '>=', $validFrom))
            ->when($validTo !== null, static fn (Builder $q) => $q->whereDate('created_at', '<=', $validTo))
            ->when($validActorId !== null, static fn (Builder $q) => $q->where('user_id', $validActorId));

        // Summary totals ONLY when exactly one valid ingredient is selected (exact milli-unit arithmetic)
        $summary = null;
        if ($validIngredient !== null) {
            $inMilli = 0;
            $outMilli = 0;

            foreach ((clone $baseQuery)->pluck('quantity') as $qty) {
                $m = Ingredient::toMilli((string) $qty);
                if ($m > 0) {
                    $inMilli += $m;
                } elseif ($m < 0) {
                    $outMilli += $m;
                }
            }

            $netMilli = $inMilli + $outMilli;
            $summary = [
                'unit' => $validIngredient->unit,
                'total_in' => Ingredient::fromMilli($inMilli),
                'total_out' => Ingredient::fromMilli(abs($outMilli)),
                'net' => ($netMilli > 0 ? '+' : '').Ingredient::fromMilli($netMilli),
            ];
        }

        $movements = (clone $baseQuery)
            ->with([
                'ingredient' => static fn ($q) => $q->withTrashed(),
                'user' => static fn ($q) => $q->withTrashed(),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        // One bulk query for order references on this page (no N+1)
        $orderIds = $movements->getCollection()
            ->where('reference_type', 'orders')
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $ordersById = $orderIds !== []
            ? Order::whereIn('id', $orderIds)->pluck('order_number', 'id')
            : collect();

        $ingredientsList = Ingredient::withTrashed()
            ->when(trim($this->ingredientSearch) !== '', function (Builder $q): void {
                $kw = '%'.trim($this->ingredientSearch).'%';
                $q->where(static function (Builder $sub) use ($kw): void {
                    $sub->where('name', 'like', $kw)->orWhere('sku', 'like', $kw);
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit', 'deleted_at']);

        $actorsList = User::withTrashed()
            ->orderBy('name')
            ->get(['id', 'name', 'deleted_at']);

        return view('livewire.inventory.stock-movement-history', [
            'movements' => $movements,
            'ordersById' => $ordersById,
            'summary' => $summary,
            'ingredientsList' => $ingredientsList,
            'actorsList' => $actorsList,
            'movementTypes' => self::MOVEMENT_TYPES,
        ])->layout('components.layouts.app', [
            'title' => __('messages.stock_history.title'),
            'headerTitle' => __('messages.stock_history.title'),
            'headerSubtitle' => __('messages.stock_history.subtitle'),
        ]);
    }
}
