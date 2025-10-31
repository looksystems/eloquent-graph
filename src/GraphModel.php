<?php

namespace Look\EloquentCypher;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasGlobalScopes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Look\EloquentCypher\Casting\AttributeCaster;
use Look\EloquentCypher\Checkers\GlobalScopeChecker;
use Look\EloquentCypher\Traits\HasCypherDsl;

class GraphModel extends Model
{
    use HasCypherDsl, HasFactory, HasGlobalScopes;

    protected $connection = 'graph';

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * The array of trait initializers that will be called on each new instance.
     *
     * @var array
     */
    protected static $traitInitializers = [];

    /**
     * The attribute caster instance.
     */
    protected ?AttributeCaster $attributeCaster = null;

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'created_at',
        'updated_at',
    ];

    /**
     * Additional labels to apply to the node (beyond the primary table label).
     *
     * @var array
     */
    protected $labels = [];

    protected static function boot()
    {
        parent::boot();
    }

    /**
     * Remove a registered global scope.
     *
     * @param  \Illuminate\Database\Eloquent\Scope|string  $scope
     * @return void
     */
    public static function removeGlobalScope($scope)
    {
        if (! is_string($scope)) {
            $scope = get_class($scope);
        }

        unset(static::$globalScopes[static::class][$scope]);
    }

    /**
     * Create a new model instance.
     *
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Initialize traits for this instance if needed
        $this->initializeTraits();
    }

    /**
     * Initialize any initializable traits on the model.
     *
     * @return void
     */
    protected function initializeTraits()
    {
        $class = static::class;

        if (isset(static::$traitInitializers[$class])) {
            foreach (static::$traitInitializers[$class] as $method) {
                $this->{$method}();
            }
        }
    }

    /**
     * Get the table associated with the model.
     * Overridden to support label prefixing (like table prefixing in MySQL).
     *
     * @return string
     */
    public function getTable()
    {
        $table = parent::getTable();

        // Get prefix from connection configuration
        $connection = $this->getConnection();
        if ($connection instanceof \Look\EloquentCypher\GraphConnection) {
            $labelResolver = $connection->getLabelResolver();

            return $labelResolver->qualify($table);
        }

        return $table;
    }

    /**
     * Get all labels for this model (primary table + additional labels).
     */
    public function getLabels(): array
    {
        $primary = $this->getTable();
        $additional = $this->labels ?? [];

        return array_merge([$primary], $additional);
    }

    /**
     * Check if the model has a specific label.
     */
    public function hasLabel(string $label): bool
    {
        return in_array($label, $this->getLabels(), true);
    }

    /**
     * Get the label string for Cypher queries (e.g., ":users:Person:Individual").
     */
    public function getLabelString(): string
    {
        return ':'.implode(':', $this->getLabels());
    }

    /**
     * Scope a query to use specific labels instead of the model's default labels.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithLabels($query, array $labels)
    {
        // Store custom labels in the query builder
        $query->getQuery()->customLabels = $labels;

        return $query;
    }

    /**
     * Fire the given event for the model.
     * Made public to allow Neo4jEloquentBuilder to fire events.
     *
     * @param  string  $event
     * @param  bool  $halt
     * @return mixed
     */
    public function fireModelEvent($event, $halt = true)
    {
        return parent::fireModelEvent($event, $halt);
    }

    protected function generateId()
    {
        return uniqid();
    }

    public function newEloquentBuilder($query)
    {
        return new \Look\EloquentCypher\GraphEloquentBuilder($query);
    }

    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new \Look\EloquentCypher\GraphQueryBuilder(
            $connection,
            null,  // Grammar functionality is integrated into Neo4jQueryBuilder
            null   // Processor functionality is integrated into Neo4jConnection
        );
    }

    /**
     * Get a new query builder for the model's table.
     * Override to set model labels for multi-label support.
     */
    public function newQuery()
    {
        $builder = parent::newQuery();

        // Set model labels on the query builder for multi-label support
        $queryBuilder = $builder->getQuery();
        if ($queryBuilder instanceof \Look\EloquentCypher\GraphQueryBuilder) {
            $queryBuilder->modelLabels = $this->getLabels();
        }

        return $builder;
    }

    protected function performInsert(Builder $query)
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $this->ensureIdIsSet();

        // Set model labels on the query builder for multi-label support
        $queryBuilder = $query->getQuery();
        if ($queryBuilder instanceof \Look\EloquentCypher\GraphQueryBuilder) {
            $queryBuilder->modelLabels = $this->getLabels();
        }

        $attributes = $this->getAttributesForInsert();
        if (empty($attributes)) {
            return true;
        }

        $result = $query->insert($attributes);

        if ($result) {
            $this->hydrateFromInsertResponse($query);
            $this->markAsCreated();
        }

        return $result;
    }

    /**
     * Ensure the model has an ID set.
     *
     * @return void
     */
    protected function ensureIdIsSet()
    {
        if (! $this->getKey()) {
            $this->setAttribute($this->getKeyName(), $this->generateId());
        }
    }

    /**
     * Hydrate the model with data from the insert response.
     *
     * @return void
     */
    protected function hydrateFromInsertResponse(Builder $query)
    {
        $queryBuilder = $query->getQuery();
        if (! $queryBuilder instanceof \Look\EloquentCypher\GraphQueryBuilder) {
            return;
        }

        $node = $queryBuilder->getLastInsertedNode();
        if (! $node) {
            return;
        }

        foreach ($node as $key => $value) {
            // Skip encrypted/hashed attributes to keep already processed values
            if (! $this->isEncryptedCastable($key) && ! $this->hasCast($key, 'hashed')) {
                $this->attributes[$key] = $value;
            }
        }
    }

    /**
     * Mark the model as created.
     *
     * @return void
     */
    protected function markAsCreated()
    {
        $this->exists = true;
        $this->wasRecentlyCreated = true;
        $this->syncOriginal();
        $this->fireModelEvent('created', false);
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirty();

        if (count($dirty) === 0) {
            $this->fireModelEvent('updated', false);

            return true;
        }

        $affected = $this->executeUpdateQuery($query, $dirty);

        if ($this->isRestoringDeletedAt($dirty) || $affected > 0) {
            $this->markAsUpdated();

            return true;
        }

        return false;
    }

    /**
     * Execute the update query with the dirty attributes.
     *
     * @return int
     */
    protected function executeUpdateQuery(Builder $query, array $dirty)
    {
        $cypher = $this->buildUpdateQuery($dirty);
        $bindings = $this->prepareUpdateBindings($dirty);

        return $query->getConnection()->affectingStatement($cypher, $bindings);
    }

    /**
     * Build the Cypher UPDATE query.
     *
     * @return string
     */
    protected function buildUpdateQuery(array $dirty)
    {
        $label = implode(':', $this->getLabels());
        $sets = [];

        foreach ($dirty as $key => $value) {
            $sets[] = ($value === null)
                ? "n.$key = null"
                : "n.$key = \$$key";
        }

        $setString = implode(', ', $sets);

        return "MATCH (n:$label) WHERE n.{$this->getKeyName()} = \$id SET $setString RETURN n";
    }

    /**
     * Prepare bindings for the update query.
     *
     * @return array
     */
    protected function prepareUpdateBindings(array $dirty)
    {
        $nonNullDirty = array_filter($dirty, function ($value) {
            return $value !== null;
        });

        return array_merge(['id' => $this->getKey()], $nonNullDirty);
    }

    /**
     * Check if we're restoring a soft deleted model.
     *
     * @return bool
     */
    protected function isRestoringDeletedAt(array $dirty)
    {
        return array_key_exists('deleted_at', $dirty) && $dirty['deleted_at'] === null;
    }

    /**
     * Mark the model as updated.
     *
     * @return void
     */
    protected function markAsUpdated()
    {
        $this->syncChanges();
        $this->fireModelEvent('updated', false);
    }

    public function delete()
    {
        if (is_null($this->getKeyName())) {
            throw new \Exception('No primary key defined on model.');
        }

        if (! $this->exists) {
            return false;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $this->performDeleteOnModel();

        // Set exists to false after deletion
        // Even for soft deletes, exists is false from the application's perspective
        $this->exists = false;

        $this->fireModelEvent('deleted', false);

        return true;
    }

    protected function performDeleteOnModel()
    {
        $strategy = $this->getDeletionStrategy();

        return $this->$strategy();
    }

    protected function getDeletionStrategy(): string
    {
        if (isset($this->forceDeleting) && $this->forceDeleting === true) {
            return 'executeForceDelete';
        }

        if ($this->usesSoftDeletes()) {
            return 'executeSoftDelete';
        }

        return 'executeHardDelete';
    }

    protected function usesSoftDeletes(): bool
    {
        return GlobalScopeChecker::usesSoftDeletes($this);
    }

    protected function executeForceDelete(): bool
    {
        $label = implode(':', $this->getLabels());
        $id = $this->getKey();

        $cypher = "MATCH (n:$label) WHERE n.{$this->getKeyName()} = \$id DETACH DELETE n";
        $result = $this->getConnection()->affectingStatement($cypher, ['id' => $id]);

        $this->exists = false;

        return $result > 0;
    }

    protected function executeSoftDelete(): bool
    {
        return $this->runSoftDelete();
    }

    protected function executeHardDelete(): void
    {
        $label = implode(':', $this->getLabels());
        $id = $this->getKey();

        $cypher = "MATCH (n:$label) WHERE n.{$this->getKeyName()} = \$id DETACH DELETE n";
        $this->getConnection()->statement($cypher, ['id' => $id]);

        $this->exists = false;
    }

    protected function runSoftDelete()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();

        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time)];

        $this->{$this->getDeletedAtColumn()} = $time;

        if ($this->timestamps && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;

            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));
    }

    public static function find($id, $columns = ['*'])
    {
        // Handle arrays of IDs
        if (is_array($id)) {
            return static::findMany($id, $columns);
        }

        // For models with soft deletes or other global scopes, use the query builder
        // Otherwise use direct query for performance
        $instance = new static;

        // Check if the model has any global scopes (like soft deletes)
        if (GlobalScopeChecker::hasGlobalScopes($instance)) {
            return static::query()->find($id, $columns);
        }

        // Direct query for models without global scopes
        $label = implode(':', $instance->getLabels());
        $keyName = $instance->getKeyName();

        $cypher = "MATCH (n:$label) WHERE n.$keyName = \$id RETURN n";
        $results = $instance->getConnection()->select($cypher, ['id' => $id]);

        if (! empty($results)) {
            $attributes = $results[0]['n'];

            return $instance->newFromBuilder($attributes);
        }

        return null;
    }

    public static function all($columns = ['*'])
    {
        // For models with soft deletes or other global scopes, use the query builder
        // Otherwise use direct query for performance
        $instance = new static;

        // Check if the model has any global scopes (like soft deletes)
        if (GlobalScopeChecker::hasGlobalScopes($instance)) {
            return static::query()->get($columns);
        }

        // Direct query for models without global scopes
        $label = implode(':', $instance->getLabels());

        $cypher = "MATCH (n:$label) RETURN n";
        $results = $instance->getConnection()->select($cypher);

        $models = [];
        foreach ($results as $result) {
            $models[] = $instance->newFromBuilder($result['n']);
        }

        return $instance->newCollection($models);
    }

    public static function destroy($ids)
    {
        $instance = new static;
        $ids = is_array($ids) ? $ids : func_get_args();

        $count = 0;
        foreach ($ids as $id) {
            $model = static::find($id);
            if ($model && $model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Find multiple models by their primary keys.
     *
     * @param  iterable  $ids
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function findMany($ids, $columns = ['*'])
    {
        $instance = new static;

        // Convert to array and filter out null/empty values
        $ids = collect($ids)->filter(function ($id) {
            return $id !== null && $id !== '';
        })->toArray();

        if (empty($ids)) {
            return $instance->newCollection();
        }

        // Check if the model has any global scopes (like soft deletes)
        if (GlobalScopeChecker::hasGlobalScopes($instance)) {
            return static::query()->findMany($ids, $columns);
        }

        // Direct query for models without global scopes
        $label = implode(':', $instance->getLabels());
        $keyName = $instance->getKeyName();

        $cypher = "MATCH (n:$label) WHERE n.$keyName IN \$ids RETURN n";
        $results = $instance->getConnection()->select($cypher, ['ids' => $ids]);

        $models = [];
        foreach ($results as $result) {
            $models[] = $instance->newFromBuilder($result['n']);
        }

        return $instance->newCollection($models);
    }

    /**
     * Find a model by its primary key or throw an exception.
     *
     * @param  mixed  $id
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Model
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function findOrFail($id, $columns = ['*'])
    {
        $result = static::find($id, $columns);

        // Handle array of IDs
        if (is_array($id)) {
            $requestedIds = $id;

            // If result is null or empty collection, none were found
            if ($result === null || ($result instanceof \Illuminate\Database\Eloquent\Collection && $result->isEmpty())) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)
                    ->setModel(static::class, $requestedIds);
            }

            // Check if all requested IDs were found
            if ($result instanceof \Illuminate\Database\Eloquent\Collection) {
                $keyName = (new static)->getKeyName();
                $foundIds = $result->pluck($keyName)->toArray();

                // Convert to strings for consistent comparison
                $foundIds = array_map('strval', $foundIds);
                $requestedIds = array_map('strval', $requestedIds);

                $missingIds = array_diff($requestedIds, $foundIds);

                if (! empty($missingIds)) {
                    throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)
                        ->setModel(static::class, array_values($missingIds));
                }
            }

            return $result;
        }

        // Handle single ID
        if ($result !== null) {
            return $result;
        }

        throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)
            ->setModel(static::class, $id);
    }

    /**
     * Find a model by its primary key or return fresh model instance.
     *
     * @param  mixed  $id
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Model
     */
    public static function findOrNew($id, $columns = ['*'])
    {
        if (($model = static::find($id, $columns)) !== null) {
            return $model;
        }

        return new static;
    }

    /**
     * Get the first record matching the attributes or instantiate it.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public static function firstOrNew(array $attributes = [], array $values = [])
    {
        if (($instance = static::where($attributes)->first()) !== null) {
            return $instance;
        }

        return new static($attributes + $values);
    }

    /**
     * Get the first record matching the attributes or create it.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public static function firstOrCreate(array $attributes = [], array $values = [])
    {
        if (($instance = static::where($attributes)->first()) !== null) {
            return $instance;
        }

        return static::create($attributes + $values);
    }

    /**
     * Create or update a record matching the attributes, and fill it with values.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public static function updateOrCreate(array $attributes, array $values = [])
    {
        return static::query()->updateOrCreate($attributes, $values);
    }

    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return new \Look\EloquentCypher\Relations\GraphHasMany($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return new \Look\EloquentCypher\Relations\GraphHasOne($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    public function getForeignKey()
    {
        return strtolower(class_basename($this)).'_'.$this->getKeyName();
    }

    public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null)
    {
        if (is_null($relation)) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance = $this->newRelatedInstance($related);

        if (is_null($foreignKey)) {
            $foreignKey = strtolower(class_basename($related)).'_'.$instance->getKeyName();
        }

        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return new \Look\EloquentCypher\Relations\GraphBelongsTo(
            $instance->newQuery(),
            $this,
            $foreignKey,
            $ownerKey,
            $relation
        );
    }

    protected function guessBelongsToRelation()
    {
        [$one, $two, $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $caller['function'];
    }

    public function belongsToMany($related, $table = null, $foreignPivotKey = null, $relatedPivotKey = null,
        $parentKey = null, $relatedKey = null, $relation = null)
    {
        if (is_null($relation)) {
            $relation = $this->guessBelongsToManyRelation();
        }

        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // If no table name was provided, guess it by concatenating the two models
        if (is_null($table)) {
            $table = $this->joiningTable($related, $instance);
        }

        $parentKey = $parentKey ?: $this->getKeyName();
        $relatedKey = $relatedKey ?: $instance->getKeyName();

        return new \Look\EloquentCypher\Relations\GraphBelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relation
        );
    }

    protected function guessBelongsToManyRelation()
    {
        [$one, $two, $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $caller['function'];
    }

    public function hasManyThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null)
    {
        $through = new $through;

        $firstKey = $firstKey ?: $this->getForeignKey();

        $secondKey = $secondKey ?: $through->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        $secondLocalKey = $secondLocalKey ?: $through->getKeyName();

        return new \Look\EloquentCypher\Relations\GraphHasManyThrough(
            $this->newRelatedInstance($related)->newQuery(),
            $this,
            $through,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocalKey
        );
    }

    public function hasOneThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null)
    {
        $through = new $through;

        $firstKey = $firstKey ?: $this->getForeignKey();

        $secondKey = $secondKey ?: $through->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        $secondLocalKey = $secondLocalKey ?: $through->getKeyName();

        return new \Look\EloquentCypher\Relations\GraphHasOneThrough(
            $this->newRelatedInstance($related)->newQuery(),
            $this,
            $through,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocalKey
        );
    }

    /**
     * Define a polymorphic many-to-many relationship.
     *
     * ARCHITECTURAL NOTE: All polymorphic relationships (morphMany, morphOne, morphTo, morphToMany)
     * use foreign key storage (morph_id + morph_type) rather than native Neo4j edges.
     *
     * This is an intentional design decision to maintain 100% Eloquent API compatibility.
     * Native edges cannot efficiently encode polymorphic type information, and Neo4j Community
     * Edition doesn't support edge property indexes, making foreign keys ~7x faster.
     *
     * See README.md "Polymorphic Relationships & Architecture" for full rationale.
     */
    public function morphToMany($related, $name, $table = null, $foreignPivotKey = null, $relatedPivotKey = null,
        $parentKey = null, $relatedKey = null, $inverse = false, $relation = null)
    {
        $caller = $this->guessBelongsToManyRelation();

        // First, we will need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys, we'll make the query
        // instances as well as the relationship instances we need for this.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $name.'_id';
        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // If no table was provided, we'll use the pluralized form of the relationship
        // name since typically that's what the table would be.
        $table = $table ?: Str::plural($name);

        // Now we're ready to create a new query builder for this related model and
        // the relationship instances for this relation. This will allow us to
        // bind into models and execute queries against the database.
        $parentKey = $parentKey ?: $this->getKeyName();
        $relatedKey = $relatedKey ?: $instance->getKeyName();

        // Create query for the related model
        $query = $instance->newQuery();

        return new \Look\EloquentCypher\Relations\GraphMorphToMany(
            $query, $this, $name, $table,
            $foreignPivotKey, $relatedPivotKey, $parentKey,
            $relatedKey, $caller, $inverse
        );
    }

    /**
     * Define a polymorphic inverse many-to-many relationship.
     */
    public function morphedByMany($related, $name, $table = null, $foreignPivotKey = null, $relatedPivotKey = null,
        $parentKey = null, $relatedKey = null, $relation = null)
    {
        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $name.'_id';

        return $this->morphToMany(
            $related, $name, $table, $foreignPivotKey,
            $relatedPivotKey, $parentKey, $relatedKey, true, $relation
        );
    }

    /**
     * Eager load relation counts on the model.
     *
     * @param  array|string  $relations
     * @return $this
     */
    public function loadCount($relations)
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        // Build a query with the count relations
        $query = $this->newQueryWithoutRelationships()->withCount($relations);

        // Get the builder to access withCountRelations
        if (method_exists($query, 'addWithCountToModels')) {
            // Call the protected method to add count data to this model
            $reflection = new \ReflectionMethod($query, 'addWithCountToModels');
            $reflection->setAccessible(true);
            $reflection->invoke($query, [$this]);
        }

        return $this;
    }

    /**
     * Eager load relation counts on the model if it is not already eager loaded.
     *
     * @param  array|string  $relations
     * @return $this
     */
    public function loadMissing($relations)
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        $this->newQueryWithoutRelationships()->with($relations)->eagerLoadRelations([$this]);

        return $this;
    }

    /**
     * Eager load relation counts on a collection.
     *
     * @param  array|string  $relations
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function loadCountOnCollection(Collection $collection, $relations)
    {
        if ($collection->isEmpty()) {
            return $collection;
        }

        $first = $collection->first();
        $relations = is_string($relations) ? func_get_args() : $relations;

        // Build a query with the count relations
        $query = $first->newQueryWithoutRelationships()->withCount($relations);

        // Get the builder to access withCountRelations
        if (method_exists($query, 'addWithCountToModels')) {
            // Call the protected method to add count data to all models in the collection
            $reflection = new \ReflectionMethod($query, 'addWithCountToModels');
            $reflection->setAccessible(true);
            $reflection->invoke($query, $collection->all());
        }

        return $collection;
    }

    /**
     * Update the creation and update timestamps.
     *
     * @return void
     */
    public function updateTimestamps()
    {
        $time = $this->freshTimestamp();

        if (! $this->isDirty(static::UPDATED_AT) && ! is_null(static::UPDATED_AT)) {
            $this->setUpdatedAt($time);
        }

        if (! $this->exists && ! $this->isDirty(static::CREATED_AT) && ! is_null(static::CREATED_AT)) {
            $this->setCreatedAt($time);
        }
    }

    /**
     * Set the value of the "created at" attribute.
     *
     * @param  mixed  $value
     * @return $this
     */
    public function setCreatedAt($value)
    {
        $this->{static::CREATED_AT} = $value;

        return $this;
    }

    /**
     * Set the value of the "updated at" attribute.
     *
     * @param  mixed  $value
     * @return $this
     */
    public function setUpdatedAt($value)
    {
        $this->{static::UPDATED_AT} = $value;

        return $this;
    }

    /**
     * Get a fresh timestamp for the model.
     *
     * @return \Carbon\Carbon
     */
    public function freshTimestamp()
    {
        return Carbon::now();
    }

    /**
     * Qualify the given column name by the model's table.
     *
     * @param  string  $column
     * @return string
     */
    public function qualifyColumn($column)
    {
        if (Str::contains($column, '.')) {
            return $column;
        }

        return $this->getTable().'.'.$column;
    }

    /**
     * Qualify the given columns with the model's table.
     *
     * @param  array  $columns
     * @return array
     */
    public function qualifyColumns($columns)
    {
        return collect($columns)->map(function ($column) {
            return $this->qualifyColumn($column);
        })->all();
    }

    /**
     * Get the attribute caster instance.
     */
    protected function getAttributeCaster(): AttributeCaster
    {
        if (! $this->attributeCaster) {
            $this->attributeCaster = new AttributeCaster($this);
        }

        return $this->attributeCaster;
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        return $this->getAttributeCaster()->cast($key, $value);
    }

    /**
     * Cast attribute using parent's implementation.
     * This is a helper method for AttributeCaster to access parent casting.
     */
    public function castAttributeUsingParent($key, $value)
    {
        return parent::castAttribute($key, $value);
    }

    /**
     * Get the primitive cast types for models.
     */
    public function getPrimitiveCastTypes()
    {
        return static::$primitiveCastTypes;
    }

    /**
     * Get the type of cast for a model attribute.
     */
    public function getCastType($key)
    {
        $casts = $this->getCasts();
        if (! isset($casts[$key])) {
            return null;
        }

        $cast = $casts[$key];

        if ($this->isCustomDateTimeCast($cast)) {
            return 'custom_datetime';
        }

        if ($this->isImmutableCustomDateTimeCast($cast)) {
            return 'immutable_custom_datetime';
        }

        if ($this->isDecimalCast($cast)) {
            return 'decimal';
        }

        return trim(strtolower($cast));
    }

    /**
     * Cast a JSON attribute value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function castJsonAttribute($value)
    {
        // Neo4j stores JSON as strings, so we need to decode them
        $decoded = $this->fromJson($value);

        // If fromJson returns a string (couldn't decode), try again
        if (is_string($decoded) && $this->isJson($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return $decoded;
    }

    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        // Handle JSON path syntax (e.g., 'settings->notifications')
        if (str_contains($key, '->')) {
            return $this->setJsonPathAttribute($key, $value);
        }

        // Handle mutators
        if ($this->hasSetMutator($key)) {
            return $this->setMutatedAttributeValue($key, $value);
        }

        // Don't process encrypted or hashed attributes - let parent handle them
        if ($this->isEncryptedCastable($key) || $this->hasCast($key, 'hashed')) {
            return parent::setAttribute($key, $value);
        }

        // Handle casting for storage - but only for non-null values
        if ($this->hasCast($key) && ! is_null($value)) {
            $castType = $this->getCastType($key);
            $value = $this->castAttributeForStorage($key, $value);

            // For array/json casts, set directly to avoid Laravel's parent
            // Model from re-encoding our native Neo4j types back to JSON strings
            // Note: 'collection' is excluded - it should always use JSON encoding
            if (in_array($castType, ['array', 'json'])) {
                $this->attributes[$key] = $value;

                return $this;
            }
        }

        // If an attribute is listed as a "date", we'll convert it from a DateTime
        // instance into a format that can be stored on the database.
        if ($value && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Set a nested JSON path attribute.
     * Handles dot notation like 'settings->notifications' by updating nested array values.
     */
    protected function setJsonPathAttribute(string $key, mixed $value): static
    {
        $segments = explode('->', $key);
        $mainKey = array_shift($segments);

        // Get current value of parent property with proper casting applied
        $current = $this->getAttribute($mainKey);

        // If current value is null or not an array, start with empty array
        if (! is_array($current)) {
            $current = [];
        }

        // Navigate to the nested location and set the value
        $target = &$current;
        foreach (array_slice($segments, 0, -1) as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        // Set the final nested value
        $target[end($segments)] = $value;

        // Force the attribute to be marked as dirty by clearing it first
        // This ensures Laravel's change tracking picks up the modification
        if (isset($this->attributes[$mainKey])) {
            unset($this->attributes[$mainKey]);
        }

        // Set the entire parent property, which will trigger casting and mark as dirty
        return $this->setAttribute($mainKey, $current);
    }

    /**
     * Cast attribute for database storage.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function castAttributeForStorage($key, $value)
    {
        $castType = $this->getCastType($key);

        switch ($castType) {
            case 'array':
            case 'json':
                // Neo4j only supports flat arrays (primitives) as property values
                // Nested structures must be stored as JSON strings
                if (is_array($value)) {
                    // Check if array is flat (only primitives)
                    if ($this->isFlatArray($value)) {
                        return $value; // Store as native Neo4j list
                    } else {
                        return $this->asJson($value); // Store nested as JSON string
                    }
                }
                // Encode objects as JSON
                if (is_object($value) && ! ($value instanceof \JsonSerializable)) {
                    return $this->asJson($value);
                }

                return $value;
            case 'object':
                if (is_object($value)) {
                    return $this->asJson($value);
                }

                return $value;
            case 'collection':
                // Collections should always be stored as JSON strings
                // Laravel's collection cast expects to deserialize from JSON
                if ($value instanceof \Illuminate\Support\Collection) {
                    return $this->asJson($value->toArray());
                }
                if (is_array($value)) {
                    return $this->asJson($value);
                }

                return $this->asJson($value);
            case 'bool':
            case 'boolean':
                // Don't cast booleans here, let the DB adapter handle it
                return $value;
            default:
                // Let parent handle encrypted and hashed attributes
                return $value;
        }
    }

    /**
     * Check if an array is a flat indexed array (list) of primitives.
     * Neo4j property values can only be primitives or lists of primitives.
     * Associative arrays (maps) must be stored as JSON strings.
     */
    protected function isFlatArray(array $array): bool
    {
        // Empty arrays are flat
        if (empty($array)) {
            return true;
        }

        // Check if it's an associative array (has string keys)
        // Associative arrays must be stored as JSON
        if (array_keys($array) !== range(0, count($array) - 1)) {
            return false;
        }

        // Check if all values are primitives (no nested arrays/objects)
        foreach ($array as $value) {
            if (is_array($value) || is_object($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Encode the given value as JSON.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function asJson($value, $flags = 0)
    {
        // Use flags for clean JSON compatible with APOC
        // JSON_UNESCAPED_SLASHES: Don't escape forward slashes
        // JSON_UNESCAPED_UNICODE: Keep unicode characters as-is
        $defaultFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return json_encode($value, $flags ?: $defaultFlags);
    }

    /**
     * Decode the given JSON back into an array or object.
     *
     * @param  string  $value
     * @param  bool  $asObject
     * @return mixed
     */
    public function fromJson($value, $asObject = false)
    {
        // If already an array or object of the correct type, return as-is
        if (! is_string($value)) {
            if (is_array($value) && ! $asObject) {
                return $value;
            }
            if (is_object($value) && $asObject) {
                return $value;
            }
        }

        // If it's a string, decode it
        if (is_string($value)) {
            // First, try to decode
            $decoded = json_decode($value, ! $asObject);

            // If we got null and there was an error, the string wasn't JSON
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                return $value;
            }

            return $decoded;
        }

        return $value;
    }

    /**
     * Check if a string is valid JSON.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isJson($value)
    {
        if (! is_string($value)) {
            return false;
        }
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Return a timestamp as DateTime object with time set to 00:00:00.
     *
     * @param  mixed  $value
     * @return \Carbon\Carbon
     */
    public function asDate($value)
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @param  mixed  $value
     * @return \Carbon\Carbon
     */
    public function asDateTime($value)
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        if ($value instanceof Carbon) {
            return $value;
        }

        // Handle Neo4j DateTimeZoneId objects
        if ($value instanceof \Laudis\Neo4j\Types\DateTimeZoneId) {
            return Carbon::instance($value->toDateTime())->setTimezone(config('app.timezone'));
        }

        // If the value is already a DateTime instance, we will just wrap it with a
        // Carbon instance to make this a little easier to work with.
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone(config('app.timezone'));
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp.
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value, config('app.timezone'));
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format.
        if ($this->isStandardDateFormat($value)) {
            return Carbon::createFromFormat('Y-m-d', $value, config('app.timezone'))->startOfDay();
        }

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        $format = $this->getDateFormat();

        return Carbon::createFromFormat($format, $value, config('app.timezone'));
    }

    /**
     * Determine if the given value is a standard date format.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isStandardDateFormat($value)
    {
        // Handle non-string values (e.g., Neo4j DateTimeZoneId objects)
        if (! is_string($value)) {
            return false;
        }

        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Convert a DateTime to a storable string.
     *
     * @param  mixed  $value
     * @return string|null
     */
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->format(
            $this->getDateFormat()
        );
    }

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return $this->dateFormat ?: 'Y-m-d H:i:s';
    }

    /**
     * Get an attribute from the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if (! $key) {
            return;
        }

        // If the attribute exists in the attribute array or has a "get" mutator we will
        // get the attribute's value. Otherwise, we will proceed as if the developers
        // are asking for a relationship's value. This covers both types of values.
        if (array_key_exists($key, $this->attributes) ||
            array_key_exists($key, $this->casts) ||
            $this->hasGetMutator($key) ||
            $this->isClassCastable($key)) {
            return $this->getAttributeValue($key);
        }

        // Here we will determine if the model base class itself contains this given key
        // since we don't want to treat any of those methods as relationships because
        // they are all intended as helper methods and none of these are relations.
        if (method_exists(self::class, $key)) {
            return;
        }

        return $this->getRelationValue($key);
    }

    /**
     * Get a plain attribute (not a relationship).
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttributeValue($key)
    {
        return $this->transformModelValue($key, $this->getAttributeFromArray($key));
    }

    /**
     * Get an attribute from the $attributes array.
     * Override to handle Neo4j's string quoting behavior.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function getAttributeFromArray($key)
    {
        $value = $this->getAttributes()[$key] ?? null;

        // Handle Neo4j's tendency to quote string values
        // If value is a quoted string (starts and ends with quotes), unquote it
        if (is_string($value) && strlen($value) >= 2 &&
            $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            // Try to decode as JSON first (in case it's a JSON string)
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        return $value;
    }

    /**
     * Transform a raw model value using mutators, casts, etc.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transformModelValue($key, $value)
    {
        // If the attribute has a get mutator, we will call that then return what
        // it returns as the value, which is useful for transforming values on
        // retrieval from the model to a form that is more useful for usage.
        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        }

        // If the attribute exists within the cast array, we will convert it to
        // an appropriate native PHP type dependant upon the associated value
        // given with the key in the pair.
        if ($this->hasCast($key)) {
            return $this->castAttribute($key, $value);
        }

        // If the attribute is listed as a date, we will convert it to a DateTime
        // instance on retrieval, which makes it quite convenient to work with
        // date fields without having to create a mutator for each property.
        if ($value !== null && \in_array($key, $this->getDates())) {
            return $this->asDateTime($value);
        }

        return $value;
    }

    /**
     * Determine whether an attribute should be cast to a native type.
     *
     * @param  string  $key
     * @param  array|string|null  $types
     * @return bool
     */
    public function hasCast($key, $types = null)
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array) $types, true) : true;
        }

        return false;
    }

    /**
     * Determine if the cast type is a custom date time cast.
     *
     * @param  string  $cast
     * @return bool
     */
    protected function isCustomDateTimeCast($cast)
    {
        return strncmp($cast, 'date:', 5) === 0 ||
               strncmp($cast, 'datetime:', 9) === 0;
    }

    /**
     * Determine if the cast type is an immutable custom date time cast.
     *
     * @param  string  $cast
     * @return bool
     */
    protected function isImmutableCustomDateTimeCast($cast)
    {
        return strncmp($cast, 'immutable_date:', 15) === 0 ||
               strncmp($cast, 'immutable_datetime:', 19) === 0;
    }

    /**
     * Determine if the cast type is a decimal cast.
     *
     * @param  string  $cast
     * @return bool
     */
    protected function isDecimalCast($cast)
    {
        return strncmp($cast, 'decimal:', 8) === 0;
    }

    /**
     * Determine if the given cast type is nullable.
     *
     * @param  string  $castType
     * @return bool
     */
    protected function isNullableCastType($castType)
    {
        return ! in_array($castType, [
            'array', 'bool', 'boolean', 'collection', 'date', 'datetime',
            'float', 'int', 'integer', 'json', 'object', 'real', 'string', 'timestamp',
        ]);
    }

    /**
     * Cast a float value.
     *
     * @param  mixed  $value
     * @return float
     */
    public function fromFloat($value)
    {
        return (float) $value;
    }

    /**
     * Cast a decimal value as string.
     *
     * @param  mixed  $value
     * @param  int  $decimals
     * @return string
     */
    public function asDecimal($value, $decimals)
    {
        return number_format((float) $value, (int) $decimals, '.', '');
    }

    /**
     * Cast the given value to boolean.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function asBool($value)
    {
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    /**
     * Decrypt the given encrypted string.
     *
     * @param  string  $value
     * @return mixed
     */
    public function fromEncryptedString($value)
    {
        return Crypt::decrypt($value, false);
    }

    /**
     * Determine if the given key is an encrypted castable.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isEncryptedCastable($key)
    {
        return $this->hasCast($key, ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * Determine if the given key is cast using a class.
     *
     * @param  string  $key
     * @return bool
     */
    public function isClassCastable($key)
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $this->parseCasterClass($casts[$key]);

        if (in_array($castType, static::$primitiveCastTypes ?? [])) {
            return false;
        }

        return class_exists($castType);
    }

    /**
     * Determine if the given key is cast using an enum.
     *
     * @param  string  $key
     * @return bool
     */
    public function isEnumCastable($key)
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $casts[$key];

        if (in_array($castType, static::$primitiveCastTypes ?? [])) {
            return false;
        }

        return enum_exists($castType);
    }

    /**
     * Parse the given caster class and return the class name.
     *
     * @param  string  $class
     * @return string
     */
    protected function parseCasterClass($class)
    {
        return strpos($class, ':') === false ? $class : explode(':', $class, 2)[0];
    }

    /**
     * Get the class castable attribute value.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function getClassCastableAttributeValue($key, $value)
    {
        // For now, just return the value as-is
        // This would be implemented for custom casters
        return $value;
    }

    /**
     * Get the enum castable attribute value.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function getEnumCastableAttributeValue($key, $value)
    {
        // For now, just return the value as-is
        // This would be implemented for enum casting
        return $value;
    }

    /**
     * Determine if the given key is JSON castable.
     *
     * @param  string  $key
     * @return bool
     */
    public function isJsonCastable($key)
    {
        return $this->hasCast($key, ['array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * Return a timestamp as Unix timestamp.
     *
     * @param  mixed  $value
     * @return int
     */
    public function asTimestamp($value)
    {
        return $this->asDateTime($value)->getTimestamp();
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format($this->getDateFormat());
    }

    /**
     * Clone the model into a new, non-existing instance.
     *
     * @return static
     */
    public function replicate(?array $except = null)
    {
        $defaults = array_values(array_filter([
            $this->getKeyName(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
            ...$this->uniqueIds(),
        ]));

        $attributes = \Illuminate\Support\Arr::except(
            $this->getAttributes(), $except ? array_unique(array_merge($except, $defaults)) : $defaults
        );

        return tap(new static, function ($instance) use ($attributes) {
            $instance->setRawAttributes($attributes);

            $instance->setRelations($this->relations);

            $instance->fireModelEvent('replicating', false);
        });
    }

    /**
     * Clone the model into a new, non-existing instance without raising any events.
     *
     * @return static
     */
    public function replicateQuietly(?array $except = null)
    {
        return static::withoutEvents(fn () => $this->replicate($except));
    }

    /**
     * Register observers with the model.
     *
     * @param  object|array|string  $classes
     * @return void
     */
    public static function observe($classes)
    {
        $instance = new static;

        // Handle single or multiple observers
        $observers = is_array($classes) ? $classes : [$classes];

        foreach ($observers as $class) {
            $instance->registerObserver($class);
        }
    }

    /**
     * Register a single observer with the model.
     *
     * @param  object|string  $class
     * @return void
     */
    protected function registerObserver($class)
    {
        // Skip if null or invalid
        if (! $class) {
            return;
        }

        $className = is_string($class) ? $class : get_class($class);

        // Get the observable events this model supports
        $observableEvents = [
            'retrieved', 'creating', 'created', 'updating', 'updated',
            'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored',
            'replicating', 'forceDeleting', 'forceDeleted',
        ];

        // Register each method that exists in the observer
        foreach ($observableEvents as $event) {
            if (method_exists($class, $event)) {
                static::registerModelEvent($event, function ($model) use ($class, $event) {
                    // If class is a string, instantiate it
                    $observer = is_string($class) ? app($class) : $class;

                    return $observer->{$event}($model);
                });
            }
        }
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @return $this
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        return $this->incrementOrDecrement($column, $amount, $extra, 'increment');
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @return $this
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        return $this->incrementOrDecrement($column, $amount, $extra, 'decrement');
    }

    /**
     * Run the increment or decrement method on the model.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array  $extra
     * @param  string  $method
     * @return $this
     */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        $query = $this->newQuery();

        if (! $this->exists) {
            return $this->newQuery()->{$method}($column, $amount, $extra);
        }

        $this->incrementOrDecrementAttributeValue($column, $amount, $extra, $method);

        // For the query builder, decrement should pass negative amount since it uses increment internally
        $queryAmount = $method === 'decrement' ? -$amount : $amount;
        $query->where($this->getKeyName(), $this->getKey())->increment($column, $queryAmount, $extra);

        return $this;
    }

    /**
     * Increment or decrement the given attribute using the underlying query function.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array  $extra
     * @param  string  $method
     * @return void
     */
    protected function incrementOrDecrementAttributeValue($column, $amount, $extra, $method)
    {
        $this->{$column} = $this->{$column} ?? 0;

        if ($method === 'increment') {
            $this->{$column} += $amount;
        } else {
            $this->{$column} -= $amount;
        }

        // Apply casting if defined for this column
        if ($this->hasCast($column)) {
            $this->{$column} = $this->castAttribute($column, $this->{$column});
        } else {
            // Cast back to int if the result is a whole number
            if (is_numeric($this->{$column})) {
                $floatVal = (float) $this->{$column};
                if ($floatVal == floor($floatVal)) {
                    $this->{$column} = (int) $floatVal;
                }
            }
        }

        // Set extra columns and apply casting to them too
        if (! empty($extra)) {
            foreach ($extra as $key => $value) {
                $this->{$key} = $value;
                if ($this->hasCast($key)) {
                    $this->{$key} = $this->castAttribute($key, $this->{$key});
                }
            }
        }

        $this->syncOriginal();
    }

    /**
     * Determine if two models have the same ID and belong to the same table.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     * @return bool
     */
    public function is($model)
    {
        // Check if same object instance
        if ($this === $model) {
            return true;
        }

        return ! is_null($model) &&
               $this->getKey() === $model->getKey() &&
               ! is_null($this->getKey()) &&
               $this->getTable() === $model->getTable() &&
               $this->getConnectionName() === $model->getConnectionName();
    }

    /**
     * Determine if two models are not the same.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     * @return bool
     */
    public function isNot($model)
    {
        return ! $this->is($model);
    }
}
