<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Contracts;

interface SummaryInterface
{
    /**
     * Get execution time in milliseconds
     */
    public function getExecutionTime(): float;

    /**
     * Get counters (nodes created, relationships created, etc.)
     * Returns object with methods like nodesCreated(), nodesDeleted(), etc.
     */
    public function getCounters();

    /**
     * Get query plan if available
     */
    public function getPlan(): ?array;
}
