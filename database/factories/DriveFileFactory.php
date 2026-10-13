<?php

namespace Database\Factories;

use App\Models\DriveFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DriveFile>
 */
class DriveFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->word().'.pdf',
            'mime' => 'application/pdf',
            'size' => 1024 * 1024,
            'storage' => 'uploadthing',
            'storage_key' => 'utkey'.Str::random(20),
            'custom_id' => 'drive-'.Str::random(24),
            'status' => DriveFile::READY,
            'visibility' => 'private',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => DriveFile::PENDING]);
    }
}
