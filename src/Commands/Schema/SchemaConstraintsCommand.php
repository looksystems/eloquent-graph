<?php

namespace Look\EloquentCypher\Commands\Schema;

use Illuminate\Console\Command;

class SchemaConstraintsCommand extends Command
{
    protected $signature = 'graph:schema:constraints
                            {--type= : Filter by constraint type}';

    protected $aliases = ['neo4j:schema:constraints'];

    protected $description = 'List all Neo4j constraints';

    public function handle(): int
    {
        $constraints = \Look\EloquentCypher\Facades\GraphSchema::getConstraints();

        $this->info('Neo4j Constraints');
        $this->newLine();

        // Filter by type if specified
        if ($typeFilter = $this->option('type')) {
            $constraints = array_filter($constraints, function ($constraint) use ($typeFilter) {
                return strcasecmp($constraint['type'] ?? '', $typeFilter) === 0;
            });
        }

        $this->displayConstraints($constraints);

        return self::SUCCESS;
    }

    protected function displayConstraints(array $constraints): void
    {
        $headers = ['Name', 'Type', 'Entity Type', 'Labels/Types', 'Properties'];

        if (empty($constraints)) {
            // Manually output headers for better test visibility
            $this->line(implode(' | ', $headers));
            $this->line('No constraints found');

            return;
        }

        $data = [];

        foreach ($constraints as $constraint) {
            $name = $constraint['name'] ?? 'unnamed';
            $type = $constraint['type'] ?? 'unknown';
            $entityType = $constraint['entityType'] ?? 'unknown';
            $labelsOrTypes = implode(', ', $constraint['labelsOrTypes'] ?? []);
            $properties = implode(', ', $constraint['properties'] ?? []);

            $data[] = [
                $name,
                $type,
                $entityType,
                $labelsOrTypes,
                $properties,
            ];
        }

        $this->table($headers, $data);
    }
}
