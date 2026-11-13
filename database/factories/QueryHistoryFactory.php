<?php

namespace Database\Factories;

use App\Models\QueryHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryHistory>
 */
class QueryHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'database_name' => 'shop',
            'sql' => 'SELECT * FROM orders LIMIT '.fake()->numberBetween(1, 100),
            'statement_count' => 1,
            'status' => 'ok',
            'error' => null,
            'duration_ms' => fake()->randomFloat(1, 0, 500),
            'name' => null,
            'saved_at' => null,
        ];
    }

    /**
     * A saved query with a name.
     */
    public function saved(string $name = 'Paid orders'): static
    {
        return $this->state(fn () => [
            'name' => $name,
            'saved_at' => now(),
        ]);
    }
}
