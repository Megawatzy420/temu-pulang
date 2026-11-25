<?php

use App\Http\Controllers\AuthController;
use App\Livewire\ActivityLogs\ActivityLogIndex;
use App\Livewire\Dashboard\OwnerDashboard;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\RecipeManager;
use App\Livewire\Menu\CategoryManager;
use App\Livewire\Menu\ModifierManager;
use App\Livewire\Menu\ProductForm;
use App\Livewire\Menu\ProductIndex;
use App\Livewire\Orders\OrderIndex;
use App\Livewire\Orders\OrderRefund;
use App\Livewire\Pos\CashierPos;
use App\Livewire\Printing\PrintJobIndex;
use App\Livewire\Settings\ShopSettingsManager;
use App\Livewire\Shifts\ShiftManager;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Public & Guest Routes
Route::get('/', function () {
    if (Auth::check()) {
        /** @var User $user */
        $user = Auth::user();

        return $user->isOwner()
            ? redirect()->route('dashboard')
            : redirect()->route('pos');
    }

    return redirect()->route('login');
})->name('home');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Locale Switcher
Route::get('/locale/{locale}', [AuthController::class, 'setLocale'])->name('locale.set');

// Authenticated Application Routes with RBAC
Route::middleware('auth')->group(function () {
    // Owner Only
    Route::get('/dashboard', OwnerDashboard::class)
        ->middleware('role:owner')
        ->name('dashboard');

    Route::get('/settings', ShopSettingsManager::class)
        ->middleware('role:owner')
        ->name('settings');

    Route::get('/activity-logs', ActivityLogIndex::class)
        ->middleware('role:owner')
        ->name('activity-logs');

    Route::get('/printing/jobs', PrintJobIndex::class)
        ->middleware('role:owner')
        ->name('printing.jobs');

    Route::get('/orders/{order}/refund', OrderRefund::class)
        ->middleware('role:owner')
        ->name('orders.refund');

    // Menu Management Backoffice: Owner Only
    Route::prefix('menu')->middleware('role:owner')->name('menu.')->group(function () {
        Route::get('/categories', CategoryManager::class)->name('categories');
        Route::get('/modifiers', ModifierManager::class)->name('modifiers');
        Route::get('/products', ProductIndex::class)->name('products');
        Route::get('/products/create', ProductForm::class)->name('products.create');
        Route::get('/products/{id}/edit', ProductForm::class)->name('products.edit');
    });

    // Inventory Management: Owner Only
    Route::prefix('inventory')->middleware('role:owner')->name('inventory.')->group(function () {
        Route::get('/ingredients', IngredientManager::class)->name('ingredients');
        Route::get('/recipes', RecipeManager::class)->name('recipes');
    });

    // POS Terminal & Shift Operations: Accessible to both Owner and Cashier
    Route::get('/pos', CashierPos::class)
        ->middleware('role:owner,cashier')
        ->name('pos');

    Route::get('/orders', OrderIndex::class)
        ->middleware('role:owner,cashier')
        ->name('orders.index');

    Route::get('/shifts', ShiftManager::class)
        ->middleware('role:owner,cashier')
        ->name('shifts');
});
