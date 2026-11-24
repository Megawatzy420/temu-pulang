<?php

namespace App\Livewire\ActivityLogs;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ActivityLogIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $selectedUserId = 'all';

    public string $selectedModule = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    public ?int $viewingLogId = null;

    /**
     * Reset pagination when search or filters change.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSelectedUserId(): void
    {
        $this->resetPage();
    }

    public function updatingSelectedModule(): void
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

    /**
     * Show properties JSON modal.
     */
    public function viewDetails(int $id): void
    {
        $this->viewingLogId = $id;
    }

    public function closeDetails(): void
    {
        $this->viewingLogId = null;
    }

    /**
     * Render Activity Log index.
     */
    public function render(): View
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403, 'Hanya Pemilik (Owner) yang berwenang melihat log aktivitas sistem.');
        }

        $logs = ActivityLog::query()
            ->with('user')
            ->when($this->search !== '', function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->where('description', 'like', "%{$this->search}%")
                        ->orWhere('action', 'like', "%{$this->search}%")
                        ->orWhere('ip_address', 'like', "%{$this->search}%")
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->selectedUserId !== 'all', function (Builder $query) {
                $query->where('user_id', $this->selectedUserId);
            })
            ->when($this->selectedModule !== 'all', function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->where('module', $this->selectedModule)
                        ->orWhere('action', 'like', "{$this->selectedModule}.%");
                });
            })
            ->when($this->dateFrom !== '', function (Builder $query) {
                $query->whereDate('created_at', '>=', $this->dateFrom);
            })
            ->when($this->dateTo !== '', function (Builder $query) {
                $query->whereDate('created_at', '<=', $this->dateTo);
            })
            ->latest('created_at')
            ->paginate(15);

        $users = User::orderBy('name')->get();
        $modules = [
            'auth' => 'Otentikasi & Akses',
            'shift' => 'Shift Kasir',
            'cash_movement' => 'Pergerakan Kas Drawer',
            'settings' => 'Pengaturan Toko',
            'system' => 'Sistem & Inisialisasi',
        ];

        $viewingLog = $this->viewingLogId ? ActivityLog::with('user')->find($this->viewingLogId) : null;

        return view('livewire.activity-logs.activity-log-index', [
            'logs' => $logs,
            'users' => $users,
            'modules' => $modules,
            'viewingLog' => $viewingLog,
        ])->layout('components.layouts.app', [
            'title' => 'Log Aktivitas & Audit Trail',
            'headerTitle' => 'Audit Trail & Log Aktivitas',
            'headerSubtitle' => 'Rekam jejak seluruh aktivitas penting dan keamanan kasir (Hanya Pemilik)',
        ]);
    }
}
