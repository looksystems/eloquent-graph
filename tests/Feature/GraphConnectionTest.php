<?php

it('can connect to neo4j', function () {
    $result = $this->neo4jClient->run('RETURN "Hello, Neo4j!" as message');
    expect($result->first()->get('message'))->toBe('Hello, Neo4j!');
});

it('clears database between tests', function () {
    // In parallel mode, use namespaced labels
    $label = $this->isParallelExecution()
        ? '`'.$this->getTestNamespace().'TestNode`'
        : 'TestNode';

    // Create a node
    $this->neo4jClient->run("CREATE (n:{$label} {name: 'Test'})");

    // Verify it exists
    $result = $this->neo4jClient->run("MATCH (n:{$label}) RETURN count(n) as count");
    expect($result->first()->get('count'))->toBe(1);
});

it('verifies database was cleared from previous test', function () {
    // In parallel mode, use namespaced labels
    $label = $this->isParallelExecution()
        ? '`'.$this->getTestNamespace().'TestNode`'
        : 'TestNode';

    // Check that the node from previous test doesn't exist
    $result = $this->neo4jClient->run("MATCH (n:{$label}) RETURN count(n) as count");
    expect($result->first()->get('count'))->toBe(0);
});
