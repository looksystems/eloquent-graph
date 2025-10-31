<?php

namespace Look\EloquentCypher\Exceptions;

/**
 * Exception thrown when there are constraint violations in Neo4j
 */
class GraphConstraintException extends \Look\EloquentCypher\Exceptions\GraphException
{
    /**
     * Create a unique constraint violation exception
     *
     * @param  mixed  $value
     * @return static
     */
    public static function uniqueConstraintViolation(string $label, string $property, $value): self
    {
        $exception = new self(
            "Unique constraint violation: A node with label '{$label}' and {$property}='{$value}' already exists"
        );

        $exception->setMigrationHint(
            "This is similar to SQL's unique constraint violation. Options:\n".
            "• Use MERGE instead of CREATE to update existing nodes\n".
            "• Check if the node exists before creating: MATCH (n:{$label} {'{'}$property: \$value{'}'}) ...\n".
            '• Remove the unique constraint if duplicates are allowed'
        );

        return $exception;
    }

    /**
     * Create a constraint creation failure exception
     *
     * @return static
     */
    public static function constraintCreationFailed(string $constraint, string $reason): self
    {
        $exception = new self(
            "Failed to create constraint '{$constraint}': {$reason}"
        );

        $exception->setMigrationHint(
            "Common reasons for constraint creation failure:\n".
            "• Existing data violates the constraint - clean data first\n".
            "• Constraint already exists - drop it first\n".
            '• Invalid syntax - check Neo4j version compatibility'
        );

        return $exception;
    }

    public function getQuery(): string
    {
        return $this->getCypher() ?? '';
    }

    public function getHint(): string
    {
        return $this->getMigrationHint() ?? '';
    }
}
