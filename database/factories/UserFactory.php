<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'branch_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Assign owner role to the user after creation.
     */
    public function owner(): static
    {
        return $this->afterCreating(function (User $user) {
            $role = Role::firstOrCreate(
                ['slug' => 'owner'],
                ['name' => 'Owner', 'description' => 'Business owner with full access', 'is_system' => true]
            );
            $user->assignRole($role);
        });
    }

    /**
     * Assign cashier role to the user after creation.
     */
    public function cashier(): static
    {
        return $this->afterCreating(function (User $user) {
            $role = Role::firstOrCreate(
                ['slug' => 'cashier'],
                ['name' => 'Cashier', 'description' => 'Cashier with POS and inventory view permissions', 'is_system' => true]
            );
            $user->assignRole($role);
        });
    }
}
