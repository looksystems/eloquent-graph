<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Drivers\Neo4j;

use Laudis\Neo4j\Contracts\ClientInterface;
use Look\EloquentCypher\Contracts\CapabilitiesInterface;

class Neo4jCapabilities implements CapabilitiesInterface
{
    protected ClientInterface $client;

    protected array $config;

    protected ?bool $hasApoc = null;

    protected ?string $version = null;

    public function __construct(ClientInterface $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    public function supportsJsonOperations(): bool
    {
        // Check if APOC is available (config override or auto-detect)
        if (isset($this->config['features']['apoc'])) {
            return $this->config['features']['apoc'];
        }

        return $this->detectApoc();
    }

    public function supportsSchemaIntrospection(): bool
    {
        return true; // Neo4j 4.0+ supports SHOW CONSTRAINTS/INDEXES
    }

    public function supportsTransactions(): bool
    {
        return true;
    }

    public function supportsBatchExecution(): bool
    {
        return true;
    }

    public function getVersion(): string
    {
        if ($this->version === null) {
            $this->version = $this->detectVersion();
        }

        return $this->version;
    }

    public function getDatabaseType(): string
    {
        return 'neo4j';
    }

    protected function detectApoc(): bool
    {
        if ($this->hasApoc !== null) {
            return $this->hasApoc;
        }

        try {
            $result = $this->client->run('RETURN apoc.version() as version');
            $this->hasApoc = $result->count() > 0;
        } catch (\Exception $e) {
            $this->hasApoc = false;
        }

        return $this->hasApoc;
    }

    protected function detectVersion(): string
    {
        try {
            $result = $this->client->run('CALL dbms.components() YIELD versions RETURN versions[0] as version');

            return $result->first()['version'] ?? 'unknown';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}
