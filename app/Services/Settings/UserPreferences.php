<?php

namespace App\Services\Settings;

use App\Models\User;

/**
 * Per-user limits for browsing and the SQL editor. Each preference falls back
 * to the .env default; a saved choice is kept within the .env maximum.
 */
class UserPreferences
{
    /**
     * Preference key => [default config key, maximum config key, smallest allowed value].
     *
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    public const array LIMITS = [
        'page_size' => ['dbnova.limits.page_size', 'dbnova.limits.max.page_size', 10],
        'query_row_cap' => ['dbnova.limits.query_row_cap', 'dbnova.limits.max.query_row_cap', 10],
        'query_timeout_ms' => ['dbnova.limits.query_timeout_ms', 'dbnova.limits.max.query_timeout_ms', 1000],
    ];

    /**
     * The values this user works with: their own choice, clamped to the allowed range.
     *
     * @return array{page_size: int, query_row_cap: int, query_timeout_ms: int}
     */
    public function effective(?User $user): array
    {
        $saved = $user->preferences ?? [];
        $values = [];

        foreach (self::LIMITS as $key => [$defaultKey]) {
            $chosen = $saved[$key] ?? null;
            $values[$key] = is_int($chosen) ? $this->clamp($key, $chosen) : config()->integer($defaultKey);
        }

        /** @var array{page_size: int, query_row_cap: int, query_timeout_ms: int} $values */
        return $values;
    }

    /**
     * Make this request use the user's values wherever DBNova reads the limits.
     */
    public function apply(?User $user): void
    {
        $saved = $user->preferences ?? [];

        // Only the user's own choices are applied; the .env defaults stay as configured.
        foreach (self::LIMITS as $key => [$defaultKey]) {
            if (is_int($saved[$key] ?? null)) {
                config([$defaultKey => $this->clamp($key, $saved[$key])]);
            }
        }
    }

    /**
     * @return array{min: int, max: int, default: int}
     */
    public function range(string $key): array
    {
        [$defaultKey, $maxKey, $min] = self::LIMITS[$key];
        $max = max($min, config()->integer($maxKey));

        return ['min' => $min, 'max' => $max, 'default' => config()->integer($defaultKey)];
    }

    private function clamp(string $key, int $value): int
    {
        ['min' => $min, 'max' => $max] = $this->range($key);

        return min(max($value, $min), $max);
    }
}
