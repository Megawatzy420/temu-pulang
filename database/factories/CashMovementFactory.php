<?php

namespace Database\Factories;

use App\Models\CashMovement;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'user_id' => User::factory(),
            'type' => 'in',
            'amount' => 50000,
            'category' => 'capital_add',
            'description' => 'Tambah kas awal',
            'approved_by_user_id' => null,
            'is_reversal' => false,
            'reversed_movement_id' => null,
            'reversal_reason' => null,
        ];
    }

    /**
     * Outflow state.
     */
    public function out(): static
    {
        return $this->state(fn () => [
            'type' => 'out',
            'category' => 'expense',
            'description' => 'Beli es batu warung',
        ]);
    }
}
