<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
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
            'mysql_username' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('username'),
            'action' => 'database.create',
            'database_name' => fake()->unique()->slug(2, false),
            'table_name' => null,
            'details' => null,
            'succeeded' => true,
            'error' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Indicate that the audited operation failed.
     */
    public function failed(string $error = 'MySQL error: Access denied'): static
    {
        return $this->state(fn () => [
            'succeeded' => false,
            'error' => $error,
        ]);
    }
}
