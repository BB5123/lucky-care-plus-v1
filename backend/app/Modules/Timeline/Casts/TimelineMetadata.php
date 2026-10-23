<?php

namespace App\Modules\Timeline\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * JSONB object cast for `timeline_entries.metadata`.
 *
 * Eloquent's plain `array` cast encodes an empty PHP array as `[]`, which the
 * `jsonb_typeof(metadata) = 'object'` check rejects. This cast always encodes
 * an object and always returns an array on read.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class TimelineMetadata implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $metadata = is_array($value) ? $value : [];

        return [$key => json_encode((object) $metadata, JSON_THROW_ON_ERROR)];
    }
}
