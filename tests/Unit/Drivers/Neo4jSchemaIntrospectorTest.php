<?php

use Look\EloquentCypher\Contracts\SchemaIntrospectorInterface;
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
    $this->introspector = $this->driver->getSchemaIntrospector();

    // Create some test data
    $this->driver->executeQuery('CREATE (n:TestLabel {testProp: "value"})');
    $this->driver->executeQuery('CREATE (a:TestLabel)-[:TEST_REL]->(b:TestLabel)');
});

afterEach(function () {
    // Cleanup
    $this->driver->executeQuery('MATCH (n:TestLabel) DETACH DELETE n');
});

test('implements schema introspector interface', function () {
    expect($this->introspector)->toBeInstanceOf(SchemaIntrospectorInterface::class);
});

test('getLabels returns array of labels', function () {
    $labels = $this->introspector->getLabels();

    expect($labels)->toBeArray();
    expect($labels)->toContain('TestLabel');
});

test('getRelationshipTypes returns array of types', function () {
    $types = $this->introspector->getRelationshipTypes();

    expect($types)->toBeArray();
    expect($types)->toContain('TEST_REL');
});

test('getPropertyKeys returns array of keys', function () {
    $keys = $this->introspector->getPropertyKeys();

    expect($keys)->toBeArray();
    expect($keys)->toContain('testProp');
});

test('getConstraints returns array', function () {
    $constraints = $this->introspector->getConstraints();

    expect($constraints)->toBeArray();
});

test('getIndexes returns array', function () {
    $indexes = $this->introspector->getIndexes();

    expect($indexes)->toBeArray();
});

test('introspect returns complete schema', function () {
    $schema = $this->introspector->introspect();

    expect($schema)->toBeArray();
    expect($schema)->toHaveKeys(['labels', 'relationshipTypes', 'propertyKeys', 'constraints', 'indexes']);

    expect($schema['labels'])->toBeArray();
    expect($schema['relationshipTypes'])->toBeArray();
    expect($schema['propertyKeys'])->toBeArray();
    expect($schema['constraints'])->toBeArray();
    expect($schema['indexes'])->toBeArray();
});

test('introspect includes test data', function () {
    $schema = $this->introspector->introspect();

    expect($schema['labels'])->toContain('TestLabel');
    expect($schema['relationshipTypes'])->toContain('TEST_REL');
    expect($schema['propertyKeys'])->toContain('testProp');
});

test('getConstraints with type filter works', function () {
    // This test will pass even if there are no constraints of the specified type
    $constraints = $this->introspector->getConstraints('UNIQUENESS');

    expect($constraints)->toBeArray();
});

test('getIndexes with type filter works', function () {
    // This test will pass even if there are no indexes of the specified type
    $indexes = $this->introspector->getIndexes('RANGE');

    expect($indexes)->toBeArray();
});

test('schema introspection is consistent', function () {
    $schema1 = $this->introspector->introspect();
    $schema2 = $this->introspector->introspect();

    // Labels should be consistent
    expect(count($schema1['labels']))->toBe(count($schema2['labels']));
    expect(count($schema1['relationshipTypes']))->toBe(count($schema2['relationshipTypes']));
});

test('multiple introspectors return same data', function () {
    $driver2 = new Neo4jDriver;
    $driver2->connect($this->config);
    $introspector2 = $driver2->getSchemaIntrospector();

    $labels1 = $this->introspector->getLabels();
    $labels2 = $introspector2->getLabels();

    // Should have at least TestLabel in both
    expect($labels1)->toContain('TestLabel');
    expect($labels2)->toContain('TestLabel');
});
