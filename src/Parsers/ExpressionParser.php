<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Parsers;

/**
 * Parser for prefixing columns in raw expressions with node aliases.
 * Handles complex expressions with functions, keywords, and aliases.
 */
class ExpressionParser
{
    /**
     * Common Cypher functions that should not be auto-prefixed.
     */
    protected array $cypherFunctions = [
        'upper', 'lower', 'trim', 'ltrim', 'rtrim', 'split', 'replace', 'substring',
        'left', 'right', 'size', 'length', 'reverse', 'toString', 'toInteger',
        'toFloat', 'toBoolean', 'keys', 'labels', 'type', 'id', 'coalesce',
        'head', 'last', 'tail', 'range', 'collect', 'count', 'sum', 'avg',
        'min', 'max', 'percentileDisc', 'percentileCont', 'stDev', 'stDevP',
    ];

    /**
     * Keywords and reserved words that should not be prefixed.
     */
    protected array $cypherKeywords = [
        'AS', 'as', 'AND', 'and', 'OR', 'or', 'NOT', 'not', 'IN', 'in',
        'IS', 'is', 'NULL', 'null', 'TRUE', 'true', 'FALSE', 'false',
        'DESC', 'desc', 'ASC', 'asc', 'DISTINCT', 'distinct',
        'COUNT', 'count', 'SUM', 'sum', 'AVG', 'avg', 'MAX', 'max', 'MIN', 'min',
        'CASE', 'case', 'WHEN', 'when', 'THEN', 'then', 'ELSE', 'else', 'END', 'end',
        'EXISTS', 'exists', 'WITH', 'with',
    ];

    /**
     * Parse and prefix column references in an expression.
     */
    public function prefixColumns(string $expression, string $alias = 'n'): string
    {
        // If the expression already starts with the alias, don't process it
        if (strpos(trim($expression), $alias.'.') === 0) {
            return $expression;
        }

        // Check if expression starts with a function - if so, return as-is
        if ($this->startsWithFunction($expression)) {
            return $expression;
        }

        // Extract and replace function calls to avoid prefixing them
        [$expression, $functionReplacements] = $this->extractFunctions($expression);

        // Extract and replace aliases (words after 'as') to avoid prefixing them
        [$expression, $aliases, $aliasPlaceholders] = $this->extractAliases($expression);

        // Process the expression to add prefixes to column references
        $expression = $this->processCypherKeywords($expression, $alias, $aliasPlaceholders);

        // Restore aliases and functions
        $expression = $this->restoreAliases($expression, $aliases, $aliasPlaceholders);
        $expression = $this->restoreFunctions($expression, $functionReplacements);

        return $expression;
    }

    /**
     * Check if an expression starts with a Cypher function.
     */
    protected function startsWithFunction(string $expression): bool
    {
        $trimmedExpr = trim($expression);
        foreach ($this->cypherFunctions as $func) {
            if (stripos($trimmedExpr, $func.'(') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract function calls from the expression and replace with placeholders.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected function extractFunctions(string $expression): array
    {
        $functionPattern = '/\b('.implode('|', $this->cypherFunctions).')\s*\(/i';
        $functionReplacements = [];

        $expression = preg_replace_callback($functionPattern, function ($matches) use (&$functionReplacements) {
            $placeholder = '__FUNC_'.count($functionReplacements).'__';
            $functionReplacements[$placeholder] = $matches[0];

            return $placeholder;
        }, $expression);

        return [$expression, $functionReplacements];
    }

    /**
     * Extract aliases from the expression and replace with placeholders.
     *
     * @return array{0: string, 1: array<string>, 2: array<string>}
     */
    protected function extractAliases(string $expression): array
    {
        $aliasPattern = '/\bas\s+(\w+)/i';
        $aliases = [];
        $aliasPlaceholders = [];

        preg_match_all($aliasPattern, $expression, $aliasMatches, PREG_OFFSET_CAPTURE);
        foreach ($aliasMatches[1] as $i => $match) {
            $aliasName = $match[0];
            $placeholder = "__ALIAS_{$i}__";
            $aliases[] = $aliasName;
            $aliasPlaceholders[] = $placeholder;
        }

        // Replace aliases with placeholders
        $aliasIndex = 0;
        $expression = preg_replace_callback($aliasPattern, function ($match) use (&$aliasIndex) {
            return ' as __ALIAS_'.($aliasIndex++).'__';
        }, $expression);

        return [$expression, $aliases, $aliasPlaceholders];
    }

    /**
     * Process the expression to add prefixes to column references.
     *
     * @param  array<string>  $aliasPlaceholders
     */
    protected function processCypherKeywords(string $expression, string $alias, array $aliasPlaceholders): string
    {
        // Pattern to match potential column names
        // Matches word boundaries that are not preceded by a dot (already prefixed)
        // and not followed by a parenthesis (function names)
        $pattern = '/(?<![.\w])(\w+)(?!\s*\()/';

        return preg_replace_callback($pattern, function ($matches) use ($alias, $aliasPlaceholders) {
            $word = $matches[1];

            // Skip if it's a placeholder
            if (in_array($word, $aliasPlaceholders) || strpos($word, '__ALIAS_') === 0) {
                return $word;
            }

            // Skip if it's a keyword or a number
            if ($this->isKeyword($word) || is_numeric($word)) {
                return $word;
            }

            // Assume it's a column name if it matches common column patterns
            if (preg_match('/^[a-z_][a-z0-9_]*$/i', $word)) {
                return "$alias.$word";
            }

            return $word;
        }, $expression);
    }

    /**
     * Check if a word is a Cypher keyword.
     */
    protected function isKeyword(string $word): bool
    {
        return in_array($word, $this->cypherKeywords) ||
               in_array(strtolower($word), $this->cypherKeywords);
    }

    /**
     * Restore aliases that were replaced with placeholders.
     *
     * @param  array<string>  $aliases
     * @param  array<string>  $aliasPlaceholders
     */
    protected function restoreAliases(string $expression, array $aliases, array $aliasPlaceholders): string
    {
        foreach ($aliasPlaceholders as $i => $placeholder) {
            $expression = str_replace($placeholder, $aliases[$i], $expression);
        }

        return $expression;
    }

    /**
     * Restore function calls that were replaced with placeholders.
     *
     * @param  array<string, string>  $functionReplacements
     */
    protected function restoreFunctions(string $expression, array $functionReplacements): string
    {
        foreach ($functionReplacements as $placeholder => $function) {
            $expression = str_replace($placeholder, $function, $expression);
        }

        return $expression;
    }
}
