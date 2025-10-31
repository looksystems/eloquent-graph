<?php

namespace Look\EloquentCypher\Commands\Schema;

use Illuminate\Console\Command;

class SchemaRelationshipsCommand extends Command
{
    protected $signature = 'graph:schema:relationships
                            {--count : Show count for each relationship type}';

    protected $aliases = ['neo4j:schema:relationships'];

    protected $description = 'List all Neo4j relationship types';

    public function handle(): int
    {
        $types = \Look\EloquentCypher\Facades\GraphSchema::getAllRelationshipTypes();

        $this->info('Neo4j Relationship Types');
        $this->newLine();

        if (empty($types)) {
            $this->line('No relationship types found');

            return self::SUCCESS;
        }

        if ($this->option('count')) {
            $this->displayTypesWithCount($types);
        } else {
            $this->displayTypes($types);
        }

        return self::SUCCESS;
    }

    protected function displayTypes(array $types): void
    {
        foreach ($types as $type) {
            $this->line("  - {$type}");
        }
    }

    protected function displayTypesWithCount(array $types): void
    {
        $connection = app('db')->connection('graph');
        $data = [];

        foreach ($types as $type) {
            try {
                $result = $connection->select("MATCH ()-[r:`{$type}`]->() RETURN count(r) as count");
                $count = is_array($result[0]) ? ($result[0]['count'] ?? 0) : ($result[0]->count ?? 0);
                $data[] = [$type, $count];
            } catch (\Exception $e) {
                $data[] = [$type, 'Error'];
            }
        }

        $this->table(['Relationship Type', 'Count'], $data);
    }
}
