<?php

namespace App\Domain\Settings;

use Illuminate\Support\Facades\DB;

/**
 * Admin-editable settings stored as JSON in app_settings (read once per request).
 */
class AppSettings
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->values ??= DB::table('app_settings')->pluck('value', 'key')
            ->map(fn ($v) => json_decode((string) $v, true))
            ->all();

        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );
        $this->values = null;
    }
}
