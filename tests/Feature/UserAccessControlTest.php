<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_owner_role_has_full_system_access_via_gate(): void
    {
        $owner = User::factory()->owner()->create();

        $this->assertTrue($owner->hasRole('owner'));
        $this->assertTrue($owner->isOwner());

        // Gate should grant all abilities to owner via Gate::before
        $this->assertTrue(Gate::forUser($owner)->allows('pos.access'));
        $this->assertTrue(Gate::forUser($owner)->allows('products.manage'));
        $this->assertTrue(Gate::forUser($owner)->allows('reports.sensitive.view'));
        $this->assertTrue(Gate::forUser($owner)->allows('refunds.process'));
        $this->assertTrue(Gate::forUser($owner)->allows('settings.manage'));
    }

    public function test_cashier_can_operate_pos_and_view_inventory(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->assertTrue($cashier->hasRole('cashier'));
        $this->assertTrue($cashier->isCashier());

        // Allowed abilities
        $this->assertTrue(Gate::forUser($cashier)->allows('pos.access'));
        $this->assertTrue(Gate::forUser($cashier)->allows('pos.orders.create'));
        $this->assertTrue(Gate::forUser($cashier)->allows('pos.orders.view'));
        $this->assertTrue(Gate::forUser($cashier)->allows('inventory.view'));
    }

    public function test_cashier_is_forbidden_from_managing_products_refunds_reports_and_settings(): void
    {
        $cashier = User::factory()->cashier()->create();

        // Cashier MUST NOT be allowed these actions
        $this->assertFalse(Gate::forUser($cashier)->allows('products.manage'));
        $this->assertFalse(Gate::forUser($cashier)->allows('reports.sensitive.view'));
        $this->assertFalse(Gate::forUser($cashier)->allows('refunds.process'));
        $this->assertFalse(Gate::forUser($cashier)->allows('settings.manage'));
    }

    public function test_passwords_are_securely_hashed(): void
    {
        $rawPassword = 'SecretPassword123!';
        $user = User::factory()->create([
            'password' => $rawPassword,
        ]);

        $this->assertNotEquals($rawPassword, $user->password);
        $this->assertTrue(Hash::check($rawPassword, $user->password));
    }

    public function test_user_active_scope_and_soft_deletes(): void
    {
        $activeUser = User::factory()->create(['is_active' => true]);
        $inactiveUser = User::factory()->inactive()->create();

        $activeUsers = User::active()->get();
        $this->assertTrue($activeUsers->contains($activeUser));
        $this->assertFalse($activeUsers->contains($inactiveUser));

        $activeUser->delete();
        $this->assertSoftDeleted($activeUser);
        $this->assertDatabaseHas('users', ['id' => $activeUser->id]);
    }

    public function test_activity_logs_can_be_recorded_for_auditing(): void
    {
        $user = User::factory()->create();

        $log = ActivityLog::record(
            action: 'pos.shift_opened',
            description: 'Cashier opened register shift with 200000 IDR float',
            properties: ['cash_float' => 200000],
            user: $user
        );

        $this->assertDatabaseHas('activity_logs', [
            'id' => $log->id,
            'user_id' => $user->id,
            'action' => 'pos.shift_opened',
        ]);

        $this->assertEquals(200000, $log->properties['cash_float']);
        $this->assertEquals($user->id, $log->user->id);
    }

    public function test_role_and_permission_relationships(): void
    {
        $role = Role::create([
            'name' => 'Supervisor',
            'slug' => 'supervisor',
            'description' => 'Shift supervisor',
        ]);

        $permission = Permission::create([
            'name' => 'Void Order Item',
            'slug' => 'orders.void_item',
            'group' => 'pos',
        ]);

        $role->givePermissionTo($permission);
        $this->assertTrue($role->hasPermission('orders.void_item'));

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($user->hasRole('supervisor'));
        $this->assertTrue($user->hasPermission('orders.void_item'));

        $role->revokePermissionTo($permission);
        $this->assertFalse($role->hasPermission('orders.void_item'));
    }
}
