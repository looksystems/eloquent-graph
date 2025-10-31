<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Exceptions;

class GraphNetworkException extends \Look\EloquentCypher\Exceptions\GraphConnectionException
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

        $this->setMigrationHint('Network error detected. This could be a temporary network issue. '.
                               'The connection will attempt to reconnect automatically. '.
                               'If this persists, check your network connectivity and Neo4j server status.');
    }

    public function isRetryable(): bool
    {
        return true;
    }

    public function shouldReconnect(): bool
    {
        return true;
    }

    public function getQuery(): string
    {
        return $this->getCypher() ?? '';
    }

    public function getHint(): string
    {
        return $this->getMigrationHint() ?? '';
    }

    public function getConnectionName(): string
    {
        return 'neo4j';
    }
}
