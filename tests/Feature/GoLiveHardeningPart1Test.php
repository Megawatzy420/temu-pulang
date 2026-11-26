<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\ShopSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GoLiveHardeningPart1Test extends TestCase
{
    use RefreshDatabase;

    private string $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = (string) $this->app['env'];
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->originalEnv;
        config(['app.demo_login_enabled' => null]);
        parent::tearDown();
    }

    // ── 1. Demo login environment gating ──────────────────────────────────────

    public function test_demo_login_works_in_local_and_testing_and_returns_404_in_production(): void
    {
        $this->seed(DatabaseSeeder::class);

        // 1. Works in 'testing'
        $this->app['env'] = 'testing';
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('owner@temupulang.local')
            ->assertSee('cashier@temupulang.local');

        $this->post(route('login.demo', ['role' => 'owner']), ['_token' => session()->token()])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->post(route('logout'), ['_token' => session()->token()]);

        // 2. Works in 'local'
        $this->app['env'] = 'local';
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('owner@temupulang.local')
            ->assertSee('cashier@temupulang.local');

        $this->post(route('login.demo', ['role' => 'cashier']), ['_token' => session()->token()])
            ->assertRedirect(route('pos'));
        $this->assertAuthenticated();
        $this->post(route('logout'), ['_token' => session()->token()]);

        // 3. Disabled in 'production': login page hides demo block & route returns 404
        $this->app['env'] = 'production';
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('owner@temupulang.local')
            ->assertDontSee('cashier@temupulang.local')
            ->assertDontSee('TemuPulang@2026!')
            ->assertDontSee(__('messages.auth.quick_demo_login'));

        $this->post(route('login.demo', ['role' => 'owner']), ['_token' => session()->token()])
            ->assertNotFound();
        $this->assertGuest();

        $this->post(route('login.demo', ['role' => 'cashier']), ['_token' => session()->token()])
            ->assertNotFound();
        $this->assertGuest();
    }

    // ── 2. DatabaseSeeder environment gating ──────────────────────────────────

    public function test_database_seeder_creates_no_users_in_production_and_creates_demo_users_in_testing(): void
    {
        $this->app['env'] = 'production';
        app(DatabaseSeeder::class)->run();

        $this->assertSame(0, User::count());
        $this->assertTrue(Role::where('slug', 'owner')->exists());
        $this->assertTrue(Role::where('slug', 'cashier')->exists());

        $this->app['env'] = 'testing';
        app(DatabaseSeeder::class)->run();

        $this->assertTrue(User::where('email', 'owner@temupulang.local')->exists());
        $this->assertTrue(User::where('email', 'cashier@temupulang.local')->exists());
    }

    // ── 3. temupulang:create-user command ─────────────────────────────────────

    public function test_create_user_command_creates_active_user_with_role_and_validates_inputs(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        // Verify no --password option or password argument exists on the command definition
        $definition = Artisan::all()['temupulang:create-user']->getDefinition();
        $this->assertFalse($definition->hasOption('password'));
        $this->assertFalse($definition->hasArgument('password'));

        $secretPass = 'ProdOwner#2026Valid';

        // 1. Valid creation
        $this->artisan('temupulang:create-user', [
            '--role' => 'owner',
            '--name' => 'Real Store Owner',
            '--email' => 'realowner@temupulang.id',
        ])
            ->expectsQuestion('Password (minimum 10 characters)', $secretPass)
            ->expectsQuestion('Confirm password', $secretPass)
            ->expectsOutputToContain('realowner@temupulang.id')
            ->doesntExpectOutputToContain($secretPass)
            ->assertExitCode(0);

        $user = User::where('email', 'realowner@temupulang.id')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->isOwner());
        $this->assertNotSame($secretPass, $user->password);
        $this->assertTrue(Hash::check($secretPass, $user->password));

        // 2. Rejects duplicate email
        $this->artisan('temupulang:create-user', [
            '--role' => 'cashier',
            '--name' => 'Duplicate Email',
            '--email' => 'realowner@temupulang.id',
        ])
            ->expectsOutputToContain('already registered')
            ->assertExitCode(1);

        // 3. Rejects invalid email
        $this->artisan('temupulang:create-user', [
            '--role' => 'cashier',
            '--name' => 'Bad Email',
            '--email' => 'not-an-email',
        ])
            ->expectsOutputToContain('valid email')
            ->assertExitCode(1);

        // 4. Rejects invalid role
        $this->artisan('temupulang:create-user', [
            '--role' => 'supervisor',
            '--name' => 'Invalid Role',
            '--email' => 'sup@temupulang.id',
        ])
            ->expectsOutputToContain('Invalid role')
            ->assertExitCode(1);

        // 5. Rejects password shorter than 10 characters
        $this->artisan('temupulang:create-user', [
            '--role' => 'cashier',
            '--name' => 'Short Pass Cashier',
            '--email' => 'cashier1@temupulang.id',
        ])
            ->expectsQuestion('Password (minimum 10 characters)', 'Short123!')
            ->expectsQuestion('Confirm password', 'Short123!')
            ->expectsOutputToContain('at least 10 characters')
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'cashier1@temupulang.id')->exists());
    }

    // ── 4. temupulang:set-password command ────────────────────────────────────

    public function test_set_password_command_updates_hash_and_rejects_unknown_email(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->cashier()->create([
            'email' => 'staff@temupulang.id',
            'password' => Hash::make('OldPassword#1234'),
        ]);

        $newPass = 'NewStrongPass#2026!';

        $this->artisan('temupulang:set-password', ['email' => 'staff@temupulang.id'])
            ->expectsQuestion('New password (minimum 10 characters)', $newPass)
            ->expectsQuestion('Confirm new password', $newPass)
            ->doesntExpectOutputToContain($newPass)
            ->assertExitCode(0);

        $this->assertTrue(Hash::check($newPass, $user->fresh()->password));

        // Unknown email
        $this->artisan('temupulang:set-password', ['email' => 'unknown@temupulang.id'])
            ->expectsOutputToContain('User not found')
            ->assertExitCode(1);
    }

    // ── 5. temupulang:deactivate-user command ─────────────────────────────────

    public function test_deactivate_user_blocks_login_protects_last_owner_and_supports_reactivate(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $owner1 = User::factory()->owner()->create([
            'email' => 'owner1@temupulang.id',
            'password' => Hash::make('OwnerOnePass#2026'),
            'is_active' => true,
        ]);

        $cashier = User::factory()->cashier()->create([
            'email' => 'cashier1@temupulang.id',
            'password' => Hash::make('CashierOnePass#2026'),
            'is_active' => true,
        ]);

        // 1. Refuses to deactivate the LAST active owner
        $this->artisan('temupulang:deactivate-user', ['email' => 'owner1@temupulang.id'])
            ->expectsOutputToContain('last active owner')
            ->assertExitCode(1);
        $this->assertTrue($owner1->fresh()->is_active);

        // 2. Deactivating cashier succeeds, writes ActivityLog without secrets, and blocks login
        $this->artisan('temupulang:deactivate-user', ['email' => 'cashier1@temupulang.id'])
            ->assertExitCode(0);

        $this->assertFalse($cashier->fresh()->is_active);

        $deactivatedLog = ActivityLog::where('action', 'user.deactivated')
            ->where('module', 'auth')
            ->latest('id')
            ->first();
        $this->assertNotNull($deactivatedLog);
        $this->assertStringNotContainsString('CashierOnePass#2026', (string) json_encode($deactivatedLog->toArray()));

        $this->post('/login', [
            'email' => 'cashier1@temupulang.id',
            'password' => 'CashierOnePass#2026',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        // 3. --reactivate restores login access and writes ActivityLog
        $this->artisan('temupulang:deactivate-user', [
            'email' => 'cashier1@temupulang.id',
            '--reactivate' => true,
        ])->assertExitCode(0);

        $this->assertTrue($cashier->fresh()->is_active);

        $reactivatedLog = ActivityLog::where('action', 'user.reactivated')
            ->where('module', 'auth')
            ->latest('id')
            ->first();
        $this->assertNotNull($reactivatedLog);

        $this->post('/login', [
            'email' => 'cashier1@temupulang.id',
            'password' => 'CashierOnePass#2026',
        ])->assertRedirect(route('pos'));
        $this->assertAuthenticatedAs($cashier);
    }

    // ── 6. temupulang:preflight command ───────────────────────────────────────

    public function test_preflight_passes_on_valid_production_config_and_fails_on_each_fail_condition(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        ShopSetting::create([
            'name' => 'Temu Pulang',
            'tax_rate' => 0,
            'service_charge_rate' => 0,
            'printer_name' => 'POS-80',
            'is_active' => true,
        ]);

        $realOwnerPass = 'RealProdOwner#2026!';
        $owner = User::factory()->owner()->create([
            'name' => 'Prod Owner',
            'email' => 'owner@temupulang.co.id',
            'password' => Hash::make($realOwnerPass),
            'is_active' => true,
        ]);

        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.demo_login_enabled' => null,
        ]);

        // 1. Passing configuration -> exit code 0, output never contains password
        $exitCode = Artisan::call('temupulang:preflight');
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringNotContainsString($realOwnerPass, $output);
        $this->assertStringNotContainsString('TemuPulang@2026!', $output);
        $this->assertStringNotContainsString('Cashier@2026!', $output);

        // 2. FAIL: APP_DEBUG is true in production
        config(['app.debug' => true]);
        $this->assertSame(1, Artisan::call('temupulang:preflight'));
        config(['app.debug' => false]);

        // 3. FAIL: Missing APP_KEY
        $savedKey = config('app.key');
        config(['app.key' => '']);
        $this->assertSame(1, Artisan::call('temupulang:preflight'));
        config(['app.key' => $savedKey]);

        // 4. FAIL: Demo login enabled in production
        config(['app.demo_login_enabled' => true]);
        $this->assertSame(1, Artisan::call('temupulang:preflight'));
        config(['app.demo_login_enabled' => null]);

        // 5. FAIL: Active user still uses a demo password (output never prints the password)
        $demoPassUser = User::factory()->cashier()->create([
            'email' => 'staff@temupulang.co.id',
            'password' => Hash::make('TemuPulang@2026!'),
            'is_active' => true,
        ]);
        $this->assertSame(1, Artisan::call('temupulang:preflight'));
        $demoFailOutput = Artisan::output();
        $this->assertStringNotContainsString('TemuPulang@2026!', $demoFailOutput);
        $this->assertStringNotContainsString('Cashier@2026!', $demoFailOutput);
        $demoPassUser->update(['is_active' => false]);

        // 6. FAIL: No active owner exists
        $owner->update(['is_active' => false]);
        $this->assertSame(1, Artisan::call('temupulang:preflight'));
    }
}
