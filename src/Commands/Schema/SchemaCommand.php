<?php

namespace Look\EloquentCypher\Commands\Schema;

use Illuminate\Console\Command;

class SchemaCommand extends Command
{
    protected $signature = 'graph:schema
                            {--json : Output as JSON}
                            {--compact : Minimal output}';

    protected $aliases = ['neo4j:schema'];

    protected $description = 'Display complete Neo4j schema overview';

    public function handle(): int
    {
        $schema = \Look\EloquentCypher\Facades\GraphSchema::introspect();

        if ($this->option('json')) {
            $this->line(json_encode($schema, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->displaySchema($schema);

        return self::SUCCESS;
    }

    protected function displaySchema(array $schema): void
    {
        $this->info('Neo4j Schema Overview');
        $this->newLine();

        // Labels
        $this->line('<comment>Labels:</comment>');
        if (empty($schema['labels'])) {
            $this->line('  No labels found');
        } else {
            foreach ($schema['labels'] as $label) {
                $this->line("  - {$label}");
            }
        }
        $this->newLine();

        // Relationship Types
        $this->line('<comment>Relationship Types:</comment>');
        if (empty($schema['relationshipTypes'])) {
            $this->line('  No relationship types found');
        } else {
            foreach ($schema['relationshipTypes'] as $type) {
                $this->line("  - {$type}");
            }
        }
        $this->newLine();

        // Property Keys
        if (! $this->option('compact')) {
            $this->line('<comment>Property Keys:</comment>');
            if (empty($schema['propertyKeys'])) {
                $this->line('  No property keys found');
            } else {
                $propertyList = implode(', ', $schema['propertyKeys']);
                $this->line("  {$propertyList}");
            }
            $this->newLine();
        }

        // Constraints
        $this->line('<comment>Constraints:</comment>');
        if (empty($schema['constraints'])) {
            $this->line('  No constraints found');
        } else {
            foreach ($schema['constraints'] as $constraint) {
                $name = $constraint['name'] ?? 'unnamed';
                $type = $constraint['type'] ?? 'unknown';
                $labels = implode(', ', $constraint['labelsOrTypes'] ?? []);
                $this->line("  - {$name} ({$type}) on {$labels}");
            }
        }
        $this->newLine();

        // Indexes
        $this->line('<comment>Indexes:</comment>');
        if (empty($schema['indexes'])) {
            $this->line('  No indexes found');
        } else {
            // Filter out system indexes
            $userIndexes = array_filter($schema['indexes'], fn ($idx) => ! str_starts_with($idx['name'] ?? '', '__'));
            if (empty($userIndexes)) {
                $this->line('  No user indexes found');
            } else {
                foreach ($userIndexes as $index) {
                    $name = $index['name'] ?? 'unnamed';
                    $type = $index['type'] ?? 'unknown';
                    $labels = implode(', ', $index['labelsOrTypes'] ?? []);
                    $state = $index['state'] ?? 'unknown';
                    $this->line("  - {$name} ({$type}) on {$labels} [{$state}]");
                }
            }
        }
    }
}
