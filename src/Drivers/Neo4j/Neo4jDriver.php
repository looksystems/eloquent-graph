<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Drivers\Neo4j;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Look\EloquentCypher\Contracts\CapabilitiesInterface;
use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Contracts\ResultSetInterface;
use Look\EloquentCypher\Contracts\SchemaIntrospectorInterface;
use Look\EloquentCypher\Contracts\TransactionInterface;

class Neo4jDriver implements GraphDriverInterface
{
    protected ClientInterface $client;

    protected Neo4jCapabilities $capabilities;

    protected Neo4jSchemaIntrospector $schemaIntrospector;

    protected array $config;

    public function connect(array $config): void
    {
        $this->config = $config;

        $auth = Authenticate::basic(
            $config['username'],
            $config['password']
        );

        $protocol = $config['protocol'] ?? 'bolt';
        $uri = "{$protocol}://{$config['host']}:{$config['port']}";

        $this->client = ClientBuilder::create()
            ->withDriver($protocol, $uri, $auth)
            ->withDefaultDriver($protocol)
            ->build();

        $this->capabilities = new Neo4jCapabilities($this->client, $config);
        $this->schemaIntrospector = new Neo4jSchemaIntrospector($this->client);
    }

    public function disconnect(): void
    {
        // Laudis client handles connection pooling automatically
        unset($this->client);
    }

    public function executeQuery(string $cypher, array $parameters = []): ResultSetInterface
    {
        $result = $this->client->run($cypher, $parameters);

        return new Neo4jResultSet($result);
    }

    public function executeBatch(array $queries): array
    {
        $results = [];
        foreach ($queries as $query) {
            $results[] = $this->executeQuery($query['cypher'], $query['parameters'] ?? []);
        }

        return $results;
    }

    public function beginTransaction(): TransactionInterface
    {
        $transaction = $this->client->beginTransaction();

        return new Neo4jTransaction($transaction);
    }

    public function commit(TransactionInterface $transaction): void
    {
        $transaction->commit();
    }

    public function rollback(TransactionInterface $transaction): void
    {
        $transaction->rollback();
    }

    public function ping(): bool
    {
        try {
            $result = $this->executeQuery('RETURN 1 as ping');

            return $result->count() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCapabilities(): CapabilitiesInterface
    {
        return $this->capabilities;
    }

    public function getSchemaIntrospector(): SchemaIntrospectorInterface
    {
        return $this->schemaIntrospector;
    }
}
