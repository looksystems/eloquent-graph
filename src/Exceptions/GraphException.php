<?php

namespace Look\EloquentCypher\Exceptions;

use Exception;

/**
 * Base exception class for all Neo4j related exceptions
 */
class GraphException extends Exception
{
    /**
     * The Cypher query that caused the exception
     */
    protected ?string $cypher = null;

    /**
     * The query parameters that were used
     */
    protected array $parameters = [];

    /**
     * SQL to Cypher migration hints
     */
    protected ?string $migrationHint = null;

    /**
     * Set the Cypher query that caused the exception
     *
     * @return $this
     */
    public function setCypher(string $cypher): self
    {
        $this->cypher = $cypher;

        return $this;
    }

    /**
     * Get the Cypher query that caused the exception
     */
    public function getCypher(): ?string
    {
        return $this->cypher;
    }

    /**
     * Set the query parameters
     *
     * @return $this
     */
    public function setParameters(array $parameters): self
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Get the query parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Set a migration hint for SQL users
     *
     * @return $this
     */
    public function setMigrationHint(string $hint): self
    {
        $this->migrationHint = $hint;

        return $this;
    }

    /**
     * Get the migration hint
     */
    public function getMigrationHint(): ?string
    {
        return $this->migrationHint;
    }

    /**
     * Get a detailed error message including query information
     */
    public function getDetailedMessage(): string
    {
        $message = $this->getMessage();

        if ($this->migrationHint) {
            $message .= "\n\n💡 Migration Hint: ".$this->migrationHint;
        }

        if ($this->cypher) {
            $message .= "\n\n📝 Cypher Query:\n".$this->cypher;
        }

        if (! empty($this->parameters)) {
            $message .= "\n\n🔍 Parameters:\n".json_encode($this->parameters, JSON_PRETTY_PRINT);
        }

        return $message;
    }
}
