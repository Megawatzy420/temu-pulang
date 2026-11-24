<?php

namespace Tests\Feature;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductModifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_modifier_group_translations_work(): void
    {
        $group = ModifierGroup::create([
            'slug' => 'sugar-level',
            'min_selections' => 1,
            'max_selections' => 1,
            'is_required' => true,
        ]);

        $group->translations()->create([
            'locale' => 'en',
            'name' => 'Sugar Level',
            'description' => 'Sweetness preference',
        ]);

        $group->translations()->create([
            'locale' => 'id',
            'name' => 'Tingkat Gula',
            'description' => 'Pilihan kemanisan',
        ]);

        app()->setLocale('id');
        $this->assertEquals('Tingkat Gula', $group->name);
        $this->assertEquals('Pilihan kemanisan', $group->description);

        app()->setLocale('en');
        $this->assertEquals('Sugar Level', $group->name);
        $this->assertEquals('Sweetness preference', $group->description);
    }

    public function test_free_and_paid_modifiers(): void
    {
        $group = ModifierGroup::create([
            'slug' => 'toppings',
            'min_selections' => 0,
            'max_selections' => 3,
            'is_required' => false,
        ]);

        $freeModifier = Modifier::create([
            'modifier_group_id' => $group->id,
            'price' => 0,
            'sort_order' => 1,
        ]);
        $freeModifier->translations()->create(['locale' => 'en', 'name' => 'Normal Ice']);

        $paidModifier = Modifier::create([
            'modifier_group_id' => $group->id,
            'price' => 3000,
            'sort_order' => 2,
        ]);
        $paidModifier->translations()->create(['locale' => 'en', 'name' => 'Extra Lemon Slice']);

        $this->assertTrue($freeModifier->is_free);
        $this->assertEquals(0, $freeModifier->price);

        $this->assertFalse($paidModifier->is_free);
        $this->assertEquals(3000, $paidModifier->price);

        $this->assertTrue(Modifier::free()->get()->contains($freeModifier));
        $this->assertFalse(Modifier::free()->get()->contains($paidModifier));

        $this->assertTrue(Modifier::paid()->get()->contains($paidModifier));
        $this->assertFalse(Modifier::paid()->get()->contains($freeModifier));
    }

    public function test_required_vs_optional_modifier_groups(): void
    {
        $requiredGroup = ModifierGroup::create([
            'slug' => 'ice-level',
            'min_selections' => 1,
            'max_selections' => 1,
            'is_required' => true,
        ]);

        $optionalGroup = ModifierGroup::create([
            'slug' => 'extra-sauce',
            'min_selections' => 0,
            'max_selections' => 5,
            'is_required' => false,
        ]);

        $this->assertTrue($requiredGroup->is_required);
        $this->assertEquals(1, $requiredGroup->min_selections);
        $this->assertEquals(1, $requiredGroup->max_selections);

        $this->assertFalse($optionalGroup->is_required);
        $this->assertEquals(0, $optionalGroup->min_selections);
        $this->assertEquals(5, $optionalGroup->max_selections);

        $requiredGroups = ModifierGroup::required()->get();
        $this->assertTrue($requiredGroups->contains($requiredGroup));
        $this->assertFalse($requiredGroups->contains($optionalGroup));
    }

    public function test_product_modifier_group_relationship_with_sort_order(): void
    {
        $product = Product::create([
            'sku' => 'PRD-LT-001',
            'base_price' => 12000,
            'has_variants' => true,
        ]);

        $sugarGroup = ModifierGroup::create(['slug' => 'sugar', 'is_required' => true]);
        $iceGroup = ModifierGroup::create(['slug' => 'ice', 'is_required' => true]);

        $product->modifierGroups()->attach([
            $sugarGroup->id => ['sort_order' => 1],
            $iceGroup->id => ['sort_order' => 2],
        ]);

        $this->assertCount(2, $product->modifierGroups);
        $this->assertEquals('sugar', $product->modifierGroups->first()->slug);
        $this->assertEquals(1, $product->modifierGroups->first()->pivot->sort_order);

        $this->assertTrue($sugarGroup->products->contains($product));
    }

    public function test_modifier_and_group_soft_deletes(): void
    {
        $group = ModifierGroup::create(['slug' => 'temp-group']);
        $modifier = Modifier::create(['modifier_group_id' => $group->id, 'price' => 1000]);

        $modifier->delete();
        $this->assertSoftDeleted($modifier);

        $group->delete();
        $this->assertSoftDeleted($group);

        $this->assertNull(Modifier::find($modifier->id));
        $this->assertNull(ModifierGroup::find($group->id));
        $this->assertNotNull(Modifier::withTrashed()->find($modifier->id));
        $this->assertNotNull(ModifierGroup::withTrashed()->find($group->id));
    }
}
