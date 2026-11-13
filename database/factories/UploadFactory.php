<?php

namespace Database\Factories;

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Upload>
 */
class UploadFactory extends Factory
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
            'original_name' => 'shop.sql',
            'format' => 'sql',
            'size' => 1024,
            'chunk_size' => 1024,
            'total_chunks' => 1,
            'status' => UploadStatus::Uploading,
        ];
    }

    /**
     * A finished upload.
     */
    public function uploaded(): static
    {
        return $this->state(fn () => [
            'status' => UploadStatus::Uploaded,
            'completed_at' => now(),
        ]);
    }
}
