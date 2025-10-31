<?php

declare(strict_types=1);

namespace Tests\Feature;

use Exception;
use Mockery;
use ReflectionClass;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class ErrorRecoveryTest extends GraphTestCase
{
    protected \Look\EloquentCypher\GraphConnection $connection;

    protected ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = app('db')->connection('graph');
        $this->reflection = new ReflectionClass($this->connection);
    }

    protected function callProtectedMethod(string $method, ...$args)
    {
        $method = $this->reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invoke($this->connection, ...$args);
    }

    protected function setProtectedProperty(string $property, $value)
    {
        $property = $this->reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($this->connection, $value);
    }

    protected function getProtectedProperty(string $property)
    {
        $property = $this->reflection->getProperty($property);
        $property->setAccessible(true);

        return $property->getValue($this->connection);
    }

    /** @test */
    public function automatically_reconnects_on_stale_connection()
    {
        // First ensure we have a working connection
        $result = $this->connection->select('RETURN 1 as one');
        expect($result)->toHaveCount(1);

        // Simulate a stale connection by force-closing the client
        $client = $this->getProtectedProperty('client');
        if (method_exists($client, 'close')) {
            $client->close();
        }

        // Mark connection as stale
        $this->setProtectedProperty('isStale', true);

        // Next query should trigger reconnection
        $result = $this->connection->select('RETURN 2 as two');
        expect($result)->toHaveCount(1);
        expect($result[0]->two ?? $result[0]['two'])->toBe(2);

        // Connection should no longer be stale
        $isStale = $this->getProtectedProperty('isStale');
        expect($isStale)->toBeFalse();
    }

    /** @test */
    public function retries_query_after_reconnection()
    {
        $attemptCount = 0;

        // Mock a connection that fails once then succeeds
        $mockConnection = Mockery::mock(\Look\EloquentCypher\GraphConnection::class)->makePartial();
        $mockConnection->shouldAllowMockingProtectedMethods();

        $mockConnection->shouldReceive('runStatement')
            ->twice()
            ->andReturnUsing(function () use (&$attemptCount) {
                $attemptCount++;
                if ($attemptCount === 1) {
                    throw new \Look\EloquentCypher\Exceptions\GraphNetworkException('Connection lost');
                }

                return [(object) ['result' => 'success']];
            });

        $mockConnection->shouldReceive('reconnect')->once();
        $mockConnection->shouldReceive('shouldReconnect')->once()->andReturn(true);
        $mockConnection->shouldReceive('isRetryable')->once()->andReturn(true);

        // This should retry once after network error
        $result = $mockConnection->selectWithRetry('RETURN 1', [], 2);

        expect($attemptCount)->toBe(2);
        expect($result[0]->result)->toBe('success');
    }

    /** @test */
    public function provides_helpful_error_messages()
    {
        try {
            $this->connection->statement('CREATE (n:Test) SET n.id = $id, n.name = $name', [
                'id' => 1,
                'name' => 'Test',
            ]);

            // Try to create duplicate (assuming unique constraint on id)
            $this->connection->statement('CREATE (n:Test) SET n.id = $id, n.name = $name', [
                'id' => 1,
                'name' => 'Duplicate',
            ]);

            // If no constraint exists, this test should be skipped
            $this->markTestSkipped('No unique constraint on Test.id');
        } catch (Exception $e) {
            expect($e->getMessage())->toContain('already exists');

            if (method_exists($e, 'getQuery')) {
                expect($e->getQuery())->toContain('CREATE (n:Test)');
            }

            if (method_exists($e, 'getParameters')) {
                expect($e->getParameters())->toHaveKey('id');
                expect($e->getParameters())->toHaveKey('name');
            }

            if (method_exists($e, 'getHint')) {
                expect($e->getHint())->not()->toBeEmpty();
            }
        }

        // Clean up
        $this->connection->statement('MATCH (n:Test) DELETE n');
    }

    /** @test */
    public function includes_query_context_in_exceptions()
    {
        try {
            $this->connection->select('INVALID CYPHER QUERY');
            $this->fail('Should have thrown an exception');
        } catch (Exception $e) {
            expect($e->getMessage())->toContain('INVALID');

            if (method_exists($e, 'getQuery')) {
                expect($e->getQuery())->toBe('INVALID CYPHER QUERY');
            }

            if (method_exists($e, 'getConnectionName')) {
                expect($e->getConnectionName())->toBe('graph');
            }
        }
    }

    /** @test */
    public function migration_hints_are_correct()
    {
        // Test constraint violation hint
        try {
            // First create a user
            User::create(['id' => 999, 'name' => 'First', 'email' => 'unique@example.com']);

            // Try to create another with same email (if unique constraint exists)
            User::create(['id' => 998, 'name' => 'Second', 'email' => 'unique@example.com']);

            // If no constraint, skip this assertion
            $this->markTestSkipped('No unique constraint on User.email');
        } catch (Exception $e) {
            if (method_exists($e, 'getHint')) {
                $hint = $e->getHint();
                expect($hint)->toContain('constraint');
                // Should suggest how to create constraint
                expect($hint)->toMatch('/CREATE\s+CONSTRAINT/i');
            }
        }

        // Test missing APOC hint
        try {
            $this->connection->select("RETURN apoc.json.parse('{\"key\": \"value\"}')");
            // If APOC is installed, this won't fail
            $this->markTestSkipped('APOC is installed');
        } catch (Exception $e) {
            if (method_exists($e, 'getHint')) {
                $hint = $e->getHint();
                expect($hint)->toContain('APOC');
                expect($hint)->toContain('plugin');
            }
        }

        // Clean up
        User::whereIn('id', [998, 999])->delete();
    }

    /** @test */
    public function handles_transient_errors_gracefully()
    {
        // Mock a transient error that should be retried
        $mock = Mockery::mock($this->connection)->makePartial();
        $attempts = 0;

        $mock->shouldReceive('runStatement')
            ->times(3)
            ->andReturnUsing(function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new \Look\EloquentCypher\Exceptions\GraphTransientException('Deadlock detected', 0, null, 'MATCH (n) RETURN n', []);
                }

                return [(object) ['success' => true]];
            });

        $result = $mock->selectWithRetry('MATCH (n) RETURN n', [], 3);

        expect($attempts)->toBe(3);
        expect($result[0]->success)->toBeTrue();
    }

    /** @test */
    public function respects_max_retry_limit()
    {
        $mock = Mockery::mock($this->connection)->makePartial();
        $attempts = 0;

        $mock->shouldReceive('runStatement')
            ->times(3)
            ->andReturnUsing(function () use (&$attempts) {
                $attempts++;
                throw new \Look\EloquentCypher\Exceptions\GraphTransientException('Always fails');
            });

        $mock->shouldReceive('isRetryable')->andReturn(true);

        try {
            $mock->selectWithRetry('MATCH (n) RETURN n', [], 3);
            $this->fail('Should have thrown exception after max retries');
        } catch (\Look\EloquentCypher\Exceptions\GraphTransientException $e) {
            expect($attempts)->toBe(3);
            expect($e->getMessage())->toContain('Always fails');
        }
    }

    /** @test */
    public function does_not_retry_non_retryable_errors()
    {
        $mock = Mockery::mock($this->connection)->makePartial();
        $attempts = 0;

        $mock->shouldReceive('runStatement')
            ->once()
            ->andReturnUsing(function () use (&$attempts) {
                $attempts++;
                throw new \Look\EloquentCypher\Exceptions\GraphAuthenticationException('Invalid credentials');
            });

        $mock->shouldReceive('isRetryable')->andReturn(false);

        try {
            $mock->selectWithRetry('MATCH (n) RETURN n', [], 3);
            $this->fail('Should have thrown exception immediately');
        } catch (\Look\EloquentCypher\Exceptions\GraphAuthenticationException $e) {
            expect($attempts)->toBe(1);
            expect($e->getMessage())->toContain('Invalid credentials');
        }
    }

    /** @test */
    public function applies_exponential_backoff_correctly()
    {
        $delays = [];

        // Test backoff calculation
        $config = [
            'initial_delay_ms' => 100,
            'max_delay_ms' => 5000,
            'multiplier' => 2.0,
            'jitter' => false,
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $delay = $this->callProtectedMethod('calculateBackoffDelay', $attempt, $config);
            $delays[] = $delay;
        }

        // Check exponential growth
        expect($delays[0])->toBe(100);  // 100ms
        expect($delays[1])->toBe(200);  // 100 * 2
        expect($delays[2])->toBe(400);  // 100 * 2^2
        expect($delays[3])->toBe(800);  // 100 * 2^3
        expect($delays[4])->toBe(1600); // 100 * 2^4
    }

    /** @test */
    public function applies_jitter_to_prevent_thundering_herd()
    {
        $config = [
            'initial_delay_ms' => 100,
            'max_delay_ms' => 5000,
            'multiplier' => 2.0,
            'jitter' => true,
        ];

        $delays = [];
        for ($i = 0; $i < 10; $i++) {
            $delay = $this->callProtectedMethod('calculateBackoffDelay', 2, $config);
            $delays[] = $delay;
        }

        // With jitter, delays should vary
        $uniqueDelays = array_unique($delays);
        expect(count($uniqueDelays))->toBeGreaterThan(1);

        // But should be within expected range (base is 400ms for attempt 2)
        foreach ($delays as $delay) {
            expect($delay)->toBeGreaterThanOrEqual(200);  // 50% of base
            expect($delay)->toBeLessThanOrEqual(600);     // 150% of base
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
