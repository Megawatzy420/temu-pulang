<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Shifts\ShiftManager;
use App\Models\ActivityLog;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\ShiftService;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\ShopSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class GoLiveHardeningPart2ATest extends TestCase
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

    // ── 1. Login throttling (5 failed attempts allowed, 6th blocked per email+IP) ──

    public function test_login_throttle_allows_five_failed_attempts_blocks_sixth_is_scoped_per_email_and_ip_clears_on_success_and_logs_without_password(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $realSecret = 'ValidOwnerPass#2026!';
        $wrongSecret = 'WrongAttemptPass#999';

        $owner = User::factory()->owner()->create([
            'email' => 'owner@temupulang.id',
            'password' => Hash::make($realSecret),
            'is_active' => true,
        ]);

        $otherUser = User::factory()->cashier()->create([
            'email' => 'cashier@temupulang.id',
            'password' => Hash::make($realSecret),
            'is_active' => true,
        ]);

        RateLimiter::clear('owner@temupulang.id|127.0.0.1');
        RateLimiter::clear('cashier@temupulang.id|127.0.0.1');
        RateLimiter::clear('owner@temupulang.id|192.168.1.55');

        // 1. First 5 failed logins for owner@temupulang.id from 127.0.0.1 return invalid_credentials
        App::setLocale('id');
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->from('/login')->post('/login', [
                'email' => 'Owner@TemuPulang.id',
                'password' => $wrongSecret,
            ]);

            $response->assertRedirect('/login');
            $response->assertSessionHasErrors([
                'email' => __('messages.auth.invalid_credentials'),
            ]);
        }

        $this->assertSame(0, ActivityLog::where('action', 'auth.login_throttled')->count());

        // 2. The 6th attempt is blocked with translated message + remaining seconds and writes ActivityLog without password
        $sixthResponse = $this->from('/login')->post('/login', [
            'email' => 'OWNER@temupulang.id',
            'password' => $wrongSecret,
        ]);

        $sixthResponse->assertRedirect('/login');
        $sixthResponse->assertSessionHasErrors('email');
        $sixthError = $this->app['session.store']->get('errors')->getBag('default')->first('email');
        $this->assertMatchesRegularExpression(
            '/Terlalu banyak percobaan masuk yang gagal\. Silakan coba lagi dalam \d+ detik\./',
            $sixthError
        );

        $throttleLog = ActivityLog::where('action', 'auth.login_throttled')
            ->where('module', 'auth')
            ->latest('id')
            ->first();
        $this->assertNotNull($throttleLog);
        $serializedLog = (string) json_encode($throttleLog->toArray());
        $this->assertStringNotContainsString($wrongSecret, $serializedLog);
        $this->assertStringNotContainsString($realSecret, $serializedLog);

        // 3. English locale also renders the translated throttle message with remaining seconds
        App::setLocale('en');
        $enResponse = $this->from('/login')->post('/login', [
            'email' => 'owner@temupulang.id',
            'password' => $wrongSecret,
        ]);
        $enResponse->assertSessionHasErrors('email');
        $enError = $this->app['session.store']->get('errors')->getBag('default')->first('email');
        $this->assertMatchesRegularExpression(
            '/Too many failed login attempts\. Please try again in \d+ seconds\./',
            $enError
        );

        // 4. Different email from the same IP is NOT blocked
        $diffEmailResponse = $this->from('/login')->post('/login', [
            'email' => $otherUser->email,
            'password' => $realSecret,
        ]);
        $diffEmailResponse->assertRedirect(route('pos'));
        $this->assertAuthenticatedAs($otherUser);
        $this->post('/logout');

        // 5. Same email from a different IP is NOT blocked
        $diffIpResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.55'])
            ->from('/login')
            ->post('/login', [
                'email' => $owner->email,
                'password' => $realSecret,
            ]);
        $diffIpResponse->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner);
        $this->post('/logout');

        // 6. Successful login clears the counter after prior failures
        RateLimiter::clear('owner@temupulang.id|127.0.0.1');
        for ($i = 1; $i <= 4; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $owner->email,
                'password' => $wrongSecret,
            ]);
        }

        // 5th attempt succeeds -> clears counter
        $this->from('/login')->post('/login', [
            'email' => $owner->email,
            'password' => $realSecret,
        ])->assertRedirect(route('dashboard'));
        $this->post('/logout');

        // Counter was cleared, so another failed attempt is treated as attempt #1 (not throttled)
        App::setLocale('id');
        $afterClear = $this->from('/login')->post('/login', [
            'email' => $owner->email,
            'password' => $wrongSecret,
        ]);
        $afterClear->assertSessionHasErrors([
            'email' => __('messages.auth.invalid_credentials'),
        ]);
    }

    // ── 2. ShopSettingSeeder environment-aware placeholders ──────────────────

    public function test_shop_setting_seeder_uses_neutral_values_in_production_and_sample_values_in_testing(): void
    {
        $this->app['env'] = 'production';
        app(ShopSettingSeeder::class)->run();

        $prodSetting = ShopSetting::firstOrFail();
        $this->assertSame('', (string) $prodSetting->address);
        $this->assertSame('', (string) $prodSetting->phone);
        $this->assertSame('', (string) $prodSetting->email);
        $this->assertSame(ShopSettingSeeder::GENERIC_RECEIPT_FOOTER, $prodSetting->receipt_footer);
        $this->assertStringNotContainsString('kopienak', (string) $prodSetting->receipt_footer);

        $this->app['env'] = 'testing';
        app(ShopSettingSeeder::class)->run();

        $testSetting = ShopSetting::firstOrFail();
        $this->assertSame(ShopSettingSeeder::SAMPLE_ADDRESS, $testSetting->address);
        $this->assertSame(ShopSettingSeeder::SAMPLE_PHONE, $testSetting->phone);
        $this->assertSame(ShopSettingSeeder::SAMPLE_EMAIL, $testSetting->email);
        $this->assertSame(ShopSettingSeeder::SAMPLE_RECEIPT_FOOTER, $testSetting->receipt_footer);
    }

    // ── 3. Preflight placeholder FAIL, empty address WARN, session lifetime WARN ──

    public function test_preflight_fails_on_seeder_placeholders_and_warns_on_empty_address_or_short_session_lifetime(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $realOwnerPass = 'ProdOwnerValidPass#2026';
        User::factory()->owner()->create([
            'name' => 'Real Owner',
            'email' => 'owner@kopitemu.id',
            'password' => Hash::make($realOwnerPass),
            'is_active' => true,
        ]);

        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.demo_login_enabled' => null,
            'session.lifetime' => 720,
            'logging.channels.stack.level' => 'warning',
            'logging.channels.single.level' => 'warning',
        ]);

        // 1. Seeder placeholder values give FAIL and exit code 1
        $setting = ShopSetting::create([
            'name' => 'Temu Pulang',
            'address' => ShopSettingSeeder::SAMPLE_ADDRESS,
            'phone' => ShopSettingSeeder::SAMPLE_PHONE,
            'email' => ShopSettingSeeder::SAMPLE_EMAIL,
            'receipt_footer' => ShopSettingSeeder::SAMPLE_RECEIPT_FOOTER,
            'tax_rate' => 0,
            'service_charge_rate' => 0,
            'printer_name' => 'POS-80',
            'is_active' => true,
        ]);

        $this->assertSame(1, Artisan::call('temupulang:preflight'));
        $failOutput = Artisan::output();
        $this->assertStringContainsString('[FAIL]', $failOutput);
        $this->assertStringContainsString('sample seeder placeholder', $failOutput);
        $this->assertStringNotContainsString($realOwnerPass, $failOutput);

        // 2. Empty address gives WARN (and exit code 0 when no FAIL exists)
        $setting->update([
            'address' => '',
            'phone' => '021-5551234',
            'email' => 'contact@kopitemu.id',
            'receipt_footer' => ShopSettingSeeder::GENERIC_RECEIPT_FOOTER,
        ]);

        $this->assertSame(0, Artisan::call('temupulang:preflight'));
        $emptyAddrOutput = Artisan::output();
        $this->assertStringContainsString('[WARN] Shop address or phone number is empty', $emptyAddrOutput);

        // 3. Session lifetime below 480 gives WARN (and exit code 0)
        $setting->update([
            'address' => 'Jl. Melawai Raya No. 8, Jakarta Selatan',
        ]);
        config(['session.lifetime' => 120]);

        $this->assertSame(0, Artisan::call('temupulang:preflight'));
        $shortSessionOutput = Artisan::output();
        $this->assertStringContainsString('[WARN] SESSION_LIFETIME is 120 minutes', $shortSessionOutput);

        // 4. Clean configuration with SESSION_LIFETIME=720 exits 0 and never prints passwords
        config(['session.lifetime' => 720]);
        $this->assertSame(0, Artisan::call('temupulang:preflight'));
        $cleanOutput = Artisan::output();
        $this->assertStringContainsString('[PASS] SESSION_LIFETIME is 720 minutes', $cleanOutput);
        $this->assertStringNotContainsString($realOwnerPass, $cleanOutput);
        $this->assertStringNotContainsString('TemuPulang@2026!', $cleanOutput);
        $this->assertStringNotContainsString('Cashier@2026!', $cleanOutput);
    }

    // ── 4. Shift modals start empty and reject empty submission ──────────────

    public function test_shift_modals_start_with_empty_amounts_and_reject_empty_submissions_with_validation_errors(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $cashier = User::factory()->cashier()->create();

        // 1. Open Shift modal starts with openingCash = null and rejects empty submit
        Livewire::actingAs($cashier)
            ->test(ShiftManager::class)
            ->assertSet('openingCash', null)
            ->call('openNewShiftModal')
            ->assertSet('showOpenShiftModal', true)
            ->assertSet('openingCash', null)
            ->call('submitOpenShift')
            ->assertHasErrors(['openingCash' => 'required']);

        // 2. Cash Movement modal starts with movementAmount = null and rejects empty submit
        app(ShiftService::class)->openShift($cashier, 150000, 'Test shift');

        Livewire::actingAs($cashier)
            ->test(ShiftManager::class)
            ->assertSet('movementAmount', null)
            ->call('openNewMovementModal', 'in')
            ->assertSet('showMovementModal', true)
            ->assertSet('movementAmount', null)
            ->set('movementDescription', 'Tambah pecahan kecil')
            ->call('submitCashMovement')
            ->assertHasErrors(['movementAmount' => 'required']);

        // 3. Language key parity check
        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);
    }
}
