<?php

use Look\EloquentCypher\Contracts\CapabilitiesInterface;
use Look\EloquentCypher\Drivers\Neo4j\Neo4jDriver;

beforeEach(function () {
    $this->config = [
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
        'protocol' => 'bolt',
    ];

    $this->driver = new Neo4jDriver;
    $this->driver->connect($this->config);
    $this->capabilities = $this->driver->getCapabilities();
});

test('implements capabilities interface', function () {
    expect($this->capabilities)->toBeInstanceOf(CapabilitiesInterface::class);
});

test('supportsTransactions returns true', function () {
    expect($this->capabilities->supportsTransactions())->toBeTrue();
});

test('supportsSchemaIntrospection returns true', function () {
    expect($this->capabilities->supportsSchemaIntrospection())->toBeTrue();
});

test('getDatabaseType returns neo4j', function () {
    expect($this->capabilities->getDatabaseType())->toBe('neo4j');
});

test('getVersion returns string', function () {
    $version = $this->capabilities->getVersion();

    expect($version)->toBeString();
    expect(strlen($version))->toBeGreaterThan(0);
});

test('version follows semantic versioning pattern', function () {
    $version = $this->capabilities->getVersion();

    // Should match pattern like "5.12.0" or "4.4.0"
    expect($version)->toMatch('/^\d+\.\d+/');
});

test('supportsJsonOperations detection works', function () {
    $result = $this->capabilities->supportsJsonOperations();

    // Should be boolean
    expect($result)->toBeBool();

    // If APOC is installed, should be true
    // If not installed, should be false
    // We can't assert the value without knowing the test environment
});

test('capabilities are consistent across calls', function () {
    $type1 = $this->capabilities->getDatabaseType();
    $type2 = $this->capabilities->getDatabaseType();

    expect($type1)->toBe($type2);

    $version1 = $this->capabilities->getVersion();
    $version2 = $this->capabilities->getVersion();

    expect($version1)->toBe($version2);
});

test('multiple capability objects return same values', function () {
    $driver2 = new Neo4jDriver;
    $driver2->connect($this->config);
    $capabilities2 = $driver2->getCapabilities();

    expect($this->capabilities->getDatabaseType())->toBe($capabilities2->getDatabaseType());
    expect($this->capabilities->getVersion())->toBe($capabilities2->getVersion());
    expect($this->capabilities->supportsTransactions())->toBe($capabilities2->supportsTransactions());
});
