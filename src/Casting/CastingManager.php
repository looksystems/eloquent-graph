<?php

namespace Look\EloquentCypher\Casting;

use Look\EloquentCypher\Casting\Casters\ArrayCaster;
use Look\EloquentCypher\Casting\Casters\BooleanCaster;
use Look\EloquentCypher\Casting\Casters\CollectionCaster;
use Look\EloquentCypher\Casting\Casters\DateCaster;
use Look\EloquentCypher\Casting\Casters\DateTimeCaster;
use Look\EloquentCypher\Casting\Casters\DecimalCaster;
use Look\EloquentCypher\Casting\Casters\EncryptedArrayCaster;
use Look\EloquentCypher\Casting\Casters\EncryptedCaster;
use Look\EloquentCypher\Casting\Casters\EncryptedCollectionCaster;
use Look\EloquentCypher\Casting\Casters\EncryptedJsonCaster;
use Look\EloquentCypher\Casting\Casters\EncryptedObjectCaster;
use Look\EloquentCypher\Casting\Casters\FloatCaster;
use Look\EloquentCypher\Casting\Casters\HashedCaster;
use Look\EloquentCypher\Casting\Casters\IntegerCaster;
use Look\EloquentCypher\Casting\Casters\JsonCaster;
use Look\EloquentCypher\Casting\Casters\ObjectCaster;
use Look\EloquentCypher\Casting\Casters\StringCaster;
use Look\EloquentCypher\Casting\Casters\TimestampCaster;
use Look\EloquentCypher\Casting\Contracts\CasterInterface;

/**
 * Manages attribute casting for Neo4j models.
 * Provides a centralized system for registering and applying casters.
 */
class CastingManager
{
    /**
     * Registered casters indexed by type.
     *
     * @var array<string, CasterInterface>
     */
    protected array $casters = [];

    /**
     * Default caster instances.
     *
     * @var array<string, CasterInterface>
     */
    protected array $defaultCasters = [];

    /**
     * Create a new CastingManager instance.
     */
    public function __construct()
    {
        $this->registerDefaultCasters();
    }

    /**
     * Register the default casters.
     */
    protected function registerDefaultCasters(): void
    {
        $this->defaultCasters = [
            'int' => new IntegerCaster,
            'integer' => new IntegerCaster,
            'real' => new FloatCaster,
            'float' => new FloatCaster,
            'double' => new FloatCaster,
            'decimal' => new DecimalCaster,
            'string' => new StringCaster,
            'bool' => new BooleanCaster,
            'boolean' => new BooleanCaster,
            'object' => new ObjectCaster,
            'array' => new ArrayCaster,
            'json' => new JsonCaster,
            'collection' => new CollectionCaster,
            'date' => new DateCaster,
            'datetime' => new DateTimeCaster,
            'custom_datetime' => new DateTimeCaster,
            'timestamp' => new TimestampCaster,
            'hashed' => new HashedCaster,
            'encrypted' => new EncryptedCaster,
            'encrypted:array' => new EncryptedArrayCaster,
            'encrypted:collection' => new EncryptedCollectionCaster,
            'encrypted:json' => new EncryptedJsonCaster,
            'encrypted:object' => new EncryptedObjectCaster,
        ];

        // Register all default casters
        foreach ($this->defaultCasters as $type => $caster) {
            $this->registerCaster($type, $caster);
        }
    }

    /**
     * Register a custom caster for a specific type.
     */
    public function registerCaster(string $type, CasterInterface $caster): void
    {
        $this->casters[$type] = $caster;
    }

    /**
     * Get a caster for the specified type.
     */
    public function getCaster(string $type): ?CasterInterface
    {
        // Check for parameterized types (e.g., decimal:2)
        if (str_contains($type, ':')) {
            $baseType = explode(':', $type)[0];
            if (isset($this->casters[$baseType])) {
                return $this->casters[$baseType];
            }
        }

        return $this->casters[$type] ?? null;
    }

    /**
     * Cast a value to the specified type.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function cast(string $type, $value, array $options = [])
    {
        if ($value === null && ! in_array($type, ['bool', 'boolean'])) {
            return null;
        }

        $caster = $this->getCaster($type);
        if (! $caster) {
            throw \Look\EloquentCypher\Exceptions\GraphQueryException::invalidCastType($type, 'unknown');
        }

        // Extract parameters from type (e.g., decimal:2)
        if (str_contains($type, ':')) {
            $parts = explode(':', $type);
            array_shift($parts); // Remove the base type
            $options['parameters'] = $parts;
        }

        return $caster->cast($value, $options);
    }

    /**
     * Cast a value from the database.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function castFromDatabase(string $type, $value, array $options = [])
    {
        $caster = $this->getCaster($type);
        if (! $caster) {
            return $value;
        }

        // Extract parameters from type
        if (str_contains($type, ':')) {
            $parts = explode(':', $type);
            array_shift($parts);
            $options['parameters'] = $parts;
        }

        return $caster->castFromDatabase($value, $options);
    }

    /**
     * Cast a value for database storage.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function castForDatabase(string $type, $value, array $options = [])
    {
        $caster = $this->getCaster($type);
        if (! $caster) {
            return $value;
        }

        // Extract parameters from type
        if (str_contains($type, ':')) {
            $parts = explode(':', $type);
            array_shift($parts);
            $options['parameters'] = $parts;
        }

        return $caster->castForDatabase($value, $options);
    }

    /**
     * Check if a caster exists for the specified type.
     */
    public function hasCaster(string $type): bool
    {
        if (str_contains($type, ':')) {
            $baseType = explode(':', $type)[0];

            return isset($this->casters[$baseType]);
        }

        return isset($this->casters[$type]);
    }

    /**
     * Get all registered caster types.
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->casters);
    }

    /**
     * Reset to default casters only.
     */
    public function reset(): void
    {
        $this->casters = [];
        $this->registerDefaultCasters();
    }
}
