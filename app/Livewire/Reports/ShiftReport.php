<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Models\Shift;
use App\Services\ShiftReportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class ShiftReport extends Component
{
    public Shift $shift;

    /**
     * Mount the Shift Report component and enforce RBAC:
     * Owner may view any shift; Cashier may view only a shift they opened themselves.
     */
    public function mount(Shift $shift): void
    {
        $this->authorizeShiftAccess($shift);
        $this->shift = $shift;
    }

    /**
     * Refresh the live X-Report state from the database.
     */
    public function refreshReport(): void
    {
        $this->shift = $this->shift->fresh(['user', 'closedByUser']) ?? $this->shift;
        $this->authorizeShiftAccess($this->shift);
    }

    /**
     * Render the X-Report (live) or Z-Report (final) view.
     */
    public function render(ShiftReportService $reportService): View
    {
        $this->authorizeShiftAccess($this->shift);

        $report = $reportService->buildReport($this->shift);
        $pageTitle = $report['is_open']
            ? __('messages.reports.x_report_title')
            : __('messages.reports.z_report_title');
        $pageSubtitle = $report['is_open']
            ? __('messages.reports.x_report_subtitle')
            : __('messages.reports.z_report_subtitle');

        return view('livewire.reports.shift-report', [
            'report' => $report,
        ])->layout('components.layouts.app', [
            'title' => $pageTitle.' — '.$report['header']['shift_number'],
            'headerTitle' => $pageTitle,
            'headerSubtitle' => $pageSubtitle,
        ]);
    }

    /**
     * Verify that the current user is either an Owner or the Cashier who opened the shift.
     */
    private function authorizeShiftAccess(Shift $shift): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }

        if (! $user->isOwner() && (int) $shift->user_id !== (int) $user->id) {
            abort(403);
        }
    }
}
