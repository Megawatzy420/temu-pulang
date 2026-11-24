<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $action = fake()->word();
        $group = fake()->randomElement(['pos', 'products', 'inventory', 'reports', 'settings']);

        return [
            'name' => ucfirst($action).' '.ucfirst($group),
            'slug' => $group.'.'.Str::slug($action),
            'group' => $group,
            'description' => fake()->sentence(),
        ];
    }
}
