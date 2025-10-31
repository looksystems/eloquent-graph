<?php

namespace Look\EloquentCypher\Commands\Schema;

use Illuminate\Console\Command;

class SchemaPropertiesCommand extends Command
{
    protected $signature = 'graph:schema:properties';

    protected $aliases = ['neo4j:schema:properties'];

    protected $description = 'List all Neo4j property keys';

    public function handle(): int
    {
        $keys = \Look\EloquentCypher\Facades\GraphSchema::getAllPropertyKeys();

        $this->info('Neo4j Property Keys');
        $this->newLine();

        if (empty($keys)) {
            $this->line('No property keys found');

            return self::SUCCESS;
        }

        // Display in columns for better readability
        $chunks = array_chunk($keys, 3);
        foreach ($chunks as $chunk) {
            $this->line('  '.implode(', ', $chunk));
        }

        $this->newLine();
        $count = count($keys);
        $this->line("Total: {$count} property keys");

        return self::SUCCESS;
    }
}
