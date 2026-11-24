<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\OwnerDashboard;
use App\Livewire\Pos\CashierPos;
use App\Models\Product;
use App\Models\User;
use App\Services\ShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationAndFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_login_page_renders_successfully(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Temu Pulang');
        $response->assertSee('email');
        $response->assertSee('password');
    }

    public function test_owner_can_authenticate_and_redirects_to_dashboard(): void
    {
        $response = $this->post('/login', [
            'email' => 'owner@temupulang.local',
            'password' => 'TemuPulang@2026!',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_cashier_can_authenticate_and_redirects_to_pos(): void
    {
        $response = $this->post('/login', [
            'email' => 'cashier@temupulang.local',
            'password' => 'Cashier@2026!',
        ]);

        $response->assertRedirect('/pos');
        $this->assertAuthenticated();
    }

    public function test_user_cannot_authenticate_with_invalid_password(): void
    {
        $response = $this->post('/login', [
            'email' => 'owner@temupulang.local',
            'password' => 'WrongPassword!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_authenticate(): void
    {
        $user = User::factory()->inactive()->create([
            'email' => 'inactive@temupulang.local',
            'password' => 'Secret123!',
        ]);

        $response = $this->post('/login', [
            'email' => 'inactive@temupulang.local',
            'password' => 'Secret123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $owner = User::where('email', 'owner@temupulang.local')->first();

        $response = $this->actingAs($owner)->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_owner_can_access_owner_dashboard(): void
    {
        $owner = User::where('email', 'owner@temupulang.local')->first();

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Total Penjualan Hari Ini');
    }

    public function test_cashier_is_redirected_away_from_owner_dashboard_to_pos(): void
    {
        $cashier = User::where('email', 'cashier@temupulang.local')->first();

        $response = $this->actingAs($cashier)->get('/dashboard');

        // Cashier should be redirected to POS
        $response->assertRedirect('/pos');
    }

    public function test_both_owner_and_cashier_can_access_pos_terminal(): void
    {
        $owner = User::where('email', 'owner@temupulang.local')->first();
        $cashier = User::where('email', 'cashier@temupulang.local')->first();

        $this->actingAs($owner)->get('/pos')->assertStatus(200);
        $this->actingAs($cashier)->get('/pos')->assertStatus(200);
    }

    public function test_locale_switcher_updates_session_and_application_locale(): void
    {
        $response = $this->get('/locale/en');
        $response->assertSessionHas('locale', 'en');

        $response = $this->get('/locale/id');
        $response->assertSessionHas('locale', 'id');
    }

    public function test_owner_dashboard_livewire_component_renders(): void
    {
        $owner = User::where('email', 'owner@temupulang.local')->first();

        Livewire::actingAs($owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('Total Penjualan Hari Ini')
            ->assertSee('Simulasi Demo');
    }

    public function test_cashier_pos_livewire_component_renders_and_handles_cart(): void
    {
        $cashier = User::where('email', 'cashier@temupulang.local')->first();
        app(ShiftService::class)->openShift(user: $cashier, openingCash: 100000);
        $product = Product::where('is_sold_out', false)->first();

        Livewire::actingAs($cashier)
            ->test(CashierPos::class)
            ->assertStatus(200)
            ->assertCount('cart', 0)
            ->assertSee('Kopi Temu')
            ->assertSee('Kentang Goreng')
            ->call('selectProduct', $product->id)
            ->assertCount('cart', 1)
            ->call('increaseQty', 0)
            ->assertCount('cart', 1)
            ->call('clearCart')
            ->assertCount('cart', 0);
    }
}
