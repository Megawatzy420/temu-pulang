<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'shift_number' => 'SFT-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'user_id' => User::factory(),
            'closed_by_user_id' => null,
            'status' => 'open',
            'opened_at' => now(),
            'closed_at' => null,
            'opening_cash' => 200000, // Rp 200.000 float
            'expected_cash' => null,
            'actual_cash' => null,
            'discrepancy' => null,
            'opening_note' => 'Shift float verified',
            'closing_note' => null,
        ];
    }

    /**
     * Closed state.
     */
    public function closed(?int $actualCash = null): static
    {
        return $this->state(function (array $attributes) use ($actualCash) {
            $opening = $attributes['opening_cash'] ?? 200000;
            $actual = $actualCash ?? $opening;
            $discrepancy = $actual - $opening;

            return [
                'status' => 'closed',
                'closed_at' => now(),
                'expected_cash' => $opening,
                'actual_cash' => $actual,
                'discrepancy' => $discrepancy,
                'closing_note' => $discrepancy === 0 ? 'Shift closed balanced' : 'Shift closed with variance',
            ];
        });
    }
}
