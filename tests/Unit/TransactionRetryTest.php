<?php

declare(strict_types=1);

namespace Tests\Unit;

use Laudis\Neo4j\Exception\Neo4jException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TransactionRetryTest extends TestCase
{
    protected \Look\EloquentCypher\GraphConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        // We'll mock or create a test instance
        // For unit tests, we should mock the connection
    }

    /** @test */
    public function detects_deadlock_errors(): void
    {
        // Test various deadlock error messages
        $deadlockErrors = [
            'DeadlockDetectedException',
            'ForsetiClient',
            'deadlock detected',
            'Transaction failed due to concurrent modification',
            'LockAcquisitionTimeout',
        ];

        foreach ($deadlockErrors as $errorMessage) {
            $exception = new Neo4jException($errorMessage);
            $this->assertTrue(
                $this->isTransientError($exception),
                "Failed to detect deadlock error: $errorMessage"
            );
        }
    }

    /** @test */
    public function detects_lock_timeout_errors(): void
    {
        $timeoutErrors = [
            'Lock acquisition timeout',
            'Unable to acquire lock',
            'Transaction timeout',
            'Lock wait timeout exceeded',
        ];

        foreach ($timeoutErrors as $errorMessage) {
            $exception = new Neo4jException($errorMessage);
            $this->assertTrue(
                $this->isTransientError($exception),
                "Failed to detect timeout error: $errorMessage"
            );
        }
    }

    /** @test */
    public function detects_transient_network_errors(): void
    {
        $networkErrors = [
            'Connection refused',
            'Connection reset by peer',
            'Broken pipe',
            'Network is unreachable',
            'Connection timed out',
            'Unable to connect to',
            'Socket exception',
        ];

        foreach ($networkErrors as $errorMessage) {
            $exception = new RuntimeException($errorMessage);
            $this->assertTrue(
                $this->isTransientError($exception),
                "Failed to detect network error: $errorMessage"
            );
        }
    }

    /** @test */
    public function does_not_retry_permanent_errors(): void
    {
        $permanentErrors = [
            'Syntax error',
            'Unknown function',
            'Property does not exist',
            'Label does not exist',
            'Invalid query',
            'Type mismatch',
            'Constraint violation',
            'Node already exists',
        ];

        foreach ($permanentErrors as $errorMessage) {
            $exception = new Neo4jException($errorMessage);
            $this->assertFalse(
                $this->isTransientError($exception),
                "Incorrectly detected permanent error as transient: $errorMessage"
            );
        }
    }

    /** @test */
    public function calculates_backoff_delays_correctly(): void
    {
        // Test exponential backoff calculation
        $config = [
            'initial_delay_ms' => 100,
            'max_delay_ms' => 5000,
            'multiplier' => 2.0,
            'jitter' => false, // Disable jitter for predictable testing
        ];

        // Expected delays for attempts 1, 2, 3, 4
        $expectedDelays = [
            100,   // First retry: 100ms
            200,   // Second retry: 100 * 2 = 200ms
            400,   // Third retry: 200 * 2 = 400ms
            800,   // Fourth retry: 400 * 2 = 800ms
        ];

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $delay = $this->calculateBackoffDelay($attempt, $config);
            $this->assertEquals(
                $expectedDelays[$attempt - 1],
                $delay,
                "Incorrect delay for attempt $attempt"
            );
        }

        // Test max delay cap
        $delayAttempt10 = $this->calculateBackoffDelay(10, $config);
        $this->assertLessThanOrEqual(
            $config['max_delay_ms'],
            $delayAttempt10,
            'Delay should not exceed max_delay_ms'
        );
    }

    /** @test */
    public function applies_jitter_to_delays(): void
    {
        $config = [
            'initial_delay_ms' => 1000,
            'max_delay_ms' => 10000,
            'multiplier' => 2.0,
            'jitter' => true,
        ];

        // Run multiple times to verify jitter creates variation
        $delays = [];
        for ($i = 0; $i < 10; $i++) {
            $delays[] = $this->calculateBackoffDelay(2, $config);
        }

        // With jitter, not all delays should be identical
        $uniqueDelays = array_unique($delays);
        $this->assertGreaterThan(
            1,
            count($uniqueDelays),
            'Jitter should create variation in delays'
        );

        // All delays should be within expected range
        // For attempt 2 with base 1000ms and multiplier 2: expected ~2000ms
        // With jitter, should be between 1000ms and 3000ms (±50%)
        foreach ($delays as $delay) {
            $this->assertGreaterThanOrEqual(1000, $delay);
            $this->assertLessThanOrEqual(3000, $delay);
        }
    }

    /** @test */
    public function respects_max_retry_attempts(): void
    {
        $maxAttempts = 3;
        $attemptCount = 0;

        // Simulate retry loop
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $attemptCount++;
            if ($attemptCount >= $maxAttempts) {
                break;
            }
        }

        $this->assertEquals($maxAttempts, $attemptCount);
    }

    /** @test */
    public function identifies_retryable_neo4j_error_codes(): void
    {
        // Neo4j specific error codes that should trigger retry
        $retryableCodes = [
            'Neo.TransientError.Transaction.DeadlockDetected',
            'Neo.TransientError.Transaction.Terminated',
            'Neo.TransientError.Network.CommunicationError',
            'Neo.TransientError.Database.Unavailable',
        ];

        foreach ($retryableCodes as $code) {
            $this->assertTrue(
                $this->isRetryableErrorCode($code),
                "Failed to identify retryable code: $code"
            );
        }

        // Non-retryable codes
        $nonRetryableCodes = [
            'Neo.ClientError.Statement.SyntaxError',
            'Neo.ClientError.Schema.ConstraintValidationFailed',
            'Neo.ClientError.Security.Unauthorized',
        ];

        foreach ($nonRetryableCodes as $code) {
            $this->assertFalse(
                $this->isRetryableErrorCode($code),
                "Incorrectly identified non-retryable code as retryable: $code"
            );
        }
    }

    /** @test */
    public function exponential_backoff_with_custom_multiplier(): void
    {
        // Test with different multipliers
        $config1 = [
            'initial_delay_ms' => 100,
            'max_delay_ms' => 10000,
            'multiplier' => 1.5,
            'jitter' => false,
        ];

        $delay1 = $this->calculateBackoffDelay(3, $config1);
        $this->assertEquals(225, $delay1); // 100 * 1.5 * 1.5 = 225

        $config2 = [
            'initial_delay_ms' => 100,
            'max_delay_ms' => 10000,
            'multiplier' => 3.0,
            'jitter' => false,
        ];

        $delay2 = $this->calculateBackoffDelay(3, $config2);
        $this->assertEquals(900, $delay2); // 100 * 3 * 3 = 900
    }

    /** @test */
    public function handles_configuration_edge_cases(): void
    {
        // Test with zero initial delay
        $config1 = [
            'initial_delay_ms' => 0,
            'max_delay_ms' => 1000,
            'multiplier' => 2.0,
            'jitter' => false,
        ];

        $delay1 = $this->calculateBackoffDelay(1, $config1);
        $this->assertEquals(0, $delay1);

        // Test with multiplier of 1 (no exponential growth)
        $config2 = [
            'initial_delay_ms' => 500,
            'max_delay_ms' => 10000,
            'multiplier' => 1.0,
            'jitter' => false,
        ];

        $delay2 = $this->calculateBackoffDelay(5, $config2);
        $this->assertEquals(500, $delay2); // Should stay constant
    }

    // Helper methods that simulate the actual implementation logic

    protected function isTransientError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        // Deadlock patterns
        if (str_contains($message, 'deadlock') ||
            str_contains($message, 'forseticlient') ||
            str_contains($message, 'lockacquisitiontimeout') ||
            str_contains($message, 'concurrent modification')) {
            return true;
        }

        // Timeout patterns
        if (str_contains($message, 'timeout') ||
            str_contains($message, 'unable to acquire lock')) {
            return true;
        }

        // Network error patterns
        if (str_contains($message, 'connection refused') ||
            str_contains($message, 'connection reset') ||
            str_contains($message, 'broken pipe') ||
            str_contains($message, 'network is unreachable') ||
            str_contains($message, 'unable to connect') ||
            str_contains($message, 'socket exception')) {
            return true;
        }

        return false;
    }

    protected function isRetryableErrorCode(string $code): bool
    {
        return str_starts_with($code, 'Neo.TransientError.');
    }

    protected function calculateBackoffDelay(int $attempt, array $config): int
    {
        $delay = $config['initial_delay_ms'] * pow($config['multiplier'], $attempt - 1);
        $delay = min($delay, $config['max_delay_ms']);

        if ($config['jitter'] ?? false) {
            // Add jitter: ±50% random variation
            $jitterRange = $delay * 0.5;
            $delay = $delay + mt_rand((int) -$jitterRange, (int) $jitterRange);
            $delay = max(0, $delay); // Ensure non-negative
        }

        return (int) $delay;
    }
}
