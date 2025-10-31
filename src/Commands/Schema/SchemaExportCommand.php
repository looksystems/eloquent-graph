<?php

namespace Look\EloquentCypher\Commands\Schema;

use Illuminate\Console\Command;

class SchemaExportCommand extends Command
{
    protected $signature = 'graph:schema:export
                            {file : Output file path}
                            {--format=json : Export format (json|yaml)}';

    protected $aliases = ['neo4j:schema:export'];

    protected $description = 'Export Neo4j schema to a file';

    public function handle(): int
    {
        $file = $this->argument('file');
        $format = strtolower($this->option('format'));

        if (! in_array($format, ['json', 'yaml'])) {
            $this->error("Invalid format: {$format}. Supported formats: json, yaml");

            return self::FAILURE;
        }

        $schema = \Look\EloquentCypher\Facades\GraphSchema::introspect();

        // Ensure directory exists
        $directory = dirname($file);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Export based on format
        $content = match ($format) {
            'json' => json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'yaml' => $this->arrayToYaml($schema),
            default => null,
        };

        if ($content === null) {
            $this->error('Failed to generate export content');

            return self::FAILURE;
        }

        if (file_put_contents($file, $content) === false) {
            $this->error("Failed to write to file: {$file}");

            return self::FAILURE;
        }

        $this->info("Schema exported successfully to {$file}");

        return self::SUCCESS;
    }

    protected function arrayToYaml(array $data, int $indent = 0): string
    {
        $yaml = '';
        $spaces = str_repeat('  ', $indent);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Check if it's a sequential array
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // Sequential array (list)
                    $yaml .= "{$spaces}{$key}:\n";
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $yaml .= "{$spaces}- \n";
                            $yaml .= $this->arrayToYaml($item, $indent + 1);
                        } else {
                            $yaml .= "{$spaces}- {$item}\n";
                        }
                    }
                } else {
                    // Associative array (map)
                    $yaml .= "{$spaces}{$key}:\n";
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                }
            } else {
                $yaml .= "{$spaces}{$key}: {$value}\n";
            }
        }

        return $yaml;
    }
}
