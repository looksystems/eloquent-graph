<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Contracts;

interface ResultSetInterface
{
    /**
     * Convert results to array
     */
    public function toArray(): array;

    /**
     * Get row count
     */
    public function count(): int;

    /**
     * Get first result
     */
    public function first();

    /**
     * Get summary statistics
     */
    public function getSummary(): SummaryInterface;

    /**
     * Check if result set is empty
     */
    public function isEmpty(): bool;
}
