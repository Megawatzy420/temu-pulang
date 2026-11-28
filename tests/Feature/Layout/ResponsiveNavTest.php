<?php

namespace Tests\Feature\Layout;

use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class ResponsiveNavTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
    }

    public function test_desktop_toggle_and_mobile_menu_buttons_render_with_translated_aria_labels_in_id_and_en(): void
    {
        foreach (['id', 'en'] as $locale) {
            app()->setLocale($locale);

            $response = $this->actingAs($this->owner)
                ->withSession(['locale' => $locale])
                ->get(route('dashboard'));

            $response->assertOk();
            $html = $response->getContent();

            // Desktop toggle button attributes & translated labels
            $this->assertStringContainsString('id="desktop-sidebar-toggle"', $html);
            $this->assertStringContainsString('aria-controls="app-sidebar"', $html);
            $this->assertStringContainsString('aria-expanded="true"', $html);
            $this->assertStringContainsString(
                'aria-label="'.__('messages.nav.collapse_sidebar', [], $locale).'"',
                $html
            );
            $this->assertStringContainsString(
                'data-collapse-label="'.__('messages.nav.collapse_sidebar', [], $locale).'"',
                $html
            );
            $this->assertStringContainsString(
                'data-expand-label="'.__('messages.nav.expand_sidebar', [], $locale).'"',
                $html
            );

            // Mobile/tablet top bar hamburger button attributes & translated labels
            $this->assertStringContainsString('id="mobile-menu-toggle"', $html);
            $this->assertStringContainsString('aria-expanded="false"', $html);
            $this->assertStringContainsString(
                'aria-label="'.__('messages.nav.open_menu', [], $locale).'"',
                $html
            );

            // Mobile/tablet drawer close button attributes & translated labels
            $this->assertStringContainsString('id="mobile-drawer-close"', $html);
            $this->assertStringContainsString(
                'aria-label="'.__('messages.nav.close_menu', [], $locale).'"',
                $html
            );

            // Sidebar landmark label
            $this->assertStringContainsString(
                'aria-label="'.__('messages.nav.sidebar_label', [], $locale).'"',
                $html
            );
        }
    }

    public function test_expected_responsive_classes_overlay_and_local_storage_try_catch_exist(): void
    {
        $response = $this->actingAs($this->owner)->get(route('dashboard'));
        $response->assertOk();
        $html = $response->getContent();

        // Off-canvas drawer below lg (1024px) & desktop collapsible rail at lg
        $this->assertStringContainsString('id="app-sidebar"', $html);
        $this->assertStringContainsString('-translate-x-full', $html);
        $this->assertStringContainsString('lg:translate-x-0', $html);
        $this->assertStringContainsString('lg:static', $html);
        $this->assertStringContainsString('lg:w-64', $html);
        $this->assertStringContainsString('lg:w-20', $html);

        // Mobile & tablet overlay and minimum 44x44px touch target classes
        $this->assertStringContainsString('id="mobile-sidebar-backdrop"', $html);
        $this->assertStringContainsString('bg-coffee-950/60', $html);
        $this->assertStringContainsString('lg:hidden', $html);
        $this->assertStringContainsString('min-w-11 min-h-11', $html);

        // Reduced motion and print classes
        $this->assertStringContainsString('motion-reduce:transition-none', $html);
        $this->assertStringContainsString('no-print', $html);

        // Keyboard Escape, resize across 1024px boundary, and Livewire navigation close listeners
        $this->assertStringContainsString('x-on:keydown.escape.window', $html);
        $this->assertStringContainsString('x-on:resize.window="handleResize()"', $html);
        $this->assertStringContainsString('x-on:livewire:navigated.window="closeMobileDrawer(false)"', $html);

        // localStorage key 'tp.sidebar.collapsed' wrapped in try/catch
        $this->assertStringContainsString('tp.sidebar.collapsed', $html);
        $this->assertMatchesRegularExpression(
            '/try\s*\{[^}]*localStorage\.getItem\(\'tp\.sidebar\.collapsed\'\)[^}]*\}\s*catch\s*\(/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/try\s*\{[^}]*localStorage\.setItem\(\'tp\.sidebar\.collapsed\'[^}]*\}\s*catch\s*\(/s',
            $html
        );
    }

    public function test_all_sidebar_labels_and_low_stock_badge_present_for_owner_and_cashier_in_id_and_en(): void
    {
        // Trigger low-stock badge (count >= 1)
        $cat = IngredientCategory::create(['name' => 'Nav Test Cat', 'slug' => 'nav-test-cat', 'sort_order' => 99]);
        $ing = Ingredient::create([
            'category_id' => $cat->id,
            'sku' => 'NAV-LOW-1',
            'name' => 'Low Stock Syrup',
            'unit' => 'ml',
            'cost_per_unit' => 500,
            'min_stock_alert' => '50.000',
            'is_active' => true,
        ]);
        IngredientBalance::create([
            'branch_id' => null,
            'ingredient_id' => $ing->id,
            'current_stock' => '10.000',
        ]);

        $ownerExpectedKeys = [
            'messages.nav.dashboard',
            'messages.nav.pos',
            'messages.nav.orders_queue',
            'messages.nav.shifts',
            'messages.nav.section_owner_admin',
            'messages.nav.settings',
            'messages.nav.activity_logs',
            'messages.nav.print_jobs',
            'messages.nav.section_menu_catalog',
            'messages.nav.product_list',
            'messages.nav.menu_categories',
            'messages.nav.modifier_groups',
            'messages.nav.section_inventory',
            'messages.nav.ingredients_stock',
            'messages.nav.menu_recipes',
            'messages.nav.stock',
            'messages.nav.stock_history',
            'messages.nav.reports',
            'messages.nav.analytics',
            'messages.nav.role_owner',
            'messages.nav.logout',
        ];

        $cashierAllowedKeys = [
            'messages.nav.pos',
            'messages.nav.orders_queue',
            'messages.nav.shifts',
            'messages.nav.role_cashier',
            'messages.nav.logout',
        ];

        $ownerOnlyKeys = [
            'messages.nav.section_owner_admin',
            'messages.nav.settings',
            'messages.nav.activity_logs',
            'messages.nav.print_jobs',
            'messages.nav.section_menu_catalog',
            'messages.nav.product_list',
            'messages.nav.menu_categories',
            'messages.nav.modifier_groups',
            'messages.nav.section_inventory',
            'messages.nav.ingredients_stock',
            'messages.nav.menu_recipes',
            'messages.nav.stock',
            'messages.nav.stock_history',
            'messages.nav.reports',
            'messages.nav.analytics',
        ];

        foreach (['id', 'en'] as $locale) {
            app()->setLocale($locale);

            // Owner view
            $ownerResponse = $this->actingAs($this->owner)
                ->withSession(['locale' => $locale])
                ->get(route('dashboard'));
            $ownerResponse->assertOk();

            foreach ($ownerExpectedKeys as $key) {
                $ownerResponse->assertSee(__($key, [], $locale));
            }

            // Low-stock badge rendered on /inventory/stock link
            $ownerHtml = $ownerResponse->getContent();
            $this->assertMatchesRegularExpression(
                '/bg-rose-500 text-white"\s*>\s*[1-9]\d*\s*<\/span>/',
                $ownerHtml
            );

            // Cashier view
            $cashierResponse = $this->actingAs($this->cashier)
                ->withSession(['locale' => $locale])
                ->get(route('pos'));
            $cashierResponse->assertOk();

            foreach ($cashierAllowedKeys as $key) {
                $cashierResponse->assertSee(__($key, [], $locale));
            }

            foreach ($ownerOnlyKeys as $key) {
                $cashierResponse->assertDontSee(__($key, [], $locale));
            }
        }
    }

    /**
     * Allowed Alpine.js and Livewire magic identifiers inside x-*, :*, @*, and wire:* attributes.
     *
     * @var list<string>
     */
    public const ALLOWED_ALPINE_LIVEWIRE_MAGICS = [
        '$wire',
        '$el',
        '$refs',
        '$store',
        '$nextTick',
        '$dispatch',
        '$watch',
        '$event',
        '$root',
        '$data',
        '$id',
        '$set',
        '$toggle',
        '$refresh',
        '$parent',
        '$errors',
        '$js',
    ];

    /**
     * Scan a single Blade file for PHP-style $variables inside Alpine or Livewire attributes on plain HTML elements.
     *
     * @return list<string>
     */
    public static function scanBladeFileForInvalidAlpineVariables(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $violations = [];

        // Strip Blade comments {{-- ... --}} and Blade echo blocks {{ ... }} / {!! ... !!}
        // because PHP variables inside Blade echos are evaluated server-side by Blade.
        $sanitized = preg_replace('/\{\{--.*?--\}\}/s', '', $content) ?? $content;
        $sanitized = preg_replace('/\{\{.*?\}\}/s', '__BLADE_ECHO__', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\{!!.*?!!\}/s', '__BLADE_RAW__', $sanitized) ?? $sanitized;
        // Strip @if/@elseif/@foreach/@php directives so only HTML tags remain
        $sanitized = preg_replace('/@(?:php|if|elseif|foreach|for|while|switch|case|isset|empty|auth|guest)\b[^\n]*/', '', $sanitized) ?? $sanitized;

        // Match opening HTML tags (excluding Blade components <x-...>)
        if (preg_match_all('/<([a-zA-Z][a-zA-Z0-9:-]*)\b((?:[^>"\']|"[^"]*"|\'[^\']*\'|\n)*)>/s', $sanitized, $tagMatches, PREG_SET_ORDER)) {
            foreach ($tagMatches as $tagMatch) {
                $tagName = strtolower($tagMatch[1]);
                if (str_starts_with($tagName, 'x-')) {
                    continue;
                }

                $attrBlock = $tagMatch[2];

                // Match Alpine & Livewire attributes: x-*, :*, @*, wire:*
                if (preg_match_all('/(?:^|\s)((?:x-[a-zA-Z0-9:._-]+|:[a-zA-Z0-9:._-]+|@[a-zA-Z0-9:._-]+|wire:[a-zA-Z0-9:._-]+))\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $attrBlock, $attrMatches, PREG_SET_ORDER)) {
                    foreach ($attrMatches as $attrMatch) {
                        $attrName = $attrMatch[1];
                        $attrValue = $attrMatch[2] !== '' ? $attrMatch[2] : ($attrMatch[3] ?? '');

                        if (preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*/', $attrValue, $varMatches)) {
                            foreach ($varMatches[0] as $varName) {
                                if (! in_array($varName, self::ALLOWED_ALPINE_LIVEWIRE_MAGICS, true)) {
                                    $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $filePath);
                                    $violations[] = "{$relativePath} (<{$tagName} {$attrName}>): unexpected {$varName}";
                                }
                            }
                        }
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Reusable language key parity and HTML-entity check for lang/id/messages.php and lang/en/messages.php.
     *
     * @param  list<string>  $requiredKeys
     */
    public static function assertLanguageParityAndNoHtmlEntities(TestCase $test, array $requiredKeys = []): void
    {
        $id = Arr::dot(require base_path('lang/id/messages.php'));
        $en = Arr::dot(require base_path('lang/en/messages.php'));

        $test->assertSame(array_keys($id), array_keys($en));

        foreach ($requiredKeys as $key) {
            $test->assertArrayHasKey($key, $id);
            $test->assertArrayHasKey($key, $en);
            $test->assertNotEmpty($id[$key]);
            $test->assertNotEmpty($en[$key]);
            $test->assertDoesNotMatchRegularExpression('/&[a-zA-Z0-9#]+;/', (string) $id[$key]);
            $test->assertDoesNotMatchRegularExpression('/&[a-zA-Z0-9#]+;/', (string) $en[$key]);
        }
    }

    public function test_no_blade_view_contains_php_style_dollar_variable_in_alpine_or_livewire_attributes(): void
    {
        $viewsPath = resource_path('views');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsPath));
        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $violations = array_merge(
                $violations,
                self::scanBladeFileForInvalidAlpineVariables($file->getPathname())
            );
        }

        $this->assertSame(
            [],
            $violations,
            "Found unexpected PHP-style \$variable inside Alpine/Livewire attributes:\n".implode("\n", $violations)
        );
    }

    public function test_language_files_have_identical_keys_and_no_html_entities_in_nav(): void
    {
        self::assertLanguageParityAndNoHtmlEntities(
            $this,
            ['nav.open_menu', 'nav.close_menu', 'nav.collapse_sidebar', 'nav.expand_sidebar', 'nav.sidebar_label']
        );
    }
}
