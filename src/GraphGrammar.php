<?php

namespace Look\EloquentCypher;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar;

class GraphGrammar extends Grammar
{
    /**
     * Create a new query grammar instance.
     *
     * @return void
     */
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
    }

    /**
     * Get the value of a raw expression.
     *
     * @param  \Illuminate\Database\Query\Expression|string|int|float  $expression
     * @return string|int|float
     */
    public function getValue($expression)
    {
        if ($this->isExpression($expression)) {
            return $this->getValue($expression->getValue($this));
        }

        return $expression;
    }
}
