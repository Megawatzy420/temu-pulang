<?php

namespace Tests\Feature;

use App\Livewire\ActivityLogs\ActivityLogIndex;
use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityLogOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    /**
     * 25. Important operations create activity records.
     */
    public function test_important_operations_create_activity_records(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Owner Test']);

        // 1. Auth log
        $this->post('/login', [
            'email' => $owner->email,
            'password' => 'password',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'auth.login',
            'module' => 'auth',
        ]);

        // 2. Custom operation record
        $log = ActivityLog::record(
            action: 'shift.opened',
            description: 'Shift dibuka',
            user: $owner,
            module: 'shift'
        );

        $this->assertDatabaseHas('activity_logs', [
            'id' => $log->id,
            'action' => 'shift.opened',
            'module' => 'shift',
        ]);
    }

    /**
     * 26. Cashier cannot access the Owner-only activity log.
     */
    public function test_cashier_cannot_access_owner_only_activity_log(): void
    {
        $cashier = User::factory()->cashier()->create();

        // HTTP Route level redirect
        $response = $this->actingAs($cashier)->get('/activity-logs');
        $response->assertRedirect('/pos');

        // Component level check
        Livewire::actingAs($cashier)
            ->test(ActivityLogIndex::class)
            ->assertForbidden();
    }

    /**
     * 27. Activity logs cannot be modified through normal application routes.
     */
    public function test_activity_logs_cannot_be_modified_or_deleted_via_routes(): void
    {
        $owner = User::factory()->owner()->create();
        $log = ActivityLog::record(
            action: 'test.immutable',
            description: 'Log tidak boleh diubah',
            user: $owner
        );

        // Assert no PUT/PATCH/DELETE routes exist for activity logs
        $putResponse = $this->actingAs($owner)->put('/activity-logs/'.$log->id, [
            'description' => 'Hacked description',
        ]);
        $putResponse->assertNotFound();

        $deleteResponse = $this->actingAs($owner)->delete('/activity-logs/'.$log->id);
        $deleteResponse->assertNotFound();

        // Record still intact in DB
        $this->assertDatabaseHas('activity_logs', [
            'id' => $log->id,
            'description' => 'Log tidak boleh diubah',
        ]);
    }

    /**
     * Test activity log search and filters.
     */
    public function test_activity_log_filters_and_search_work_correctly(): void
    {
        $owner = User::factory()->owner()->create();
        $cashier = User::factory()->cashier()->create();

        ActivityLog::record('shift.opened', 'Buka shift kasir', user: $cashier, module: 'shift');
        ActivityLog::record('settings.updated', 'Ubah setting toko', user: $owner, module: 'settings');

        Livewire::actingAs($owner)
            ->test(ActivityLogIndex::class)
            ->assertStatus(200)
            ->assertSee('Buka shift kasir')
            ->assertSee('Ubah setting toko')
            // Filter by shift module
            ->set('selectedModule', 'shift')
            ->assertSee('Buka shift kasir')
            ->assertDontSee('Ubah setting toko')
            // Search filter
            ->set('selectedModule', 'all')
            ->set('search', 'setting toko')
            ->assertSee('Ubah setting toko')
            ->assertDontSee('Buka shift kasir');
    }
}
