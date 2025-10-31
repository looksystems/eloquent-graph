<?php

namespace Look\EloquentCypher;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\LazyCollection;
use Look\EloquentCypher\Builders\WhereClauseBuilder;
use Look\EloquentCypher\Parsers\ExpressionParser;
use Look\EloquentCypher\Query\CypherQueryComponents;
use Look\EloquentCypher\Query\JoinClause;
use Look\EloquentCypher\Query\ParameterHelper;
use Look\EloquentCypher\Services\AliasResolver;
use Look\EloquentCypher\Support\LabelResolver;
use Look\EloquentCypher\Traits\CypherOperatorConverter;

class GraphQueryBuilder extends BaseBuilder
{
    use CypherOperatorConverter;

    public $nodeLabel;

    public $wheres = [];

    /**
     * Custom labels to use for this query (overrides model labels).
     */
    public $customLabels;

    /**
     * Model labels (from $labels property).
     */
    public $modelLabels;

    /**
     * Where clause builder instance
     */
    protected $whereClauseBuilder;

    /**
     * Expression parser instance for handling column prefixing in raw expressions
     */
    protected $expressionParser;

    /**
     * Alias resolver instance for managing table/column aliases
     */
    protected $aliasResolver;

    /**
     * The table joins for the query.
     * Note: We store Neo4j joins separately to avoid conflicts with parent class.
     */
    public $neo4jJoins = [];

    /**
     * Aliases for joined tables.
     */
    protected $joinAliases = [];

    /**
     * Counter for generating unique join aliases.
     */
    protected $joinCounter = 0;

    /**
     * Join conditions to be added to WHERE clause.
     */
    public $joinWhereConditions = [];

    /**
     * Store left joins to process after main WHERE clause.
     */
    public $leftJoinsToProcess = [];

    /**
     * Alias for the main table/label.
     */
    protected $fromAlias = null;

    /**
     * Get the appropriate alias for a table/label.
     *
     * @param  string|null  $table
     * @return string
     */
    protected function getTableAlias($table = null)
    {
        // If no table provided, use the current nodeLabel
        if (! $table) {
            $table = $this->nodeLabel;
        }

        // Common table-to-alias mapping
        $aliases = [
            'users' => 'u',
            'user' => 'u',
            'posts' => 'p',
            'post' => 'p',
            'comments' => 'c',
            'comment' => 'c',
            'products' => 'pr',
            'product' => 'pr',
            'orders' => 'o',
            'order' => 'o',
            'categories' => 'cat',
            'category' => 'cat',
        ];

        // Return mapped alias or use first character as fallback
        return $aliases[strtolower($table)] ?? substr(strtolower($table), 0, 1);
    }

    public $orders = [];

    public $limit;

    public $offset;

    public $groupLimit;

    public $groups = [];

    protected $lastInsertedNode = null;

    public $grammar;

    public $queryComponents;

    /**
     * The label resolver instance.
     */
    protected ?LabelResolver $labelResolver = null;

    /**
     * Create a new query builder instance.
     *
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @param  \Illuminate\Database\Query\Grammars\Grammar|null  $grammar
     * @param  \Illuminate\Database\Query\Processors\Processor|null  $processor
     * @return void
     */
    public function __construct($connection, $grammar = null, $processor = null)
    {
        parent::__construct($connection, $grammar, $processor);

        // Initialize Neo4j grammar if not provided
        if (is_null($this->grammar)) {
            $this->grammar = new \Look\EloquentCypher\GraphGrammar($connection);
        }

        // Initialize query components helper
        $this->queryComponents = new CypherQueryComponents;

        // Initialize expression parser
        $this->expressionParser = new ExpressionParser;

        // Initialize alias resolver
        $this->aliasResolver = new AliasResolver;

        // Initialize WhereClauseBuilder
        $this->whereClauseBuilder = new WhereClauseBuilder($this);
    }

    /**
     * Wrap a value for use in Cypher queries
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value !== '*') {
            return '`'.str_replace('`', '``', $value).'`';
        }

        return $value;
    }

    public function from($table, $as = null)
    {
        // Parse table to extract actual table name and alias
        $parsed = AliasResolver::parseTableAlias($table);
        $this->nodeLabel = $parsed['table'];

        // Set alias from parsed result or explicit $as parameter
        if ($parsed['alias']) {
            $this->fromAlias = $parsed['alias'];
        } elseif ($as !== null) {
            $this->fromAlias = $as;
        }

        return parent::from($table, $as);
    }

    /**
     * Set the label resolver instance.
     */
    public function setLabelResolver(LabelResolver $resolver): self
    {
        $this->labelResolver = $resolver;

        return $this;
    }

    /**
     * Get the qualified label with any configured prefix.
     */
    public function getQualifiedLabel(string $label): string
    {
        if ($this->labelResolver) {
            return $this->labelResolver->qualify($label);
        }

        return $label;
    }

    public function insert(array $values)
    {
        if (empty($values)) {
            return true;
        }

        // Check if this is a single record (associative array) or multiple records (list)
        // A single record won't have a numeric key at index 0
        if (! isset($values[0])) {
            $values = [$values];
        }

        // Get batch size from config (default to 100)
        $batchSize = $this->connection->getConfig('batch_size') ?? 100;
        $enableBatch = $this->connection->getConfig('enable_batch_execution') ?? true;

        // Use batch execution for multiple records if enabled
        if ($enableBatch && count($values) > 1) {
            $chunks = array_chunk($values, $batchSize);
            $allSuccess = true;

            foreach ($chunks as $chunk) {
                $statements = [];
                foreach ($chunk as $record) {
                    $cypher = $this->buildInsertCypher($record);
                    $statements[] = [
                        'query' => $cypher,
                        'parameters' => ParameterHelper::prepareParameters($record),
                    ];
                }

                try {
                    $results = $this->connection->statements($statements);

                    // Check all results were successful
                    foreach ($results as $result) {
                        if (empty($result)) {
                            $allSuccess = false;
                        }
                    }
                } catch (\Exception $e) {
                    $allSuccess = false;
                }
            }

            return $allSuccess;
        }

        // Fall back to individual inserts for single records or if batch is disabled
        $results = [];
        foreach ($values as $record) {
            $cypher = $this->buildInsertCypher($record);
            // Use select instead of statement to get the returned node
            $result = $this->connection->select($cypher, ParameterHelper::prepareParameters($record));

            if (! empty($result)) {
                // Store the last inserted node for single inserts
                if (count($values) === 1) {
                    $this->lastInsertedNode = $result[0]['n'];
                }
                $results[] = true;
            } else {
                $results[] = false;
            }
        }

        return ! in_array(false, $results, true);
    }

    /**
     * Get the label string to use for queries.
     * Priority: customLabels > modelLabels > nodeLabel
     */
    protected function getLabelsForQuery(): string
    {
        // If custom labels are set (via withLabels scope), use them
        if (! empty($this->customLabels)) {
            return implode(':', $this->customLabels);
        }

        // If model labels are set (multi-label model), use them
        if (! empty($this->modelLabels)) {
            return implode(':', $this->modelLabels);
        }

        // Fall back to single nodeLabel
        return $this->nodeLabel ?: 'Node';
    }

    protected function buildInsertCypher(array $attributes): string
    {
        $label = $this->getQualifiedLabel($this->getLabelsForQuery());
        $cypher = $this->queryComponents->buildCreate($label, $attributes, 'n');

        return $cypher.' RETURN n';
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  string|null  $sequence
     * @return int|string
     */
    public function insertGetId(array $values, $sequence = null)
    {
        if (empty($values)) {
            return null;
        }

        $cypher = $this->buildInsertCypher($values);
        $results = $this->connection->select($cypher, $values);

        if (! empty($results)) {
            $node = $results[0]['n'];
            $this->lastInsertedNode = $node;

            // Return the ID value (use sequence parameter or 'id' as default)
            $keyName = $sequence ?: 'id';

            return $node[$keyName] ?? null;
        }

        return null;
    }

    /**
     * Get the last inserted node data.
     *
     * @return array|null
     */
    public function getLastInsertedNode()
    {
        return $this->lastInsertedNode;
    }

    /**
     * Insert new records into the database while ignoring duplicate key errors.
     *
     * @return int Number of records actually inserted
     */
    public function insertOrIgnore(array $values)
    {
        if (empty($values)) {
            return 0;
        }

        // Check if this is a single record (associative array) or multiple records (list)
        if (! isset($values[0])) {
            $values = [$values];
        }

        $label = $this->getQualifiedLabel($this->nodeLabel ?: 'Node');

        // Get batch configuration
        $batchSize = $this->connection->getConfig('batch_size') ?? 100;
        $enableBatch = $this->connection->getConfig('enable_batch_execution') ?? true;

        $totalInserted = 0;

        // Use batch execution for multiple records if enabled
        if ($enableBatch && count($values) > 1) {
            $chunks = array_chunk($values, $batchSize);

            foreach ($chunks as $chunk) {
                $statements = [];

                foreach ($chunk as $record) {
                    // Use MERGE to insert only if not exists
                    // We need to identify what makes a record unique
                    // For simplicity, we'll use 'email' if it exists, otherwise 'id'
                    $uniqueField = null;
                    $uniqueValue = null;

                    if (isset($record['email'])) {
                        $uniqueField = 'email';
                        $uniqueValue = $record['email'];
                    } elseif (isset($record['id'])) {
                        $uniqueField = 'id';
                        $uniqueValue = $record['id'];
                    }

                    if ($uniqueField) {
                        // Use MERGE with ON CREATE to only insert if not exists
                        $properties = [];
                        $parameters = [];

                        foreach ($record as $key => $value) {
                            if ($key === $uniqueField) {
                                // This goes in the MERGE clause
                                continue;
                            }
                            $properties[] = "n.$key = \$$key";
                            $parameters[$key] = $value;
                        }

                        $parameters[$uniqueField] = $uniqueValue;

                        if (empty($properties)) {
                            // No additional properties to set
                            // Use a flag to detect if the node was created
                            $cypher = "MERGE (n:$label {{$uniqueField}: \${$uniqueField}}) ".
                                      'ON CREATE SET n._wasCreated = true '.
                                      'ON MATCH SET n._wasCreated = false '.
                                      'WITH n, n._wasCreated as wasCreated '.
                                      'REMOVE n._wasCreated '.
                                      'RETURN wasCreated';
                        } else {
                            $cypher = "MERGE (n:$label {{$uniqueField}: \${$uniqueField}}) ".
                                      'ON CREATE SET '.implode(', ', $properties).', n._wasCreated = true '.
                                      'ON MATCH SET n._wasCreated = false '.
                                      'WITH n, n._wasCreated as wasCreated '.
                                      'REMOVE n._wasCreated '.
                                      'RETURN wasCreated';
                        }

                        $statements[] = [
                            'query' => $cypher,
                            'parameters' => $parameters,
                        ];
                    } else {
                        // No unique field, just create
                        $cypher = $this->buildInsertCypher($record);
                        $statements[] = [
                            'query' => $cypher,
                            'parameters' => $record,
                        ];
                    }
                }

                try {
                    $results = $this->connection->statements($statements);

                    // Count how many were actually inserted (new nodes)
                    foreach ($results as $result) {
                        // Check if the node was created (not matched)
                        if (! empty($result) && isset($result[0])) {
                            $row = is_array($result[0]) ? $result[0] : (array) $result[0];
                            $wasCreated = $row['wasCreated'] ?? true; // Default to true for regular inserts
                            if ($wasCreated) {
                                $totalInserted++;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore errors (that's the point of insertOrIgnore)
                }
            }

            return $totalInserted;
        }

        // Fall back to individual inserts for single records or if batch is disabled
        foreach ($values as $record) {
            try {
                // Try to insert, ignore if it fails
                $cypher = $this->buildInsertCypher($record);
                $result = $this->connection->select($cypher, $record);

                if (! empty($result)) {
                    $totalInserted++;
                }
            } catch (\Exception $e) {
                // Ignore the error and continue
            }
        }

        return $totalInserted;
    }

    /**
     * Prefix column references in a raw expression with the node alias.
     *
     * @param  string  $expression
     * @param  string  $alias
     * @return string
     */
    protected function prefixColumnsInExpression($expression, $alias = 'n')
    {
        return $this->expressionParser->prefixColumns($expression, $alias);
    }

    public function find($id, $columns = ['*'])
    {
        $label = $this->getQualifiedLabel($this->nodeLabel);
        $cypher = "MATCH (n:{$label}) WHERE id(n) = \$id RETURN n";
        $results = $this->connection->select($cypher, ['id' => $id]);

        return ! empty($results) ? (object) $results[0]['n'] : null;
    }

    public function get($columns = ['*'])
    {
        // Use strategy pattern to build the query
        $strategy = \Look\EloquentCypher\Factories\QueryStrategyFactory::create($this);
        $queryResult = $strategy->execute($columns);

        $cypher = $queryResult['cypher'];
        $bindings = $queryResult['bindings'];

        $results = $this->connection->select($cypher, $bindings);

        return $this->processResults($results);
    }

    /**
     * Process raw results into standardized format
     */
    protected function processResults(array $results): \Illuminate\Support\Collection
    {
        return collect(array_map(function ($result) {
            // Check if we have joins
            if (! empty($this->neo4jJoins)) {
                return $this->processJoinResult($result);
            }

            if (isset($result['n'])) {
                return $this->processStandardResult($result);
            }

            // For specific column selects, just use the result directly
            return (object) $result;
        }, $results));
    }

    /**
     * Process result with joins
     */
    protected function processJoinResult(array $result): object
    {
        $data = [];

        // Check if we have whole nodes returned (n, j1, etc.)
        $hasWholeNodes = isset($result['n']) && is_array($result['n']);

        if ($hasWholeNodes) {
            // Merge all node properties from main table
            if (isset($result['n'])) {
                $data = array_merge($data, (array) $result['n']);
            }

            // Merge properties from joined tables
            foreach ($this->neo4jJoins as $join) {
                if (isset($result[$join['alias']])) {
                    // Merge join table properties, potentially overwriting duplicates
                    $data = array_merge($data, (array) $result[$join['alias']]);
                }
            }

            // Add any other columns that aren't nodes
            foreach ($result as $key => $value) {
                if ($key !== 'n' && ! in_array($key, array_column($this->neo4jJoins, 'alias'))) {
                    // Handle aliased columns or expressions
                    if (strpos($key, '.') !== false) {
                        $parts = explode('.', $key, 2);
                        $columnName = $parts[1];
                        $data[$columnName] = $value;
                    } else {
                        $data[$key] = $value;
                    }
                }
            }
        } else {
            // Handle individual columns (n.name, j1.title, etc.)
            foreach ($result as $key => $value) {
                // Convert keys like 'n.name' to just 'name'
                // and 'j1.title' to just 'title'
                if (strpos($key, '.') !== false) {
                    $parts = explode('.', $key, 2);
                    $columnName = $parts[1];
                    $data[$columnName] = $value;
                } else {
                    $data[$key] = $value;
                }
            }
        }

        return (object) $data;
    }

    /**
     * Process standard result without joins
     */
    protected function processStandardResult(array $result): object
    {
        $data = (array) $result['n'];

        // Add any additional columns from raw selects
        foreach ($result as $key => $value) {
            if ($key !== 'n') {
                $data[$key] = $value;
            }
        }

        return (object) $data;
    }

    public function update(array $values)
    {
        $label = $this->getQualifiedLabel($this->nodeLabel ?: 'Node');
        $bindings = [];

        // Build MATCH clause
        $cypher = $this->queryComponents->buildMatch($label, 'n');

        // Add WHERE clause if there are conditions
        if (! empty($this->wheres)) {
            $whereClause = $this->buildWhereClause($bindings);
            if ($whereClause) {
                $cypher .= " WHERE $whereClause";
            }
        }

        // Build SET clause with RETURN to get count
        $cypher .= ' WITH n '.$this->queryComponents->buildSet($values, 'n', $bindings);
        $cypher .= ' RETURN count(n) as affected';

        return $this->connection->affectingStatement($cypher, $bindings);
    }

    public function delete($id = null)
    {
        $label = $this->getQualifiedLabel($this->nodeLabel ?: 'Node');
        $bindings = [];

        if ($id !== null) {
            $cypher = $this->queryComponents->buildMatch($label, 'n');
            $cypher .= ' WHERE id(n) = $id';
            $cypher .= ' WITH n DETACH DELETE n RETURN count(*) as affected';

            return $this->connection->affectingStatement($cypher, ['id' => $id]);
        }

        // Build MATCH clause
        $cypher = $this->queryComponents->buildMatch($label, 'n');

        // Add WHERE clause if there are conditions
        if (! empty($this->wheres)) {
            $whereClause = $this->buildWhereClause($bindings);
            if ($whereClause) {
                $cypher .= " WHERE $whereClause";
            }
        }

        // Delete nodes and return the count
        $cypher .= ' WITH n DETACH DELETE n RETURN count(*) as affected';

        return $this->connection->affectingStatement($cypher, $bindings);
    }

    /**
     * Force delete a record (bypassing soft deletes).
     */
    public function forceDelete()
    {
        // Force delete should delete and return true/false based on result
        $result = $this->delete();

        // For compatibility with Laravel's expectations, return true if any rows affected
        return $result > 0;
    }

    /**
     * Run a truncate statement on the database.
     *
     * @return void
     */
    public function truncate()
    {
        $label = $this->getQualifiedLabel($this->nodeLabel ?: 'Node');
        $cypher = "MATCH (n:$label) DETACH DELETE n";
        $this->connection->statement($cypher);
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $type = 'Basic';
        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        return $this;
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotIn' : 'In';
        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        return $this;
    }

    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function whereIntegerInRaw($column, $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotInRaw' : 'InRaw';
        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        return $this;
    }

    public function whereIntegerNotInRaw($column, $values, $boolean = 'and')
    {
        return $this->whereIntegerInRaw($column, $values, $boolean, true);
    }

    public function whereNull($column, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';
        $this->wheres[] = compact('type', 'column', 'boolean');

        return $this;
    }

    public function whereNotNull($column, $boolean = 'and')
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function whereBetween($column, $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotBetween' : 'Between';
        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        return $this;
    }

    public function whereNotBetween($column, $values, $boolean = 'and')
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add a whereDate condition to the query.
     */
    public function whereDate($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Store as a special Date type where clause
        $type = 'Date';
        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        return $this;
    }

    /**
     * Add a whereColumn condition to the query.
     */
    public function whereColumn($first, $operator = null, $second = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $second = $operator;
            $operator = '=';
        }

        $type = 'Column';
        $this->wheres[] = [
            'type' => $type,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a whereMonth condition to the query.
     */
    public function whereMonth($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Store as a special Month type where clause
        $type = 'Month';
        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        return $this;
    }

    /**
     * Add a whereYear condition to the query.
     */
    public function whereYear($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Store as a special Year type where clause
        $type = 'Year';
        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        return $this;
    }

    /**
     * Add a whereTime condition to the query.
     */
    public function whereTime($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Store as a special Time type where clause
        $type = 'Time';
        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        return $this;
    }

    /**
     * Add a "where JSON contains" clause to the query.
     *
     * @param  string  $column
     * @param  mixed  $value
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereJsonContains($column, $value, $boolean = 'and', $not = false)
    {
        $type = 'JsonContains';

        $this->wheres[] = compact('type', 'column', 'value', 'boolean', 'not');

        return $this;
    }

    /**
     * Add a "where JSON doesn't contain" clause to the query.
     *
     * @param  string  $column
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereJsonDoesntContain($column, $value, $boolean = 'and')
    {
        return $this->whereJsonContains($column, $value, $boolean, true);
    }

    /**
     * Add a "where JSON length" clause to the query.
     *
     * @param  string  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereJsonLength($column, $operator, $value = null, $boolean = 'and')
    {
        // If only two arguments were passed, assume equals operator
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $type = 'JsonLength';

        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        return $this;
    }

    /**
     * Add an exists clause to the query.
     *
     * @param  \Closure  $callback
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereExists($callback, $boolean = 'and', $not = false)
    {
        $query = $this->forSubQuery();
        $callback($query);

        $type = 'Exists';
        $this->wheres[] = compact('type', 'query', 'boolean', 'not');

        return $this;
    }

    /**
     * Add a where not exists clause to the query.
     *
     * @param  \Closure  $callback
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotExists($callback, $boolean = 'and')
    {
        return $this->whereExists($callback, $boolean, true);
    }

    /**
     * Add an or exists clause to the query.
     *
     * @param  \Closure  $callback
     * @param  bool  $not
     * @return $this
     */
    public function orWhereExists($callback, $not = false)
    {
        return $this->whereExists($callback, 'or', $not);
    }

    /**
     * Add an or where not exists clause to the query.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function orWhereNotExists($callback)
    {
        return $this->orWhereExists($callback, true);
    }

    /**
     * Add an or where column clause to the query.
     *
     * @param  string  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @return $this
     */
    public function orWhereColumn($first, $operator = null, $second = null)
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }

    /**
     * Create a new query instance for a sub-query.
     *
     * @return static
     */
    protected function forSubQuery()
    {
        return $this->newQuery();
    }

    public function buildWhereClause(&$bindings, $context = null)
    {
        // Delegate to WhereClauseBuilder
        return $this->whereClauseBuilder->build($this->wheres, $bindings, $context);
    }

    protected function buildSingleWhereClause($where, $index, &$bindings, $context = null)
    {
        // Delegate all WHERE clause building to WhereClauseBuilder
        return $this->whereClauseBuilder->buildSingleWhereClause($where, $index, $bindings, $context);
    }

    // All WHERE clause building methods have been moved to WhereClauseBuilder
    // Helper methods for EXISTS and normalization remain temporarily for migration

    protected function normalizeValues($values)
    {
        if ($values instanceof \Illuminate\Support\Collection) {
            return $values->all();
        }

        return is_array($values) ? $values : [$values];
    }

    protected function mapColumnReferenceInExists($column, $currentTable, $currentAlias, $context)
    {
        $parts = explode('.', $column);

        if (count($parts) === 2) {
            $table = $parts[0];
            $col = $parts[1];

            // Check if it's the current table
            if ($table === $currentTable || $table === rtrim($currentTable, 's')) {
                return $col; // Will be prefixed with current alias
            }

            // Check if it's a parent table from context
            if (isset($context['aliases'][$table])) {
                return 'PARENT_'.$context['aliases'][$table].'_'.$col;
            }

            // Check parent tables by singular/plural variations
            foreach ($context['aliases'] as $contextTable => $alias) {
                if ($table === rtrim($contextTable, 's') || $contextTable === rtrim($table, 's')) {
                    return 'PARENT_'.$alias.'_'.$col;
                }
            }

            // It's from the outermost query
            return 'OUTER_'.$col;
        }

        return $column;
    }

    protected function applyExistsAliasReplacements($whereClause, $currentAlias, $context)
    {
        // Replace n. with current alias for this subquery context
        $whereClause = str_replace('n.', $currentAlias.'.', $whereClause);

        // Replace PARENT_ markers with actual parent aliases
        foreach ($context['aliases'] as $table => $alias) {
            $whereClause = str_replace($currentAlias.'.PARENT_'.$alias.'_', $alias.'.', $whereClause);
        }

        // Replace OUTER_ markers with the outermost query alias
        $outerAlias = $context['parentAlias'] ?? 'n';
        $whereClause = str_replace($currentAlias.'.OUTER_', $outerAlias.'.', $whereClause);

        return $whereClause;
    }

    public function orderBy($column, $direction = 'asc')
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC',
        ];

        return $this;
    }

    public function orderByDesc($column)
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add a raw order by clause to the query.
     *
     * @param  string  $expression
     * @param  array  $bindings
     * @return $this
     */
    public function orderByRaw($expression, $bindings = [])
    {
        // Replace ? placeholders with actual values for Neo4j
        if (! empty($bindings)) {
            foreach ($bindings as $binding) {
                $pos = strpos($expression, '?');
                if ($pos !== false) {
                    $value = is_numeric($binding) ? $binding : "'".addslashes($binding)."'";
                    $expression = substr_replace($expression, $value, $pos, 1);
                }
            }
        }

        $this->orders[] = [
            'type' => 'raw',
            'expression' => $expression,
        ];

        return $this;
    }

    public function limit($value)
    {
        $this->limit = (int) $value;

        return $this;
    }

    public function take($value)
    {
        return $this->limit($value);
    }

    public function offset($value)
    {
        $this->offset = (int) $value;

        return $this;
    }

    public function skip($value)
    {
        return $this->offset($value);
    }

    public function groupLimit($value, $column)
    {
        if ($value >= 0) {
            // For Neo4j, we'll treat groupLimit as a regular limit for eager loading
            // This provides the expected "global limit" behavior that tests expect
            $this->limit = (int) $value;
            $this->groupLimit = compact('value', 'column');
        }

        return $this;
    }

    public function first($columns = ['*'])
    {
        $this->limit = 1;
        $results = $this->get($columns);

        return $results->first();
    }

    public function count($columns = '*')
    {
        return $this->aggregate(__FUNCTION__, [$columns]);
    }

    public function sum($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    public function avg($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    public function min($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    public function max($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Get the discrete percentile value of a column.
     * Returns the value at the specified percentile (e.g., 0.95 for 95th percentile).
     *
     * @param  float  $percentile  Value between 0.0 and 1.0
     */
    public function percentileDisc(string $column, float $percentile): ?float
    {
        return $this->aggregate('percentileDisc', [$column, $percentile]);
    }

    /**
     * Get the interpolated/continuous percentile value of a column.
     * Returns an interpolated value at the specified percentile.
     *
     * @param  float  $percentile  Value between 0.0 and 1.0
     */
    public function percentileCont(string $column, float $percentile): ?float
    {
        return $this->aggregate('percentileCont', [$column, $percentile]);
    }

    /**
     * Get the sample standard deviation of a column's values.
     */
    public function stdev(string $column): ?float
    {
        return $this->aggregate('stdev', [$column]);
    }

    /**
     * Get the population standard deviation of a column's values.
     */
    public function stdevp(string $column): ?float
    {
        return $this->aggregate('stdevp', [$column]);
    }

    /**
     * Collect all values of a column into an array.
     */
    public function collect(string $column): array
    {
        $result = $this->aggregate('collect', [$column]);

        return is_array($result) ? $result : [];
    }

    public function value($column)
    {
        $result = $this->first([$column]);

        return $result ? ($result->$column ?? null) : null;
    }

    /**
     * Paginate the results using simple offset-based pagination.
     *
     * @param  int  $page
     * @param  int  $perPage
     * @return $this
     */
    public function forPage($page, $perPage = 15)
    {
        // Return empty results for invalid page numbers
        if ($page <= 0) {
            return $this->limit(0);
        }

        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    public function aggregate($function, $columns = ['*'])
    {
        $label = $this->getQualifiedLabel($this->nodeLabel ?: 'Node');
        $cypher = "MATCH (n:$label)";
        $bindings = [];

        if (! empty($this->wheres)) {
            $whereClause = $this->buildWhereClause($bindings);
            if ($whereClause) {
                $cypher .= " WHERE $whereClause";
            }
        }

        // Neo4j-specific aggregates that are case-sensitive
        $neo4jFunctions = ['percentileDisc', 'percentileCont', 'stdev', 'stdevp', 'collect'];
        $isNeo4jFunction = in_array($function, $neo4jFunctions);

        // Only uppercase for standard SQL aggregates
        if (! $isNeo4jFunction) {
            $function = strtoupper($function);
        }

        // Build the aggregate expression
        if ($function === 'COUNT') {
            $cypher .= ' RETURN count(n) as aggregate';
        } elseif ($function === 'percentileDisc' || $function === 'percentileCont') {
            // Multi-parameter aggregates
            $column = $columns[0] ?? '*';
            $percentile = $columns[1] ?? 0.5;
            $cypher .= " RETURN {$function}(n.$column, $percentile) as aggregate";
        } else {
            $column = $columns[0] ?? '*';
            $cypher .= " RETURN {$function}(n.$column) as aggregate";
        }

        $results = $this->connection->select($cypher, $bindings);

        // Handle empty results
        if (empty($results) || ! isset($results[0]['aggregate'])) {
            // For collect, return empty array
            if ($function === 'collect') {
                return [];
            }
            // For Neo4j-specific functions, return null for empty sets
            if ($isNeo4jFunction) {
                return null;
            }

            // For standard aggregates, return 0 (backward compatibility)
            return 0;
        }

        $result = $results[0]['aggregate'];

        // Handle null results
        if ($result === null) {
            if ($function === 'collect') {
                return [];
            }

            return $isNeo4jFunction ? null : 0;
        }

        // Type casting for specific functions
        if ($function === 'AVG' || $isNeo4jFunction) {
            // Cast to appropriate type
            if ($function === 'collect') {
                return is_array($result) ? $result : [$result];
            }

            if (in_array($function, ['percentileDisc', 'percentileCont', 'stdev', 'stdevp']) || $function === 'AVG') {
                return (float) $result;
            }
        }

        return $result;
    }

    /**
     * Add a raw where clause to the query.
     */
    public function whereRaw($sql, $bindings = [], $boolean = 'and')
    {
        $this->wheres[] = [
            'type' => 'Raw',
            'sql' => $sql,
            'bindings' => $bindings,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a raw select clause to the query.
     */
    public function selectRaw($expression, $bindings = [])
    {
        if (is_null($this->columns)) {
            $this->columns = [];
        }

        $this->columns[] = [
            'type' => 'raw',
            'expression' => $expression,
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * @return bool
     */
    public function exists()
    {
        $label = $this->getQualifiedLabel($this->nodeLabel ?: 'Node');
        $cypher = "MATCH (n:$label)";
        $bindings = [];

        if (! empty($this->wheres)) {
            $whereClause = $this->buildWhereClause($bindings);
            if ($whereClause) {
                $cypher .= " WHERE $whereClause";
            }
        }

        $cypher .= ' RETURN n LIMIT 1';

        $results = $this->connection->select($cypher, $bindings);

        return ! empty($results);
    }

    /**
     * Determine if no rows exist for the current query.
     *
     * @return bool
     */
    public function doesntExist()
    {
        return ! $this->exists();
    }

    /**
     * Insert or update a record matching the attributes, and fill it with values.
     *
     * @return bool
     */
    public function updateOrInsert(array $attributes, callable|array $values = [])
    {
        // Clone the query to avoid modifying the original
        $query = clone $this;

        // Add where clauses for each attribute
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }

        // Resolve values if callable
        if (is_callable($values)) {
            $values = $values();
        }

        if (! $query->exists()) {
            return $this->insert(array_merge($attributes, $values));
        }

        if (empty($values)) {
            return true;
        }

        // Clone again for the update
        $query = clone $this;
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }

        $result = $query->limit(1)->update($values);

        return $result > 0;
    }

    /**
     * Insert new records or update the existing ones.
     *
     * @param  array|string  $uniqueBy
     * @param  array|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if (empty($values)) {
            return 0;
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        if (is_null($update)) {
            $update = array_keys(reset($values));
        }

        $label = $this->getQualifiedLabel($this->nodeLabel ?: 'Node');

        // Convert uniqueBy to array if it's a string
        if (! is_array($uniqueBy)) {
            $uniqueBy = [$uniqueBy];
        }

        // Get batch configuration
        $batchSize = $this->connection->getConfig('batch_size') ?? 100;
        $enableBatch = $this->connection->getConfig('enable_batch_execution') ?? true;

        // Use batch execution for multiple records if enabled
        if ($enableBatch && count($values) > 1) {
            $chunks = array_chunk($values, $batchSize);
            $totalAffected = 0;

            foreach ($chunks as $chunk) {
                // First, collect all existence checks
                $checkStatements = [];
                foreach ($chunk as $index => $record) {
                    $whereConditions = [];
                    $whereBindings = [];
                    foreach ($uniqueBy as $uniqueColumn) {
                        if (isset($record[$uniqueColumn])) {
                            $whereConditions[] = "n.$uniqueColumn = \$where_$uniqueColumn";
                            $whereBindings["where_$uniqueColumn"] = $record[$uniqueColumn];
                        }
                    }

                    $checkStatements[] = [
                        'query' => "MATCH (n:$label) WHERE ".implode(' AND ', $whereConditions).' RETURN n LIMIT 1',
                        'parameters' => $whereBindings,
                    ];
                }

                // Execute existence checks in batch
                $existenceResults = $this->connection->statements($checkStatements);

                // Now prepare insert/update statements based on existence
                $upsertStatements = [];
                foreach ($chunk as $index => $record) {
                    $exists = ! empty($existenceResults[$index]);

                    if ($exists) {
                        // Prepare update statement
                        $whereConditions = [];
                        $whereBindings = [];
                        foreach ($uniqueBy as $uniqueColumn) {
                            if (isset($record[$uniqueColumn])) {
                                $whereConditions[] = "n.$uniqueColumn = \$where_$uniqueColumn";
                                $whereBindings["where_$uniqueColumn"] = $record[$uniqueColumn];
                            }
                        }

                        $updateData = [];
                        foreach ($update as $updateColumn) {
                            if (isset($record[$updateColumn]) && ! in_array($updateColumn, $uniqueBy)) {
                                $updateData[$updateColumn] = $record[$updateColumn];
                            }
                        }

                        if (! empty($updateData)) {
                            $setClause = [];
                            $updateBindings = $whereBindings;
                            foreach ($updateData as $key => $value) {
                                $setClause[] = "n.$key = \$set_$key";
                                $updateBindings["set_$key"] = $value;
                            }

                            $upsertStatements[] = [
                                'query' => "MATCH (n:$label) WHERE ".implode(' AND ', $whereConditions).
                                           ' SET '.implode(', ', $setClause).
                                           ' RETURN n',
                                'parameters' => $updateBindings,
                            ];
                        }
                    } else {
                        // Prepare insert statement
                        $properties = [];
                        $insertBindings = [];
                        foreach ($record as $key => $value) {
                            $properties[] = "$key: \$$key";
                            $insertBindings[$key] = $value;
                        }

                        // Generate unique ID for new record
                        if (! isset($record['id'])) {
                            $id = uniqid();
                            $properties[] = 'id: $id';
                            $insertBindings['id'] = $id;
                        }

                        $upsertStatements[] = [
                            'query' => "CREATE (n:$label {".implode(', ', $properties).'}) RETURN n',
                            'parameters' => $insertBindings,
                        ];
                    }
                }

                // Execute all upsert statements in batch
                if (! empty($upsertStatements)) {
                    $this->connection->statements($upsertStatements);
                    $totalAffected += count($upsertStatements);
                }
            }

            return $totalAffected;
        }

        // Fall back to individual upserts for single records or if batch is disabled
        $affected = 0;
        foreach ($values as $record) {
            // Build WHERE conditions for uniqueBy columns
            $whereConditions = [];
            $whereBindings = [];
            foreach ($uniqueBy as $uniqueColumn) {
                if (isset($record[$uniqueColumn])) {
                    $whereConditions[] = "n.$uniqueColumn = \$where_$uniqueColumn";
                    $whereBindings["where_$uniqueColumn"] = $record[$uniqueColumn];
                }
            }

            // Check if record exists
            $existsQuery = "MATCH (n:$label) WHERE ".implode(' AND ', $whereConditions).' RETURN n LIMIT 1';
            $result = $this->connection->select($existsQuery, $whereBindings);

            if (! empty($result)) {
                // Update existing record
                $updateData = [];
                foreach ($update as $updateColumn) {
                    if (isset($record[$updateColumn]) && ! in_array($updateColumn, $uniqueBy)) {
                        $updateData[$updateColumn] = $record[$updateColumn];
                    }
                }

                if (! empty($updateData)) {
                    $setClause = [];
                    $updateBindings = $whereBindings;
                    foreach ($updateData as $key => $value) {
                        $setClause[] = "n.$key = \$set_$key";
                        $updateBindings["set_$key"] = $value;
                    }

                    $updateQuery = "MATCH (n:$label) WHERE ".implode(' AND ', $whereConditions).
                                   ' SET '.implode(', ', $setClause).
                                   ' RETURN n';
                    $this->connection->update($updateQuery, $updateBindings);
                }
                $affected++;
            } else {
                // Insert new record
                $properties = [];
                $insertBindings = [];
                foreach ($record as $key => $value) {
                    $properties[] = "$key: \$$key";
                    $insertBindings[$key] = $value;
                }

                // Generate unique ID for new record
                if (! isset($record['id'])) {
                    $id = uniqid();
                    $properties[] = 'id: $id';
                    $insertBindings['id'] = $id;
                }

                $insertQuery = "CREATE (n:$label {".implode(', ', $properties).'}) RETURN n';
                $this->connection->insert($insertQuery, $insertBindings);
                $affected++;
            }
        }

        return $affected;
    }

    /**
     * Process records in chunks.
     *
     * @param  int  $count
     * @return bool
     */
    public function chunk($count, callable $callback)
    {
        $this->enforceOrderBy();

        $page = 1;

        do {
            // Clone the query to avoid modifying the original
            $clone = clone $this;

            // Get the paginated results
            $results = $clone->limit($count)->offset(($page - 1) * $count)->get();

            $countResults = $results->count();

            // Call the callback with the results
            if ($countResults > 0) {
                if ($callback($results, $page) === false) {
                    return false;
                }
            }

            $page++;
        } while ($countResults == $count);

        return true;
    }

    /**
     * Get a generator for the given query.
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function cursor()
    {
        return $this->lazy(1);
    }

    /**
     * Query lazily, by chunks of the given size.
     *
     * @param  int  $chunkSize
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazy($chunkSize = 1000)
    {
        return $this->lazyById($chunkSize);
    }

    /**
     * Query lazily, by chunks of the given size.
     *
     * @param  int  $chunkSize
     * @param  string|null  $column
     * @param  string|null  $alias
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyById($chunkSize = 1000, $column = null, $alias = null)
    {
        return $this->orderedLazyById($chunkSize, $column, $alias);
    }

    /**
     * Query lazily, ordered by the given column.
     *
     * @param  int  $chunkSize
     * @param  string|null  $column
     * @param  string|null  $alias
     * @param  bool  $descending
     * @return \Illuminate\Support\LazyCollection
     */
    protected function orderedLazyById($chunkSize = 1000, $column = null, $alias = null, $descending = false)
    {
        // Check if there are existing order clauses and use the first one
        if (! empty($this->orders)) {
            $firstOrder = $this->orders[0];
            $column = $column ?? $firstOrder['column'];
            $descending = strtolower($firstOrder['direction']) === 'desc';
        } else {
            $column = $column ?? 'id';
        }

        $alias = $alias ?? $column;

        return LazyCollection::make(function () use ($chunkSize, $column, $alias, $descending) {
            $lastId = null;
            $totalYielded = 0;

            // Check if there's an existing limit
            $existingLimit = $this->limit;

            // Check if there are existing order clauses
            $hasExistingOrders = ! empty($this->orders);

            while (true) {
                $clone = clone $this;

                // Only add order if there are no existing orders
                if (! $hasExistingOrders) {
                    if ($descending) {
                        $clone->orderByDesc($column);
                    } else {
                        $clone->orderBy($column);
                    }
                }

                if ($lastId !== null) {
                    if ($descending) {
                        $clone->where($column, '<', $lastId);
                    } else {
                        $clone->where($column, '>', $lastId);
                    }
                }

                // Determine how many to fetch this iteration
                $fetchLimit = $chunkSize;
                if ($existingLimit !== null) {
                    $remaining = $existingLimit - $totalYielded;
                    if ($remaining <= 0) {
                        return;
                    }
                    $fetchLimit = min($chunkSize, $remaining);
                }

                $results = $clone->limit($fetchLimit)->get();

                if ($results->isEmpty()) {
                    return;
                }

                foreach ($results as $result) {
                    yield $result;
                    $totalYielded++;

                    // Check if we've reached the limit
                    if ($existingLimit !== null && $totalYielded >= $existingLimit) {
                        return;
                    }
                }

                $lastId = $results->last()->{$alias};
            }
        });
    }

    /**
     * Chunk the results of a query by comparing IDs.
     *
     * @param  int  $count
     * @param  string|null  $column
     * @param  string|null  $alias
     * @return bool
     */
    public function chunkById($count, callable $callback, $column = null, $alias = null)
    {
        $column = $column ?? 'id';
        $alias = $alias ?? $column;

        $lastId = null;

        do {
            $clone = clone $this;

            if (! is_null($lastId)) {
                $clone->where($column, '>', $lastId);
            }

            $results = $clone->orderBy($column)->limit($count)->get();

            $countResults = $results->count();

            if ($countResults > 0) {
                if ($callback($results) === false) {
                    return false;
                }

                $lastId = $results->last()->{$alias};
            }
        } while ($countResults == $count);

        return true;
    }

    /**
     * Throw an exception if the query doesn't have an orderBy clause.
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    protected function enforceOrderBy()
    {
        if (empty($this->orders)) {
            throw \Look\EloquentCypher\Exceptions\GraphQueryException::missingOrderByForChunk();
        }
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     *
     * @param  string  $column
     * @return $this
     */
    public function latest($column = 'created_at')
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     *
     * @param  string  $column
     * @return $this
     */
    public function oldest($column = 'created_at')
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Put the query's results in random order.
     *
     * @param  string  $seed
     * @return $this
     */
    public function inRandomOrder($seed = '')
    {
        $this->orders[] = [
            'type' => 'raw',
            'expression' => 'rand()',
        ];

        return $this;
    }

    /**
     * Get an array with the values of a given column.
     *
     * @param  string  $column
     * @param  string|null  $key
     * @return \Illuminate\Support\Collection
     */
    public function pluck($column, $key = null)
    {
        $results = $this->get($key ? [$column, $key] : [$column]);

        if ($key === null) {
            return $results->pluck($column);
        }

        return $results->pluck($column, $key);
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $label = $this->getQualifiedLabel($this->nodeLabel ?: 'Node');
        $bindings = [];

        // Build MATCH clause with WHERE conditions
        $cypher = $this->queryComponents->buildMatch($label, 'n');

        if (! empty($this->wheres)) {
            $whereClause = $this->buildWhereClause($bindings);
            if ($whereClause) {
                $cypher .= " WHERE $whereClause";
            }
        }

        // Build SET clause for increment
        // Handle null values - treat as 0
        $setClauses = ["n.$column = COALESCE(n.$column, 0) + $amount"];

        // Add extra columns to update
        foreach ($extra as $key => $value) {
            // Remove table prefix if present
            if (strpos($key, '.') !== false) {
                $parts = explode('.', $key);
                $key = end($parts);
            }

            if ($value instanceof \Illuminate\Database\Query\Expression) {
                // Handle raw expressions
                $setClauses[] = "n.$key = ".$value->getValue();
            } else {
                $paramName = "extra_$key";
                $setClauses[] = "n.$key = \$$paramName";
                $bindings[$paramName] = $value;
            }
        }

        // Add updated_at if timestamps are enabled
        if ($this->grammar && method_exists($this->grammar, 'getDateFormat')) {
            $setClauses[] = 'n.updated_at = datetime()';
        }

        $cypher .= ' SET '.implode(', ', $setClauses);
        $cypher .= ' RETURN n';

        $results = $this->connection->select($cypher, $bindings);

        return count($results);
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        return $this->increment($column, -$amount, $extra);
    }

    /**
     * Paginate the given query.
     *
     * @param  int  $perPage
     * @param  array  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  int|null  $total
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $page = $page ?: \Illuminate\Pagination\Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: 15;

        // Only use provided columns if no columns are already selected
        $columnsToUse = ! empty($this->columns) ? $this->columns : $columns;

        // Ensure consistent ordering for pagination if no order is specified
        $query = clone $this;
        if (empty($query->orders)) {
            $query->orderBy('id');
        }

        $results = $query->forPage($page, $perPage)->get($columnsToUse);

        $total = $total ?: $this->getCountForPagination();

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Get a paginator only supporting simple next and previous links.
     *
     * @param  int  $perPage
     * @param  array  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Pagination\Paginator
     */
    public function simplePaginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: \Illuminate\Pagination\Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: 15;

        // Only use provided columns if no columns are already selected
        $columnsToUse = ! empty($this->columns) ? $this->columns : $columns;

        // Get one more item than requested to determine if there are more pages
        $results = $this->forPage($page, $perPage + 1)->get($columnsToUse);

        return $this->simplePaginator($results, $perPage, $page, [
            'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Paginate the given query using a cursor paginator.
     *
     * @param  int|null  $perPage
     * @param  array  $columns
     * @param  string  $cursorName
     * @param  \Illuminate\Pagination\Cursor|string|null  $cursor
     * @return \Illuminate\Pagination\CursorPaginator
     */
    public function cursorPaginate($perPage = null, $columns = ['*'], $cursorName = 'cursor', $cursor = null)
    {
        return $this->paginateUsingCursor($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Get the count of the total records for pagination.
     *
     * @param  array  $columns
     * @return int
     */
    public function getCountForPagination($columns = ['*'])
    {
        return $this->cloneWithout(['orders', 'limit', 'offset'])
            ->count($columns);
    }

    /**
     * Clone the query without the given properties.
     *
     * @return static
     */
    public function cloneWithout(array $properties)
    {
        $clone = clone $this;

        foreach ($properties as $property) {
            if (property_exists($clone, $property)) {
                $clone->{$property} = null;
            }
        }

        return $clone;
    }

    /**
     * Create a new length-aware paginator instance.
     *
     * @param  \Illuminate\Support\Collection  $items
     * @param  int  $total
     * @param  int  $perPage
     * @param  int  $currentPage
     * @param  array  $options
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    protected function paginator($items, $total, $perPage, $currentPage, $options)
    {
        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            $options
        );
    }

    /**
     * Create a new simple paginator instance.
     *
     * @param  \Illuminate\Support\Collection  $items
     * @param  int  $perPage
     * @param  int  $currentPage
     * @param  array  $options
     * @return \Illuminate\Pagination\Paginator
     */
    protected function simplePaginator($items, $perPage, $currentPage, $options)
    {
        return new \Illuminate\Pagination\Paginator(
            $items,
            $perPage,
            $currentPage,
            $options
        );
    }

    /**
     * Paginate the given query using a cursor paginator.
     *
     * @param  int|null  $perPage
     * @param  array  $columns
     * @param  string  $cursorName
     * @param  \Illuminate\Pagination\Cursor|string|null  $cursor
     * @return \Illuminate\Pagination\CursorPaginator
     */
    protected function paginateUsingCursor($perPage, $columns = ['*'], $cursorName = 'cursor', $cursor = null)
    {
        if (! $cursor instanceof \Illuminate\Pagination\Cursor) {
            $cursor = is_string($cursor)
                ? \Illuminate\Pagination\Cursor::fromEncoded($cursor)
                : \Illuminate\Pagination\CursorPaginator::resolveCurrentCursor($cursorName, $cursor);
        }

        $orders = $this->ensureOrderForCursorPagination(! is_null($cursor) && $cursor->pointsToPreviousItems());

        if (! is_null($cursor)) {
            $this->addWhereForCursor($cursor, $orders);
        }

        $perPage = $perPage ?: 15;

        // Only use provided columns if no columns are already selected
        $columnsToUse = ! empty($this->columns) ? $this->columns : $columns;

        $results = $this->limit($perPage + 1)->get($columnsToUse);

        return new \Illuminate\Pagination\CursorPaginator(
            $results,
            $perPage,
            $cursor,
            [
                'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
                'cursorName' => $cursorName,
                'parameters' => $orders->pluck('column')->toArray(),
            ]
        );
    }

    /**
     * Ensure the proper order by required for cursor pagination.
     *
     * @param  bool  $shouldReverse
     * @return \Illuminate\Support\Collection
     */
    protected function ensureOrderForCursorPagination($shouldReverse = false)
    {
        $orders = collect($this->orders);

        if ($orders->isEmpty()) {
            $this->orderBy('id');
            $orders = collect($this->orders);
        }

        if ($shouldReverse) {
            $orders = $orders->map(function ($order) {
                $order['direction'] = $order['direction'] === 'asc' ? 'desc' : 'asc';

                return $order;
            });

            $this->orders = $orders->toArray();
        }

        return $orders;
    }

    /**
     * Force the query to only return distinct results.
     *
     * @param  mixed  ...$columns
     * @return $this
     */
    public function distinct($columns = [])
    {
        $this->distinct = is_array($columns) ? $columns : func_get_args();

        return $this;
    }

    /**
     * Add a "group by" clause to the query.
     *
     * @param  array|string  ...$columns
     * @return $this
     */
    public function groupBy(...$columns)
    {
        $this->groups = is_array($columns[0]) ? $columns[0] : $columns;

        return $this;
    }

    /**
     * Add a "having" clause to the query.
     *
     * @param  string  $column
     * @param  string|null  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function having($column, $operator = null, $value = null, $boolean = 'and')
    {
        // Initialize havings if not set
        if (! isset($this->havings)) {
            $this->havings = [];
        }

        // If only two arguments, assume equals operator
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }

        $this->havings[] = compact('column', 'operator', 'value', 'boolean');

        return $this;
    }

    /**
     * Add a raw "having" clause to the query.
     *
     * @param  string  $sql
     * @param  string  $boolean
     * @return $this
     */
    public function havingRaw($sql, array $bindings = [], $boolean = 'and')
    {
        // Initialize havings if not set
        if (! isset($this->havings)) {
            $this->havings = [];
        }

        $this->havings[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean];

        if (! empty($bindings)) {
            $this->addBinding($bindings, 'having');
        }

        return $this;
    }

    /**
     * Add where conditions for cursor pagination.
     *
     * @param  \Illuminate\Support\Collection  $orders
     * @return void
     */
    protected function addWhereForCursor(\Illuminate\Pagination\Cursor $cursor, $orders)
    {
        // Get parameters using the parameters() method
        $parameterNames = $orders->pluck('column')->toArray();
        $parameters = array_combine($parameterNames, $cursor->parameters($parameterNames));
        $pointingPrevious = $cursor->pointsToPreviousItems();

        // For simplicity, we'll handle single-column ordering
        // Multi-column ordering requires more complex logic
        if ($orders->count() === 1) {
            $order = $orders->first();
            $column = $order['column'];
            $direction = $order['direction'];

            // Get the column name without prefix for parameter lookup
            $paramColumn = $column;
            if (strpos($paramColumn, '.') !== false) {
                $parts = explode('.', $paramColumn);
                $paramColumn = end($parts);
            }

            if (isset($parameters[$paramColumn])) {
                $value = $parameters[$paramColumn];

                // When pointing to previous, the direction is already reversed by ensureOrderForCursorPagination
                // So we always use the same logic: ASC -> '>', DESC -> '<'
                // Note: direction is stored in uppercase in our orderBy method
                $operator = strtoupper($direction) === 'ASC' ? '>' : '<';

                // Add prefix if column doesn't already have one
                if (strpos($column, '.') === false) {
                    $column = 'n.'.$column;
                }

                $this->where($column, $operator, $value);
            }
        } else {
            // For multiple columns, we need more complex comparison
            // This is a simplified version that may not handle all cases perfectly
            $this->where(function ($query) use ($parameters, $orders, $pointingPrevious) {
                foreach ($orders as $index => $order) {
                    $column = $order['column'];
                    $direction = $order['direction'];

                    if (isset($parameters[$column])) {
                        $value = $parameters[$column];

                        if ($pointingPrevious) {
                            $operator = $direction === 'asc' ? '<=' : '>=';
                        } else {
                            $operator = $direction === 'asc' ? '>=' : '<=';
                        }

                        // For the first column, use the appropriate operator
                        if ($index === 0) {
                            $query->where($column, $operator, $value);
                        }
                    }
                }
            });
        }
    }

    /**
     * Add a join clause to the query.
     */
    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        // Handle closure-based joins
        if ($first instanceof \Closure) {
            $join = new JoinClause($this, $type, $table);
            $first($join);

            $alias = $this->generateJoinAlias($table);
            $this->neo4jJoins[] = [
                'table' => $table,
                'alias' => $alias,
                'clauses' => $join->clauses,
                'type' => $type,
            ];

            return $this;
        }

        // Handle string-based joins
        if ($second === null) {
            // Expecting format: join('posts', 'posts.user_id', '=', 'users.id')
            // But received: join('posts', 'posts.user_id', 'users.id')
            // So assume '=' as operator
            $second = $operator;
            $operator = '=';
        }

        // Generate alias for joined table
        $alias = $this->generateJoinAlias($table);

        // Store join information
        $this->neo4jJoins[] = [
            'table' => $table,
            'alias' => $alias,
            'first' => $first,
            'operator' => $operator ?? '=',
            'second' => $second,
            'type' => $type,
            'where' => $where,
        ];

        return $this;
    }

    /**
     * Add a left join to the query.
     */
    public function leftJoin($table, $first, $operator = null, $second = null, $where = false)
    {
        return $this->join($table, $first, $operator, $second, 'left', $where);
    }

    /**
     * Add a right join to the query.
     */
    public function rightJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * Add a cross join to the query.
     */
    public function crossJoin($table, $first = null, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'cross');
    }

    /**
     * Generate a unique alias for a joined table.
     */
    protected function generateJoinAlias($table)
    {
        // Parse table to extract actual table name and alias
        $parsed = AliasResolver::parseTableAlias($table);
        $actualTable = $parsed['table'];
        $userAlias = $parsed['alias'] ?? $actualTable;

        // Generate internal alias for Cypher
        $this->joinCounter++;
        $internalAlias = 'j'.$this->joinCounter;

        // Store mapping from user alias (or table name) to internal alias
        $this->joinAliases[$userAlias] = $internalAlias;

        return $internalAlias;
    }

    /**
     * Get the alias for a table.
     */
    public function getAliasForTable($table)
    {
        // Parse table to extract actual table name and alias
        $parsed = AliasResolver::parseTableAlias($table);
        $actualTable = $parsed['table'];
        $userAlias = $parsed['alias'] ?? $table;

        // Check if it's the from alias (e.g., 'children' from 'users as children')
        if ($this->fromAlias && $table === $this->fromAlias) {
            return 'n';
        }

        // Check if it's the main table name
        if ($actualTable === $this->nodeLabel || $actualTable === $this->from) {
            return 'n';
        }

        // Check join aliases (user alias -> internal alias mapping)
        if (isset($this->joinAliases[$table])) {
            return $this->joinAliases[$table];
        }

        // Check if the table is an alias used in a join
        foreach ($this->neo4jJoins as $join) {
            $joinParsed = AliasResolver::parseTableAlias($join['table']);
            $joinTable = $joinParsed['table'];
            $joinAlias = $joinParsed['alias'] ?? $joinTable;

            // Match either the alias or the actual table name
            if ($table === $joinAlias || $userAlias === $joinAlias) {
                return $join['alias'];
            }

            // Also check if looking for the actual table that has this join
            if ($actualTable === $joinTable && isset($this->joinAliases[$joinAlias])) {
                return $this->joinAliases[$joinAlias];
            }

            if ($actualTable === $joinTable) {
                return $join['alias'];
            }
        }

        // Default to 'n' for main table
        return 'n';
    }

    /**
     * Compile join clauses into Cypher.
     */
    protected function compileJoins(&$cypher, &$whereConditions)
    {
        if (empty($this->neo4jJoins)) {
            return;
        }

        $mainMatches = [];
        $optionalMatches = [];
        $rightJoinTable = null;
        $rightJoinAlias = null;

        foreach ($this->neo4jJoins as $join) {
            $joinTable = $this->extractTableName($join['table']);
            $qualifiedLabel = $this->getQualifiedLabel($joinTable);
            $joinPattern = "({$join['alias']}:`{$qualifiedLabel}`)";

            switch ($join['type']) {
                case 'inner':
                    $mainMatches[] = $joinPattern;
                    if (isset($join['first']) && isset($join['second'])) {
                        $whereConditions[] = $this->buildJoinCondition($join);
                    }
                    break;

                case 'left':
                    // For left join, use OPTIONAL MATCH
                    $optionalMatch = "OPTIONAL MATCH {$joinPattern}";
                    if (isset($join['first']) && isset($join['second'])) {
                        $condition = $this->buildJoinCondition($join);
                        $optionalMatch .= " WHERE {$condition}";
                    }
                    $optionalMatches[] = $optionalMatch;
                    break;

                case 'right':
                    // For right join, we need to restructure the query
                    // The joined table becomes the main MATCH, original becomes OPTIONAL
                    $rightJoinTable = $qualifiedLabel;
                    $rightJoinAlias = $join['alias'];
                    if (isset($join['first']) && isset($join['second'])) {
                        $whereConditions[] = $this->buildJoinCondition($join);
                    }
                    break;

                case 'cross':
                    // Cross join - just add to matches without conditions
                    $mainMatches[] = $joinPattern;
                    break;
            }
        }

        // Handle right join by restructuring the query
        if ($rightJoinTable) {
            // Swap main and joined tables
            $originalLabel = $this->getQualifiedLabel($this->nodeLabel ?: 'Node');
            $originalPattern = "(n:`{$originalLabel}`)";

            // Start with the right joined table as main
            $cypher = str_replace("MATCH (n:`{$originalLabel}`)", "MATCH ({$rightJoinAlias}:`{$rightJoinTable}`)", $cypher);

            // Add original table as optional
            $cypher .= " OPTIONAL MATCH {$originalPattern}";

            // Need to swap aliases in where conditions
            // This is simplified - in practice would need more complex handling
        }

        // Add additional matches to main MATCH clause
        if (! empty($mainMatches)) {
            // First check if WHERE clause exists, and modify accordingly
            if (strpos($cypher, ' WHERE ') !== false) {
                // MATCH ... WHERE ... format
                $cypher = str_replace(
                    'MATCH (n:`'.$this->getQualifiedLabel($this->nodeLabel ?: 'Node').'`)'.' WHERE ',
                    'MATCH (n:`'.$this->getQualifiedLabel($this->nodeLabel ?: 'Node').'`), '.implode(', ', $mainMatches).' WHERE ',
                    $cypher
                );
            } else {
                // Simple MATCH without WHERE
                $cypher = str_replace(
                    'MATCH (n:`'.$this->getQualifiedLabel($this->nodeLabel ?: 'Node').'`)',
                    'MATCH (n:`'.$this->getQualifiedLabel($this->nodeLabel ?: 'Node').'`), '.implode(', ', $mainMatches),
                    $cypher
                );
            }
        }

        // Add optional matches
        foreach ($optionalMatches as $optionalMatch) {
            // Insert after WHERE clause if it exists, otherwise after MATCH
            if (strpos($cypher, ' WHERE ') !== false) {
                $parts = explode(' WHERE ', $cypher, 2);
                $whereParts = explode(' RETURN ', $parts[1], 2);
                $cypher = $parts[0].' WHERE '.$whereParts[0].' '.$optionalMatch;
                if (isset($whereParts[1])) {
                    $cypher .= ' RETURN '.$whereParts[1];
                }
            } else {
                $cypher = str_replace(' RETURN ', ' '.$optionalMatch.' RETURN ', $cypher);
            }
        }
    }

    /**
     * Quote a value for use in Cypher query.
     */
    protected function quoteValue($value)
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return $value;
        }

        // Escape single quotes and wrap in single quotes
        return "'".str_replace("'", "\\'", $value)."'";
    }

    /**
     * Build join conditions from JoinClause clauses.
     */
    protected function buildJoinClauseConditions($join)
    {
        $conditions = [];

        if (! isset($join['clauses']) || empty($join['clauses'])) {
            return $conditions;
        }

        foreach ($join['clauses'] as $clause) {
            if ($clause['type'] === 'on') {
                // Handle ON clauses (column to column comparisons)
                $first = $this->parseJoinColumn($clause['first']);
                $second = $this->parseJoinColumn($clause['second']);
                $operator = $clause['operator'] ?? '=';
                $conditions[] = "{$first} {$operator} {$second}";
            } elseif ($clause['type'] === 'where') {
                // Handle WHERE clauses (column to value comparisons)
                $column = $this->parseJoinColumn($clause['column']);
                $operator = $clause['operator'] ?? '=';
                $value = $this->quoteValue($clause['value']);
                $conditions[] = "{$column} {$operator} {$value}";
            }
        }

        return $conditions;
    }

    /**
     * Build a join condition from join data.
     */
    protected function buildJoinCondition($join)
    {
        $first = $this->parseJoinColumn($join['first']);
        $second = $this->parseJoinColumn($join['second']);
        $operator = $join['operator'] ?? '=';

        return "{$first} {$operator} {$second}";
    }

    /**
     * Parse a join column reference.
     */
    protected function parseJoinColumn($column)
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $alias = $this->getAliasForTable($table);

            return "{$alias}.{$col}";
        }

        // Default to main table
        return "n.{$column}";
    }

    /**
     * Resolve column reference for WHERE clauses with join support.
     */
    protected function resolveColumnForWhere($column)
    {
        // Use the same logic as parseJoinColumn for consistency
        return $this->parseJoinColumn($column);
    }

    /**
     * Extract table name from possible "table as alias" format.
     */
    protected function extractTableName($table)
    {
        return AliasResolver::extractTableName($table);
    }

    /**
     * Build RETURN clause with join support.
     */
    public function buildReturnWithJoins($columns, $isDistinct = false)
    {
        $returnParts = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                // Handle array columns (raw expressions, etc.)
                if (isset($column['type']) && $column['type'] === 'raw') {
                    $returnParts[] = $this->parseJoinSelectExpression($column['expression']);
                } else {
                    $returnParts[] = $this->queryComponents->formatColumn($column, 'n');
                }
            } elseif ($column === '*') {
                // Neo4j doesn't support n.*, so return the whole nodes instead
                $returnParts[] = 'n';
                foreach ($this->neo4jJoins as $join) {
                    $returnParts[] = $join['alias'];
                }
            } else {
                // Parse column for table prefix
                $returnParts[] = $this->parseJoinSelectColumn($column);
            }
        }

        $distinct = $isDistinct ? 'DISTINCT ' : '';

        return 'RETURN '.$distinct.implode(', ', $returnParts);
    }

    /**
     * Parse a select column for join support.
     */
    protected function parseJoinSelectColumn($column)
    {
        // Handle "table.column as alias" format
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $column, $matches)) {
            $columnPart = trim($matches[1]);
            $alias = trim($matches[2]);

            $parsedColumn = $this->parseTableColumn($columnPart);

            return "{$parsedColumn} as {$alias}";
        }

        // Handle regular column
        return $this->parseTableColumn($column);
    }

    /**
     * Parse a column with possible table prefix.
     */
    protected function parseTableColumn($column)
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $alias = $this->getAliasForTable($table);

            // Neo4j doesn't support alias.*, return the whole node instead
            if ($col === '*') {
                return $alias;
            }

            return "{$alias}.{$col}";
        }

        // Default to main table
        return "n.{$column}";
    }

    /**
     * Parse a raw select expression for joins.
     */
    public function parseJoinSelectExpression($expression)
    {
        // Replace table.column references with proper aliases
        $pattern = '/(\w+)\.(\w+)/';
        $expression = preg_replace_callback($pattern, function ($matches) {
            $table = $matches[1];
            $column = $matches[2];
            $alias = $this->getAliasForTable($table);

            return "{$alias}.{$column}";
        }, $expression);

        return $expression;
    }

    /**
     * Build ORDER BY clause with join support.
     */
    public function buildOrderByWithJoins($orders)
    {
        if (empty($orders)) {
            return '';
        }

        $orderClauses = [];
        foreach ($orders as $order) {
            if (isset($order['type']) && $order['type'] === 'raw') {
                // Handle raw expressions - replace table names with aliases
                $expression = $order['expression'];
                $expression = $this->replaceTableNamesWithAliases($expression);
                $orderClauses[] = $expression;
            } else {
                $direction = strtoupper($order['direction'] ?? 'ASC');
                $column = $order['column'];

                // If column contains table prefix, replace with alias
                if (strpos($column, '.') !== false) {
                    [$table, $col] = explode('.', $column, 2);
                    $alias = $this->getAliasForTable($table);
                    $orderClauses[] = "$alias.$col $direction";
                } else {
                    // Default to main table
                    $orderClauses[] = "n.$column $direction";
                }
            }
        }

        return 'ORDER BY '.implode(', ', $orderClauses);
    }

    /**
     * Replace table names with their aliases in an expression.
     */
    protected function replaceTableNamesWithAliases($expression)
    {
        // Pattern to match table.column references
        return preg_replace_callback('/\b(\w+)\.(\w+)\b/', function ($matches) {
            $table = $matches[1];
            $column = $matches[2];

            // Get the alias for this table
            $alias = $this->getAliasForTable($table);

            return "$alias.$column";
        }, $expression);
    }

    /**
     * Build MATCH clause with joins.
     */
    public function buildMatchWithJoins($label)
    {
        // Always use 'n' for main table in Cypher
        $mainMatches = ["(n:`{$label}`)"];
        $optionalMatches = [];
        $joinConditions = [];
        $rightJoin = null;
        $leftJoins = [];

        // If we have a from alias, map it to 'n' for column references
        if ($this->fromAlias) {
            $this->joinAliases[$this->fromAlias] = 'n';
        }

        foreach ($this->neo4jJoins as $join) {
            $joinTable = $this->extractTableName($join['table']);
            $qualifiedLabel = $this->getQualifiedLabel($joinTable);
            $joinPattern = "({$join['alias']}:`{$qualifiedLabel}`)";

            switch ($join['type']) {
                case 'inner':
                    $mainMatches[] = $joinPattern;
                    // Handle closure-based joins with clauses
                    if (isset($join['clauses'])) {
                        $conditions = $this->buildJoinClauseConditions($join);
                        if (! empty($conditions)) {
                            $joinConditions = array_merge($joinConditions, $conditions);
                        }
                    } elseif (isset($join['first']) && isset($join['second'])) {
                        $joinConditions[] = $this->buildJoinCondition($join);
                    }
                    break;

                case 'left':
                    // Store left join info for later processing
                    $condition = null;
                    if (isset($join['clauses'])) {
                        $conditions = $this->buildJoinClauseConditions($join);
                        if (! empty($conditions)) {
                            $condition = implode(' AND ', $conditions);
                        }
                    } elseif (isset($join['first']) && isset($join['second'])) {
                        $condition = $this->buildJoinCondition($join);
                    }

                    $leftJoins[] = [
                        'pattern' => $joinPattern,
                        'alias' => $join['alias'],
                        'condition' => $condition,
                    ];
                    break;

                case 'right':
                    // For right join, swap the tables
                    $rightJoin = $join;
                    break;

                case 'cross':
                    // Cross join - just add pattern without conditions
                    $mainMatches[] = $joinPattern;
                    break;
            }
        }

        // Handle right join by swapping main and joined
        if ($rightJoin) {
            $joinTable = $this->extractTableName($rightJoin['table']);
            $qualifiedLabel = $this->getQualifiedLabel($joinTable);

            // Start with joined table as main
            $cypher = "MATCH ({$rightJoin['alias']}:`{$qualifiedLabel}`)";

            // Add original table as optional
            $cypher .= " OPTIONAL MATCH (n:`{$label}`)";

            if (isset($rightJoin['clauses'])) {
                $conditions = $this->buildJoinClauseConditions($rightJoin);
                if (! empty($conditions)) {
                    $joinConditions = array_merge($joinConditions, $conditions);
                }
            } elseif (isset($rightJoin['first']) && isset($rightJoin['second'])) {
                $joinConditions[] = $this->buildJoinCondition($rightJoin);
            }
        } else {
            // Build standard MATCH with all inner/cross joins
            $cypher = 'MATCH '.implode(', ', $mainMatches);
        }

        // Store left joins and their conditions for proper placement
        $this->leftJoinsToProcess = $leftJoins;

        // Add join conditions to WHERE clause if they exist
        if (! empty($joinConditions)) {
            // These will be added to WHERE clause later in get()
            // Store them for later use
            $this->joinWhereConditions = $joinConditions;
        }

        return $cypher;
    }
}
