<?php

namespace Look\EloquentCypher\Commands\Schema;

use Illuminate\Console\Command;

class SchemaLabelsCommand extends Command
{
    protected $signature = 'graph:schema:labels
                            {--count : Show node count for each label}';

    protected $aliases = ['neo4j:schema:labels'];

    protected $description = 'List all Neo4j node labels';

    public function handle(): int
    {
        $labels = \Look\EloquentCypher\Facades\GraphSchema::getAllLabels();

        $this->info('Neo4j Node Labels');
        $this->newLine();

        if (empty($labels)) {
            $this->line('No labels found');

            return self::SUCCESS;
        }

        if ($this->option('count')) {
            $this->displayLabelsWithCount($labels);
        } else {
            $this->displayLabels($labels);
        }

        return self::SUCCESS;
    }

    protected function displayLabels(array $labels): void
    {
        foreach ($labels as $label) {
            $this->line("  - {$label}");
        }
    }

    protected function displayLabelsWithCount(array $labels): void
    {
        $connection = app('db')->connection('graph');
        $data = [];

        foreach ($labels as $label) {
            try {
                $result = $connection->select("MATCH (n:`{$label}`) RETURN count(n) as count");
                $count = is_array($result[0]) ? ($result[0]['count'] ?? 0) : ($result[0]->count ?? 0);
                $data[] = [$label, $count];
            } catch (\Exception $e) {
                $data[] = [$label, 'Error'];
            }
        }

        $this->table(['Label', 'Count'], $data);
    }
}
