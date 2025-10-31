<?php

namespace Look\EloquentCypher\Services;

class AliasResolver
{
    /**
     * Aliases for tables/labels.
     */
    protected array $aliases = [];

    /**
     * Counter for generating unique aliases.
     */
    protected int $counter = 0;

    /**
     * Parse a table name that may contain an alias.
     * Formats: "table", "table as alias", "table AS alias"
     */
    public static function parseTableAlias(string $table): array
    {
        $table = trim($table);

        // Check for "AS" keyword (case insensitive)
        if (preg_match('/^(.+?)\s+as\s+(.+?)$/i', $table, $matches)) {
            return [
                'table' => trim($matches[1]),
                'alias' => trim($matches[2]),
            ];
        }

        // No alias found
        return [
            'table' => $table,
            'alias' => null,
        ];
    }

    /**
     * Extract table name from a string that may contain an alias.
     */
    public static function extractTableName(string $table): string
    {
        $parsed = self::parseTableAlias($table);

        return $parsed['table'];
    }

    /**
     * Extract alias from a string, or return the table name if no alias.
     */
    public static function extractAlias(string $table): string
    {
        $parsed = self::parseTableAlias($table);

        return $parsed['alias'] ?? $parsed['table'];
    }

    /**
     * Register an alias for a table.
     */
    public function registerAlias(string $table, ?string $alias = null): string
    {
        $parsed = self::parseTableAlias($table);
        $tableName = $parsed['table'];
        $providedAlias = $alias ?? $parsed['alias'];

        if ($providedAlias) {
            $this->aliases[$tableName] = $providedAlias;

            return $providedAlias;
        }

        // Generate a unique alias if not provided
        if (! isset($this->aliases[$tableName])) {
            $this->aliases[$tableName] = $this->generateUniqueAlias($tableName);
        }

        return $this->aliases[$tableName];
    }

    /**
     * Get the alias for a table.
     */
    public function getAlias(string $table): ?string
    {
        $tableName = self::extractTableName($table);

        return $this->aliases[$tableName] ?? null;
    }

    /**
     * Get the alias for a table, or the table name if no alias exists.
     */
    public function getAliasOrTable(string $table): string
    {
        return $this->getAlias($table) ?? self::extractTableName($table);
    }

    /**
     * Generate a unique alias for a table.
     */
    protected function generateUniqueAlias(string $table): string
    {
        // Use first letter of table name + counter
        $prefix = substr($table, 0, 1);

        return $prefix.++$this->counter;
    }

    /**
     * Check if a table has an alias registered.
     */
    public function hasAlias(string $table): bool
    {
        $tableName = self::extractTableName($table);

        return isset($this->aliases[$tableName]);
    }

    /**
     * Get all registered aliases.
     */
    public function getAllAliases(): array
    {
        return $this->aliases;
    }

    /**
     * Clear all aliases.
     */
    public function clear(): void
    {
        $this->aliases = [];
        $this->counter = 0;
    }

    /**
     * Parse a column reference that may include a table prefix.
     * Formats: "column", "table.column", "alias.column"
     */
    public static function parseColumnReference(string $column): array
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);

            return [
                'table' => $table,
                'column' => $col,
            ];
        }

        return [
            'table' => null,
            'column' => $column,
        ];
    }

    /**
     * Build a column reference with the appropriate alias.
     */
    public function buildColumnReference(string $column, ?string $defaultTable = null): string
    {
        $parsed = self::parseColumnReference($column);

        if ($parsed['table']) {
            // Column already has a table prefix
            $alias = $this->getAliasOrTable($parsed['table']);

            return $alias.'.'.$parsed['column'];
        }

        if ($defaultTable) {
            // Use provided default table
            $alias = $this->getAliasOrTable($defaultTable);

            return $alias.'.'.$parsed['column'];
        }

        // Return column as-is
        return $column;
    }
}
