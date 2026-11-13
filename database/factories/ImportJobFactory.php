<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\ImportJob;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportJob>
 */
class ImportJobFactory extends Factory
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
            'upload_id' => fn (array $attributes) => Upload::factory()->uploaded()->create(['user_id' => $attributes['user_id']])->id,
            'database_name' => 'shop',
            'status' => ImportStatus::Pending,
        ];
    }
}
