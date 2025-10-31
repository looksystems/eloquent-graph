<?php

namespace Look\EloquentCypher\Query;

class JoinClause
{
    /**
     * The type of join being performed.
     */
    public $type;

    /**
     * The table the join clause is joining to.
     */
    public $table;

    /**
     * The clauses for the join.
     */
    public $clauses = [];

    /**
     * The parent query builder.
     */
    protected $parentQuery;

    /**
     * Create a new join clause instance.
     */
    public function __construct($parentQuery, $type, $table)
    {
        $this->type = $type;
        $this->table = $table;
        $this->parentQuery = $parentQuery;
    }

    /**
     * Add an "on" clause to the join.
     */
    public function on($first, $operator = null, $second = null, $boolean = 'and')
    {
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        $this->clauses[] = [
            'type' => 'on',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add an "or on" clause to the join.
     */
    public function orOn($first, $operator = null, $second = null)
    {
        return $this->on($first, $operator, $second, 'or');
    }

    /**
     * Add a "where" clause to the join.
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->clauses[] = [
            'type' => 'where',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add an "or where" clause to the join.
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        return $this->where($column, $operator, $value, 'or');
    }
}
