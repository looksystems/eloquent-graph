<?php

namespace Look\EloquentCypher;

use Illuminate\Database\Schema\Grammars\Grammar;

class GraphSchemaGrammar extends Grammar
{
    public function compileCreateIndex($blueprint, $command)
    {
        $table = $blueprint->getTable();
        $columns = $command->get('columns');

        $cypher = [];
        foreach ($columns as $column) {
            $cypher[] = "CREATE INDEX ON :$table($column)";
        }

        return $cypher;
    }

    public function compileDropIndex($blueprint, $command)
    {
        $table = $blueprint->getTable();
        $columns = $command->get('columns');

        $cypher = [];
        foreach ($columns as $column) {
            $cypher[] = "DROP INDEX ON :$table($column)";
        }

        return $cypher;
    }
}
