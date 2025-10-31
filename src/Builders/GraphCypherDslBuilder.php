<?php

namespace Look\EloquentCypher\Builders;

use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use WikibaseSolutions\CypherDSL\Query;

class GraphCypherDslBuilder
{
    use GraphPatternHelpers, Macroable;

    protected Query $query;

    protected \Look\EloquentCypher\GraphConnection $connection;

    protected array $parameters = [];

    protected ?string $model = null;

    protected ?\Illuminate\Database\Eloquent\Model $sourceNode = null;

    public function __construct(\Look\EloquentCypher\GraphConnection $connection)
    {
        $this->connection = $connection;
        $this->query = new Query;
    }

    /**
     * Get the Cypher string from the DSL query.
     */
    public function toCypher(): string
    {
        return $this->query->build();
    }

    /**
     * Alias for toCypher to match Laravel conventions.
     */
    public function toSql(): string
    {
        return $this->toCypher();
    }

    /**
     * Execute the query and get all results.
     */
    public function get(): Collection
    {
        $cypher = $this->toCypher();
        $bindings = $this->extractBindings();

        $results = $this->connection->select($cypher, $bindings);

        // If we have a model class, hydrate models
        if ($this->model) {
            return $this->hydrateModels($results);
        }

        // Otherwise, return stdClass objects
        $normalized = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                // If the row contains a single column with object data, extract it
                if (count($row) === 1) {
                    $key = array_key_first($row);
                    if (is_object($row[$key]) || is_array($row[$key])) {
                        // Handle node data that's returned directly
                        $normalized[] = (object) $this->normalizeNodeData($row[$key]);
                    } else {
                        $normalized[] = (object) $row;
                    }
                } else {
                    $normalized[] = (object) $row;
                }
            } else {
                $normalized[] = $row;
            }
        }

        return collect($normalized);
    }

    /**
     * Get the first result from the query.
     */
    public function first()
    {
        return $this->get()->first();
    }

    /**
     * Get the count of results.
     */
    public function count(): int
    {
        // Modify query to return COUNT
        $countQuery = clone $this->query;

        // Build the count version of the query
        $cypher = $this->toCypher();

        // If query already has RETURN, replace it with COUNT
        if (str_contains($cypher, 'RETURN')) {
            // Extract the MATCH part and add COUNT
            $matchPart = substr($cypher, 0, strpos($cypher, 'RETURN'));
            $countCypher = $matchPart.'RETURN COUNT(*) as count';
        } else {
            $countCypher = $cypher.' RETURN COUNT(*) as count';
        }

        $results = $this->connection->select($countCypher, $this->extractBindings());

        if (empty($results)) {
            return 0;
        }

        $firstResult = $results[0];

        return (int) ($firstResult['count'] ?? $firstResult->count ?? 0);
    }

    /**
     * Dump the query and continue.
     */
    public function dump(): self
    {
        dump([
            'cypher' => $this->toCypher(),
            'bindings' => $this->extractBindings(),
        ]);

        return $this;
    }

    /**
     * Dump and die.
     */
    public function dd(): never
    {
        dd([
            'cypher' => $this->toCypher(),
            'bindings' => $this->extractBindings(),
        ]);
    }

    /**
     * Add a parameter to be used in the query.
     */
    public function withParameter(string $name, mixed $value): self
    {
        $this->parameters[$name] = $value;

        return $this;
    }

    /**
     * Set the model class for hydration.
     */
    public function withModel(string $modelClass): self
    {
        $this->model = $modelClass;

        return $this;
    }

    /**
     * Set the source node for instance queries.
     */
    public function withSourceNode(\Illuminate\Database\Eloquent\Model $node): self
    {
        $this->sourceNode = $node;

        return $this;
    }

    /**
     * Extract bindings/parameters for the query.
     */
    public function extractBindings(): array
    {
        return $this->parameters;
    }

    /**
     * Proxy DSL methods to the underlying Query object.
     */
    public function __call(string $method, array $arguments)
    {
        // Check if this is a macro first (from Macroable trait)
        if (static::hasMacro($method)) {
            $macro = static::$macros[$method];

            if ($macro instanceof \Closure) {
                $macro = $macro->bindTo($this, static::class);
            }

            return $macro(...$arguments);
        }

        // Otherwise, proxy to the DSL Query object
        $result = $this->query->$method(...$arguments);

        // If the DSL method returns a Query, update our reference and return $this for chaining
        if ($result instanceof Query) {
            $this->query = $result;

            return $this;
        }

        return $result;
    }

    /**
     * Hydrate models from query results.
     */
    protected function hydrateModels(array $results): Collection
    {
        if (! $this->model) {
            return collect($results);
        }

        $modelInstance = new $this->model;
        $models = [];

        foreach ($results as $row) {
            $attributes = $this->extractNodeAttributes($row);

            if (! empty($attributes)) {
                // Use newFromBuilder to properly hydrate the model with casts
                $model = $modelInstance->newFromBuilder($attributes);
                $models[] = $model;
            }
        }

        return $modelInstance->newCollection($models);
    }

    /**
     * Extract node attributes from a result row.
     */
    protected function extractNodeAttributes($row): array
    {
        if (is_array($row)) {
            // If the row contains a single column, extract it
            if (count($row) === 1) {
                $key = array_key_first($row);
                $value = $row[$key];

                if (is_object($value) || is_array($value)) {
                    return $this->normalizeNodeData($value);
                }

                // If it's a scalar value, return the whole row
                return $row;
            }

            // Multiple columns, return as-is
            return $row;
        }

        if (is_object($row)) {
            // Try to get properties from various object types
            return $this->normalizeNodeData($row);
        }

        return [];
    }

    /**
     * Normalize node data from Neo4j response.
     */
    protected function normalizeNodeData($nodeData): array
    {
        if (is_object($nodeData)) {
            // Convert object to array, handling both stdClass and Neo4j Node objects
            $properties = [];

            // Handle different response formats
            if (method_exists($nodeData, 'getProperties')) {
                // Neo4j Node object
                $properties = $nodeData->getProperties();
            } elseif (method_exists($nodeData, 'toArray')) {
                $properties = $nodeData->toArray();
            } else {
                // stdClass or similar
                foreach (get_object_vars($nodeData) as $key => $value) {
                    $properties[$key] = $value;
                }
            }

            // Convert Neo4j DateTime objects to strings
            foreach ($properties as $key => $value) {
                if ($value instanceof \Laudis\Neo4j\Types\DateTime) {
                    $properties[$key] = $value->toDateTime()->format('Y-m-d H:i:s');
                } elseif ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
                    $properties[$key] = $value->format('Y-m-d H:i:s');
                }
            }

            return $properties;
        }

        if (is_array($nodeData)) {
            // Also normalize array values
            foreach ($nodeData as $key => $value) {
                if ($value instanceof \Laudis\Neo4j\Types\DateTime) {
                    $nodeData[$key] = $value->toDateTime()->format('Y-m-d H:i:s');
                } elseif ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
                    $nodeData[$key] = $value->format('Y-m-d H:i:s');
                }
            }

            return $nodeData;
        }

        return ['value' => $nodeData];
    }
}
