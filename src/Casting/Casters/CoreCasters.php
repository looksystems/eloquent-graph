<?php

namespace Look\EloquentCypher\Casting\Casters;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * Float caster.
 */
class FloatCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?float
    {
        return $value === null ? null : (float) $value;
    }

    public function castForDatabase($value, array $options = []): ?float
    {
        return $value === null ? null : (float) $value;
    }
}

/**
 * Decimal caster with precision support.
 */
class DecimalCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        $decimals = (int) ($options['parameters'][0] ?? 2);

        return number_format((float) $value, $decimals, '.', '');
    }

    public function castForDatabase($value, array $options = []): ?float
    {
        return $value === null ? null : (float) $value;
    }
}

/**
 * String caster.
 */
class StringCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

/**
 * Boolean caster.
 */
class BooleanCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return $value > 0;
        }

        if (is_string($value)) {
            $lower = strtolower($value);

            return in_array($lower, ['true', 'yes', '1', 'on'], true);
        }

        return (bool) $value;
    }

    public function castForDatabase($value, array $options = []): bool
    {
        return (bool) $value;
    }
}

/**
 * Object caster (uses JSON encoding).
 */
class ObjectCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = [])
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return json_decode($value, false);
        }

        return (object) $value;
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        // Use flags to ensure clean JSON for APOC compatibility
        // JSON_UNESCAPED_SLASHES: Don't escape forward slashes
        // JSON_UNESCAPED_UNICODE: Keep unicode characters as-is
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Array caster.
 */
class ArrayCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [$value];
        }

        return (array) $value;
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        // Use flags to ensure clean JSON for APOC compatibility
        // JSON_UNESCAPED_SLASHES: Don't escape forward slashes
        // JSON_UNESCAPED_UNICODE: Keep unicode characters as-is
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

/**
 * JSON caster.
 */
class JsonCaster extends ArrayCaster
{
    // JSON caster is essentially the same as ArrayCaster
}

/**
 * Collection caster.
 */
class CollectionCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?Collection
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Collection) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return collect($decoded);
        }

        return collect($value);
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Collection) {
            return $value->toJson();
        }

        // Use flags to ensure clean JSON for APOC compatibility
        // JSON_UNESCAPED_SLASHES: Don't escape forward slashes
        // JSON_UNESCAPED_UNICODE: Keep unicode characters as-is
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Date caster.
 */
class DateCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        $format = $options['format'] ?? null;
        if ($format) {
            return Carbon::createFromFormat($format, $value);
        }

        return Carbon::parse($value)->startOfDay();
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! ($value instanceof Carbon)) {
            $value = Carbon::parse($value);
        }

        return $value->format('Y-m-d');
    }
}

/**
 * DateTime caster.
 */
class DateTimeCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        $format = $options['format'] ?? $options['parameters'][0] ?? null;
        if ($format) {
            return Carbon::createFromFormat($format, $value);
        }

        return Carbon::parse($value);
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! ($value instanceof Carbon)) {
            $value = Carbon::parse($value);
        }

        $format = $options['format'] ?? $options['parameters'][0] ?? 'Y-m-d H:i:s';

        return $value->format($format);
    }
}

/**
 * Timestamp caster.
 */
class TimestampCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return Carbon::parse($value)->timestamp;
    }

    public function castForDatabase($value, array $options = []): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return Carbon::parse($value)->timestamp;
    }
}

/**
 * Hashed caster (one-way hashing).
 */
class HashedCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = [])
    {
        // Hashed values are not meant to be retrieved
        return $value;
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        // Don't re-hash already hashed values
        if (strlen($value) === 60 && strpos($value, '$2y$') === 0) {
            return $value;
        }

        return Hash::make($value);
    }
}

/**
 * Encrypted caster.
 */
class EncryptedCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            // If decryption fails, return the original value
            return $value;
        }
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($value);
    }
}

/**
 * Encrypted array caster.
 */
class EncryptedArrayCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);

            return json_decode($decrypted, true);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode($value));
    }
}

/**
 * Encrypted collection caster.
 */
class EncryptedCollectionCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?Collection
    {
        if ($value === null) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);

            return collect(json_decode($decrypted, true));
        } catch (\Exception $e) {
            return collect();
        }
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Collection) {
            $value = $value->toArray();
        }

        return Crypt::encryptString(json_encode($value));
    }
}

/**
 * Encrypted JSON caster.
 */
class EncryptedJsonCaster extends EncryptedArrayCaster
{
    // Same as EncryptedArrayCaster
}

/**
 * Encrypted object caster.
 */
class EncryptedObjectCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = [])
    {
        if ($value === null) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);

            return json_decode($decrypted, false);
        } catch (\Exception $e) {
            return new \stdClass;
        }
    }

    public function castForDatabase($value, array $options = []): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode($value));
    }
}
