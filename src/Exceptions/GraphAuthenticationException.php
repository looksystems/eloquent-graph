<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Exceptions;

class GraphAuthenticationException extends \Look\EloquentCypher\Exceptions\GraphConnectionException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        string $query = '',
        array $parameters = []
    ) {
        parent::__construct($message, $code, $previous);

        if ($query) {
            $this->setCypher($query);
        }
        if (! empty($parameters)) {
            $this->setParameters($parameters);
        }

        $this->setMigrationHint('Authentication failed. Please check your Neo4j credentials in the database configuration. '.
                               'Ensure the username and password are correct, and that the user has the necessary permissions.');
    }

    public function isRetryable(): bool
    {
        return false; // Auth failures should not be retried with same credentials
    }

    public function shouldReconnect(): bool
    {
        return false; // Don't attempt reconnection with bad credentials
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
