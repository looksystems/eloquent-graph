<?php

namespace Look\EloquentCypher\Commands\Schema;

use Illuminate\Console\Command;

class SchemaIndexesCommand extends Command
{
    protected $signature = 'graph:schema:indexes
                            {--type= : Filter by index type}';

    protected $aliases = ['neo4j:schema:indexes'];

    protected $description = 'List all Neo4j indexes';

    public function handle(): int
    {
        $indexes = \Look\EloquentCypher\Facades\GraphSchema::getIndexes();

        $this->info('Neo4j Indexes');
        $this->newLine();

        // Filter out system indexes
        $userIndexes = array_filter($indexes, fn ($idx) => ! str_starts_with($idx['name'] ?? '', '__'));

        // Filter by type if specified
        if ($typeFilter = $this->option('type')) {
            $userIndexes = array_filter($userIndexes, function ($index) use ($typeFilter) {
                return strcasecmp($index['type'] ?? '', $typeFilter) === 0;
            });
        }

        $this->displayIndexes($userIndexes);

        return self::SUCCESS;
    }

    protected function displayIndexes(array $indexes): void
    {
        $headers = ['Name', 'Type', 'Entity Type', 'Labels/Types', 'Properties', 'State'];

        if (empty($indexes)) {
            // Manually output headers for better test visibility
            $this->line(implode(' | ', $headers));
            $this->line('No user indexes found');

            return;
        }

        $data = [];

        foreach ($indexes as $index) {
            $name = $index['name'] ?? 'unnamed';
            $type = $index['type'] ?? 'unknown';
            $entityType = $index['entityType'] ?? 'unknown';
            $labelsOrTypes = implode(', ', $index['labelsOrTypes'] ?? []);
            $properties = implode(', ', $index['properties'] ?? []);
            $state = $index['state'] ?? 'unknown';

            $data[] = [
                $name,
                $type,
                $entityType,
                $labelsOrTypes,
                $properties,
                $state,
            ];
        }

        $this->table($headers, $data);
    }
}
