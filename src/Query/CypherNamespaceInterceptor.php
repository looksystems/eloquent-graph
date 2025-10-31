<?php

namespace Look\EloquentCypher\Query;

class CypherNamespaceInterceptor
{
    protected ?string $namespace;

    public function __construct(?string $namespace = null)
    {
        $this->namespace = $namespace;
    }

    /**
     * Apply namespace to a Cypher query.
     * This intercepts and modifies label references in Cypher queries
     * to add the namespace prefix for test isolation.
     *
     * @param  string  $cypher  The original Cypher query
     * @return string The namespaced Cypher query
     */
    public function applyNamespace(string $cypher): string
    {
        if (! $this->namespace) {
            return $cypher;
        }

        // Pattern to match label references in Cypher
        // Matches patterns like:
        // - (n:Label)
        // - (n:Label {
        // - (:Label)
        // - :Label WHERE
        // - [:RELATIONSHIP]  (we need to avoid these)
        $pattern = '/(?<=[\(\s])(:)([A-Za-z_][A-Za-z0-9_]*)/';

        // Replace label references with namespaced versions
        // But skip relationship types (those in square brackets)
        $namespaced = preg_replace_callback($pattern, function ($matches) {
            $label = $matches[2];

            // Skip if this is inside square brackets (relationship)
            // We'll need to check context
            return ':'.$this->namespace.$label;
        }, $cypher);

        // More sophisticated handling for avoiding relationship types
        // Split by square brackets to handle relationships separately
        $parts = preg_split('/(\[:[^\]]+\])/', $namespaced, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Process non-relationship parts
        $result = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                // This is not a relationship part, already processed
                $result .= $part;
            } else {
                // This is a relationship part, restore original
                // Extract the relationship from original query
                preg_match('/\[:([^\]]+)\]/', $part, $relMatches);
                if ($relMatches) {
                    // Remove namespace from relationship if it was added
                    $relType = str_replace($this->namespace, '', $relMatches[1]);
                    $result .= '[:'.$relType.']';
                } else {
                    $result .= $part;
                }
            }
        }

        return $result;
    }

    /**
     * Apply namespace to query parameters if they contain label references.
     * This is for cases where labels might be passed as parameters.
     *
     * @param  array  $parameters  The query parameters
     * @return array The namespaced parameters
     */
    public function applyNamespaceToParameters(array $parameters): array
    {
        if (! $this->namespace) {
            return $parameters;
        }

        // For now, we don't modify parameters as labels are typically
        // hardcoded in queries, not passed as parameters
        // This could be extended if needed
        return $parameters;
    }

    /**
     * Check if a string looks like a label (for validation).
     *
     * @param  string  $str  The string to check
     * @return bool True if it looks like a label
     */
    protected function isLabel(string $str): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $str);
    }

    /**
     * Remove namespace from results if needed (for backwards compatibility).
     *
     * @param  array  $results  The query results
     * @return array The results with namespace stripped
     */
    public function stripNamespaceFromResults(array $results): array
    {
        if (! $this->namespace) {
            return $results;
        }

        // For each result, if it has labels, strip the namespace prefix
        foreach ($results as &$row) {
            if (is_object($row) && property_exists($row, 'labels')) {
                // Handle node labels
                if (is_array($row->labels)) {
                    $row->labels = array_map(function ($label) {
                        if (str_starts_with($label, $this->namespace)) {
                            return substr($label, strlen($this->namespace));
                        }

                        return $label;
                    }, $row->labels);
                }
            } elseif (is_array($row) && isset($row['labels'])) {
                // Handle array format
                if (is_array($row['labels'])) {
                    $row['labels'] = array_map(function ($label) {
                        if (str_starts_with($label, $this->namespace)) {
                            return substr($label, strlen($this->namespace));
                        }

                        return $label;
                    }, $row['labels']);
                }
            }
        }

        return $results;
    }
}
