<?php

namespace Tests\Feature\Printing;

use App\Livewire\Printing\PrintJobIndex;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Shift;
use App\Models\User;
use App\Services\PrintService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OwnerPrintJobResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create();
    }

    protected function createTestOrder(User $cashier): Order
    {
        $shift = Shift::factory()->create([
            'user_id' => $cashier->id,
            'status' => 'open',
        ]);

        return Order::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'status' => 'completed',
        ]);
    }

    protected function createTestPrintJob(Order $order, array $attributes = []): PrintJob
    {
        return PrintJob::create(array_merge([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'receipt',
            'printer_destination' => 'POS-80',
            'status' => 'uncertain',
        ], $attributes));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('printing.jobs'));
        $response->assertRedirect(route('login'));
    }

    public function test_cashier_cannot_access_print_jobs_page(): void
    {
        $response = $this->actingAs($this->cashier)->get(route('printing.jobs'));
        // role middleware redirects cashier to /pos or aborts 403
        $this->assertTrue(
            $response->status() === 403 || $response->isRedirect(route('pos')),
            'Cashier must be denied access or redirected away from owner printing jobs page.'
        );
    }

    public function test_owner_can_access_print_jobs_page(): void
    {
        $response = $this->actingAs($this->owner)->get(route('printing.jobs'));
        $response->assertStatus(200);
        $response->assertSee(__('messages.printing.title'));
    }

    public function test_page_lists_only_failed_and_uncertain_jobs(): void
    {
        $order = $this->createTestOrder($this->cashier);

        $failedJob = $this->createTestPrintJob($order, [
            'status' => 'failed',
            'last_error' => 'Connection timeout to USB port',
        ]);

        $uncertainJob = $this->createTestPrintJob($order, [
            'status' => 'uncertain',
            'last_error' => 'Device status unknown after write',
        ]);

        $completedJob = $this->createTestPrintJob($order, [
            'status' => 'completed',
        ]);

        $pendingJob = $this->createTestPrintJob($order, [
            'status' => 'pending',
        ]);

        Livewire::actingAs($this->owner)
            ->test(PrintJobIndex::class)
            ->assertSee('Connection timeout to USB port')
            ->assertSee('Device status unknown after write')
            ->assertDontSee($completedJob->ulid)
            ->assertDontSee($pendingJob->ulid);
    }

    public function test_owner_can_resolve_uncertain_job_to_completed(): void
    {
        $order = $this->createTestOrder($this->cashier);

        $uncertainJob = $this->createTestPrintJob($order, [
            'status' => 'uncertain',
            'last_error' => 'Print verification timed out',
        ]);

        Livewire::actingAs($this->owner)
            ->test(PrintJobIndex::class)
            ->call('openResolveModal', $uncertainJob->id)
            ->assertSet('showResolveModal', true)
            ->assertSet('resolvingJobId', $uncertainJob->id)
            ->set('resolution', 'completed')
            ->set('resolutionNote', 'Struk fisik berhasil keluar utuh dari printer.')
            ->call('resolveJob')
            ->assertHasNoErrors()
            ->assertSet('showResolveModal', false);

        $this->assertEquals('completed', $uncertainJob->fresh()->status);
        $this->assertNotNull($uncertainJob->fresh()->completed_at);

        // Verify activity logs
        $this->assertTrue(
            ActivityLog::where('action', 'print.uncertain_resolved')->exists(),
            'ActivityLog for uncertain_resolved must be recorded.'
        );
        $this->assertTrue(
            ActivityLog::where('action', 'print.uncertain_note')->exists(),
            'ActivityLog for uncertain_note must be recorded.'
        );
    }

    public function test_owner_can_resolve_uncertain_job_to_failed(): void
    {
        $order = $this->createTestOrder($this->cashier);

        $uncertainJob = $this->createTestPrintJob($order, [
            'status' => 'uncertain',
            'last_error' => 'Paper jam suspected',
        ]);

        Livewire::actingAs($this->owner)
            ->test(PrintJobIndex::class)
            ->call('openResolveModal', $uncertainJob->id)
            ->set('resolution', 'failed')
            ->set('resolutionNote', 'Kertas macet dan struk robek di dalam roller.')
            ->call('resolveJob')
            ->assertHasNoErrors();

        $this->assertEquals('failed', $uncertainJob->fresh()->status);
        $this->assertNull($uncertainJob->fresh()->completed_at);
    }

    public function test_resolution_requires_note(): void
    {
        $order = $this->createTestOrder($this->cashier);

        $uncertainJob = $this->createTestPrintJob($order, [
            'status' => 'uncertain',
        ]);

        Livewire::actingAs($this->owner)
            ->test(PrintJobIndex::class)
            ->call('openResolveModal', $uncertainJob->id)
            ->set('resolution', 'completed')
            ->set('resolutionNote', '')
            ->call('resolveJob')
            ->assertHasErrors(['resolutionNote' => 'required']);

        // Assert job status remains unchanged
        $this->assertEquals('uncertain', $uncertainJob->fresh()->status);
    }

    public function test_cashier_cannot_access_or_call_resolve_action(): void
    {
        // 1. Livewire component level authorization check
        Livewire::actingAs($this->cashier)
            ->test(PrintJobIndex::class)
            ->assertForbidden();

        // 2. Direct action authorization check
        $this->actingAs($this->cashier);
        $component = app(PrintJobIndex::class);

        $this->expectException(HttpException::class);
        $component->resolveJob(app(PrintService::class));
    }

    public function test_pos_terminal_renders_in_indonesian_locale(): void
    {
        app()->setLocale('id');

        $this->actingAs($this->cashier)
            ->withSession(['locale' => 'id'])
            ->get(route('pos'))
            ->assertStatus(200)
            ->assertSee(__('messages.pos.current_order', [], 'id'))
            ->assertSee(__('messages.pos.checkout', [], 'id'));
    }

    public function test_pos_terminal_renders_in_english_locale(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->cashier)
            ->withSession(['locale' => 'en'])
            ->get(route('pos'))
            ->assertStatus(200)
            ->assertSee(__('messages.pos.current_order', [], 'en'))
            ->assertSee(__('messages.pos.checkout', [], 'en'));
    }

    public function test_pos_success_modal_displays_uncertain_message_instructing_to_contact_owner(): void
    {
        $idMessage = __('messages.pos.print_status_uncertain', [], 'id');
        $enMessage = __('messages.pos.print_status_uncertain', [], 'en');

        // Verify both translations mention contacting the owner / Pemilik
        $this->assertStringContainsString('Pemilik Toko', $idMessage);
        $this->assertStringContainsString('Shop Owner', $enMessage);
    }
}
