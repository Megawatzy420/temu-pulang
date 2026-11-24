<?php

namespace Database\Seeders;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ModifierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Sugar Level (Required free customization: min 1, max 1)
        $sugarGroup = ModifierGroup::updateOrCreate(
            ['slug' => 'sugar-level'],
            [
                'min_selections' => 1,
                'max_selections' => 1,
                'is_required' => true,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
        $sugarGroup->translations()->updateOrCreate(
            ['locale' => 'en'],
            ['name' => 'Sugar Level', 'description' => 'Select desired sweetness']
        );
        $sugarGroup->translations()->updateOrCreate(
            ['locale' => 'id'],
            ['name' => 'Tingkat Gula', 'description' => 'Pilih tingkat kemanisan']
        );

        $sugarOptions = [
            ['en' => 'Normal Sugar (100%)', 'id' => 'Gula Normal (100%)', 'price' => 0, 'order' => 1],
            ['en' => 'Less Sugar (50%)', 'id' => 'Sedikit Gula (50%)', 'price' => 0, 'order' => 2],
            ['en' => 'No Sugar (0%)', 'id' => 'Tanpa Gula (0%)', 'price' => 0, 'order' => 3],
        ];
        foreach ($sugarOptions as $opt) {
            $mod = Modifier::updateOrCreate(
                [
                    'modifier_group_id' => $sugarGroup->id,
                    'sort_order' => $opt['order'],
                ],
                [
                    'price' => $opt['price'],
                    'is_active' => true,
                    'is_sold_out' => false,
                ]
            );
            $mod->translations()->updateOrCreate(['locale' => 'en'], ['name' => $opt['en']]);
            $mod->translations()->updateOrCreate(['locale' => 'id'], ['name' => $opt['id']]);
        }

        // 2. Ice Level (Required free customization: min 1, max 1)
        $iceGroup = ModifierGroup::updateOrCreate(
            ['slug' => 'ice-level'],
            [
                'min_selections' => 1,
                'max_selections' => 1,
                'is_required' => true,
                'is_active' => true,
                'sort_order' => 2,
            ]
        );
        $iceGroup->translations()->updateOrCreate(
            ['locale' => 'en'],
            ['name' => 'Ice Level', 'description' => 'Select ice preference']
        );
        $iceGroup->translations()->updateOrCreate(
            ['locale' => 'id'],
            ['name' => 'Tingkat Es', 'description' => 'Pilih jumlah es batu']
        );

        $iceOptions = [
            ['en' => 'Normal Ice', 'id' => 'Es Normal', 'price' => 0, 'order' => 1],
            ['en' => 'Less Ice', 'id' => 'Sedikit Es', 'price' => 0, 'order' => 2],
            ['en' => 'No Ice', 'id' => 'Tanpa Es', 'price' => 0, 'order' => 3],
        ];
        foreach ($iceOptions as $opt) {
            $mod = Modifier::updateOrCreate(
                [
                    'modifier_group_id' => $iceGroup->id,
                    'sort_order' => $opt['order'],
                ],
                [
                    'price' => $opt['price'],
                    'is_active' => true,
                    'is_sold_out' => false,
                ]
            );
            $mod->translations()->updateOrCreate(['locale' => 'en'], ['name' => $opt['en']]);
            $mod->translations()->updateOrCreate(['locale' => 'id'], ['name' => $opt['id']]);
        }

        // 3. Extra Add-ons (Optional paid customizations: min 0, max 5)
        $extraGroup = ModifierGroup::updateOrCreate(
            ['slug' => 'extra-addons'],
            [
                'min_selections' => 0,
                'max_selections' => 5,
                'is_required' => false,
                'is_active' => true,
                'sort_order' => 3,
            ]
        );
        $extraGroup->translations()->updateOrCreate(
            ['locale' => 'en'],
            ['name' => 'Extra Add-ons', 'description' => 'Optional extra toppings and sides']
        );
        $extraGroup->translations()->updateOrCreate(
            ['locale' => 'id'],
            ['name' => 'Tambahan Ekstra', 'description' => 'Topping dan porsi tambahan opsional']
        );

        $extraOptions = [
            ['en' => 'Extra Lemon Slice', 'id' => 'Ekstra Irisan Lemon', 'price' => 3000, 'order' => 1], // +Rp 3.000
            ['en' => 'Extra Sauce', 'id' => 'Ekstra Saus Spesial', 'price' => 2000, 'order' => 2], // +Rp 2.000
            ['en' => 'Extra Rice', 'id' => 'Ekstra Nasi Putih', 'price' => 5000, 'order' => 3], // +Rp 5.000
        ];
        foreach ($extraOptions as $opt) {
            $mod = Modifier::updateOrCreate(
                [
                    'modifier_group_id' => $extraGroup->id,
                    'sort_order' => $opt['order'],
                ],
                [
                    'price' => $opt['price'],
                    'is_active' => true,
                    'is_sold_out' => false,
                ]
            );
            $mod->translations()->updateOrCreate(['locale' => 'en'], ['name' => $opt['en']]);
            $mod->translations()->updateOrCreate(['locale' => 'id'], ['name' => $opt['id']]);
        }

        // 4. Attach Modifier Groups to Products
        $lemonTea = Product::where('sku', 'PRD-LT-001')->first();
        if ($lemonTea) {
            $lemonTea->modifierGroups()->syncWithoutDetaching([
                $sugarGroup->id => ['sort_order' => 1],
                $iceGroup->id => ['sort_order' => 2],
                $extraGroup->id => ['sort_order' => 3],
            ]);
        }

        $coffee = Product::where('sku', 'PRD-CF-001')->first();
        if ($coffee) {
            $coffee->modifierGroups()->syncWithoutDetaching([
                $sugarGroup->id => ['sort_order' => 1],
                $iceGroup->id => ['sort_order' => 2],
            ]);
        }

        $chickenKatsu = Product::where('sku', 'PRD-CK-001')->first();
        if ($chickenKatsu) {
            $chickenKatsu->modifierGroups()->syncWithoutDetaching([
                $extraGroup->id => ['sort_order' => 1],
            ]);
        }
    }
}
