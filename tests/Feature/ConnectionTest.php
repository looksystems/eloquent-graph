<?php

use Tests\Models\User;

// TEST FIRST: tests/Feature/ConnectionTest.php
// Focus: What users expect when using Neo4j with Laravel

test('model uses neo4j when configured', function () {
    config(['database.connections.neo4j' => [
        'driver' => 'neo4j',
        'host' => 'localhost',
        'port' => 7687,
    ]]);

    $user = new User;
    $user->setConnection('graph');

    expect($user->getConnectionName())->toBe('graph');
});
