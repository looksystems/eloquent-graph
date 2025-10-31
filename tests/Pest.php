<?php

use Tests\TestCase\GraphTestCase;
use Tests\TestCase\UnitTestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(GraphTestCase::class)->in('Feature');
uses(UnitTestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toExistInNeo4j', function (string $label, array $properties = []) {
    $cypher = "MATCH (n:$label";

    if (! empty($properties)) {
        $propConditions = [];
        foreach ($properties as $key => $value) {
            $propConditions[] = "n.$key = \$properties.$key";
        }
        $cypher .= ' WHERE '.implode(' AND ', $propConditions);
    }

    $cypher .= ') RETURN count(n) as count';

    $result = test()->neo4jClient->run($cypher, ['properties' => $properties]);
    $count = $result->first()->get('count');

    expect($count)->toBeGreaterThan(0);

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function clearNeo4j(): void
{
    test()->neo4jClient->run('MATCH (n) DETACH DELETE n');
}

/**
 * Check if APOC procedures are available in the current Neo4j connection.
 * This is used by tests to conditionally skip tests that require APOC.
 */
function hasApoc(): bool
{
    try {
        $connection = DB::connection('graph');

        return $connection->hasAPOC();
    } catch (\Exception $e) {
        return false;
    }
}
