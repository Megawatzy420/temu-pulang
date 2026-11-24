<?php

namespace App\Livewire\Printing;

use App\Models\ActivityLog;
use App\Models\PrintJob;
use App\Services\PrintService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class PrintJobIndex extends Component
{
    use WithPagination;

    public string $statusFilter = 'all';

    public bool $showResolveModal = false;

    public ?int $resolvingJobId = null;

    public string $resolution = 'completed';

    public string $resolutionNote = '';

    /**
     * Reset pagination when status filter changes.
     */
    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Open resolution modal for an uncertain job.
     */
    public function openResolveModal(int $jobId): void
    {
        abort_unless(Auth::user()?->isOwner(), 403, __('messages.common.unauthorized'));

        $job = PrintJob::findOrFail($jobId);
        if ($job->status !== 'uncertain') {
            return;
        }

        $this->resetValidation();
        $this->resolvingJobId = $job->id;
        $this->resolution = 'completed';
        $this->resolutionNote = '';
        $this->showResolveModal = true;
    }

    /**
     * Close the resolution modal.
     */
    public function closeResolveModal(): void
    {
        $this->showResolveModal = false;
        $this->resolvingJobId = null;
        $this->resolutionNote = '';
        $this->resetValidation();
    }

    /**
     * Submit resolution for the uncertain job.
     */
    public function resolveJob(PrintService $printService): void
    {
        abort_unless(Auth::user()?->isOwner(), 403, __('messages.common.unauthorized'));

        $this->validate([
            'resolution' => ['required', 'in:completed,failed'],
            'resolutionNote' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $job = PrintJob::findOrFail($this->resolvingJobId);

        $printService->resolveUncertainJob($job, $this->resolution, Auth::user());

        ActivityLog::record(
            action: 'print.uncertain_note',
            description: "Catatan resolusi pemilik untuk pekerjaan cetak #{$job->id}: {$this->resolutionNote}",
            subject: $job,
            user: Auth::user(),
            module: 'printing',
            properties: [
                'resolution' => $this->resolution,
                'note' => $this->resolutionNote,
            ],
        );

        $this->dispatch('notify', message: __('messages.printing.job_resolved_success', [
            'id' => $job->id,
            'status' => $this->resolution,
        ]), type: 'success');

        $this->closeResolveModal();
    }

    /**
     * Render the component.
     */
    public function render(): View
    {
        abort_unless(Auth::user()?->isOwner(), 403, __('messages.common.unauthorized'));

        $jobs = PrintJob::query()
            ->with('order')
            ->whereIn('status', ['failed', 'uncertain'])
            ->when($this->statusFilter !== 'all', function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->latest('created_at')
            ->paginate(15);

        $resolvingJob = $this->resolvingJobId ? PrintJob::with('order')->find($this->resolvingJobId) : null;

        return view('livewire.printing.print-job-index', [
            'jobs' => $jobs,
            'resolvingJob' => $resolvingJob,
        ])->layout('components.layouts.app', [
            'title' => __('messages.printing.title'),
            'headerTitle' => __('messages.printing.title'),
            'headerSubtitle' => __('messages.printing.subtitle'),
        ]);
    }
}
