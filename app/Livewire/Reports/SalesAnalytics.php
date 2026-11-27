<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class SalesAnalytics extends Component
{
    public const MAX_RANGE_DAYS = 366;

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    public ?string $validationNotice = null;

    /**
     * Mount the Sales Analytics component and enforce Owner-only RBAC.
     */
    public function mount(AnalyticsService $analyticsService): void
    {
        $this->authorizeOwnerAccess();

        $todayBusinessDate = $analyticsService->currentBusinessDate();

        if ($this->from === '' && $this->to === '') {
            $this->from = $todayBusinessDate;
            $this->to = $todayBusinessDate;

            return;
        }

        $this->validateAndNormalizeDates($todayBusinessDate);
    }

    /**
     * Validate date inputs whenever $from or $to is updated by the user.
     */
    public function updated(string $property, AnalyticsService $analyticsService): void
    {
        $this->authorizeOwnerAccess();

        if (in_array($property, ['from', 'to'], true)) {
            $this->validationNotice = null;
            $this->validateAndNormalizeDates($analyticsService->currentBusinessDate());
        }
    }

    /**
     * Apply a quick date-range preset relative to today's business date.
     */
    public function applyPreset(string $preset, AnalyticsService $analyticsService): void
    {
        $this->authorizeOwnerAccess();
        $this->validationNotice = null;

        $todayStr = $analyticsService->currentBusinessDate();
        $today = CarbonImmutable::parse($todayStr)->startOfDay();

        switch ($preset) {
            case 'today':
                $this->from = $todayStr;
                $this->to = $todayStr;
                break;

            case 'yesterday':
                $yesterday = $today->subDay()->format('Y-m-d');
                $this->from = $yesterday;
                $this->to = $yesterday;
                break;

            case 'this_week':
                $this->from = $today->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');
                $this->to = $today->endOfWeek(CarbonInterface::SUNDAY)->format('Y-m-d');
                break;

            case 'this_month':
                $this->from = $today->startOfMonth()->format('Y-m-d');
                $this->to = $today->endOfMonth()->format('Y-m-d');
                break;

            case 'last_month':
                $prevMonth = $today->subMonthNoOverflow();
                $this->from = $prevMonth->startOfMonth()->format('Y-m-d');
                $this->to = $prevMonth->endOfMonth()->format('Y-m-d');
                break;

            default:
                $this->from = $todayStr;
                $this->to = $todayStr;
                break;
        }
    }

    /**
     * Explicitly re-validate and refresh the analytics data.
     */
    public function refreshAnalytics(AnalyticsService $analyticsService): void
    {
        $this->authorizeOwnerAccess();
        $this->validateAndNormalizeDates($analyticsService->currentBusinessDate());
    }

    /**
     * Render the Sales Analytics page.
     */
    public function render(AnalyticsService $analyticsService): View
    {
        $this->authorizeOwnerAccess();

        $todayBusinessDate = $analyticsService->currentBusinessDate();
        $this->validateAndNormalizeDates($todayBusinessDate);

        $analytics = $analyticsService->buildAnalytics($this->from, $this->to);

        return view('livewire.reports.sales-analytics', [
            'analytics' => $analytics,
        ])->layout('components.layouts.app', [
            'title' => __('messages.analytics.page_title'),
            'headerTitle' => __('messages.analytics.header_title'),
            'headerSubtitle' => __('messages.analytics.header_subtitle'),
        ]);
    }

    /**
     * Validate that $from and $to are valid Y-m-d calendar dates, $from <= $to, and the range is at most 366 days.
     * On invalid input, reset to today's business date and show a translated notice without throwing an error page.
     */
    private function validateAndNormalizeDates(string $todayBusinessDate): void
    {
        $fromDate = $this->parseStrictCalendarDate($this->from);
        $toDate = $this->parseStrictCalendarDate($this->to);

        if ($fromDate === null || $toDate === null) {
            $this->resetToTodayWithNotice($todayBusinessDate);

            return;
        }

        if ($fromDate->gt($toDate)) {
            $this->resetToTodayWithNotice($todayBusinessDate);

            return;
        }

        $daysInclusive = (int) $fromDate->diffInDays($toDate) + 1;
        if ($daysInclusive > self::MAX_RANGE_DAYS) {
            $this->resetToTodayWithNotice($todayBusinessDate);

            return;
        }

        $this->from = $fromDate->format('Y-m-d');
        $this->to = $toDate->format('Y-m-d');
    }

    /**
     * Parse a strict YYYY-MM-DD calendar date string, returning null if malformed or non-existent.
     */
    private function parseStrictCalendarDate(string $value): ?CarbonImmutable
    {
        $trimmed = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::createFromDate($year, $month, $day)->startOfDay();
    }

    /**
     * Reset the filter range to today's business date and record a translated user-facing notice.
     */
    private function resetToTodayWithNotice(string $todayBusinessDate): void
    {
        $this->from = $todayBusinessDate;
        $this->to = $todayBusinessDate;
        $this->validationNotice = __('messages.analytics.validation_reset_notice');
    }

    /**
     * Verify that the current authenticated user is an Owner.
     */
    private function authorizeOwnerAccess(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }
    }
}
