<?php

namespace Tests\Unit\Exceptions;

use Look\EloquentCypher\Exceptions\Neo4jException;
use PHPUnit\Framework\TestCase;

class Neo4jConstraintExceptionTest extends TestCase
{
    /**
     * Test that it extends Neo4jException
     */
    public function test_extends_neo4j_exception(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphConstraintException('Test');

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphException::class, $exception);
    }

    /**
     * Test unique constraint violation
     */
    public function test_unique_constraint_violation(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphConstraintException::uniqueConstraintViolation('User', 'email', 'john@example.com');

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphConstraintException::class, $exception);
        $this->assertStringContainsString('Unique constraint violation', $exception->getMessage());
        $this->assertStringContainsString('User', $exception->getMessage());
        $this->assertStringContainsString('email', $exception->getMessage());
        $this->assertStringContainsString('john@example.com', $exception->getMessage());

        // Check migration hint
        $hint = $exception->getMigrationHint();
        $this->assertNotNull($hint);
        $this->assertStringContainsString('MERGE instead of CREATE', $hint);
        $this->assertStringContainsString('Check if the node exists', $hint);
        $this->assertStringContainsString('Remove the unique constraint', $hint);
    }

    public function test_unique_constraint_violation_with_special_characters(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphConstraintException::uniqueConstraintViolation(
            'User-Node',
            'user.email',
            "john's@example.com"
        );

        $this->assertStringContainsString('User-Node', $exception->getMessage());
        $this->assertStringContainsString('user.email', $exception->getMessage());
        $this->assertStringContainsString("john's@example.com", $exception->getMessage());
    }

    /**
     * Test constraint creation failure
     */
    public function test_constraint_creation_failed(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphConstraintException::constraintCreationFailed(
            'user_email_unique',
            'Existing data violates the constraint'
        );

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphConstraintException::class, $exception);
        $this->assertStringContainsString('Failed to create constraint', $exception->getMessage());
        $this->assertStringContainsString('user_email_unique', $exception->getMessage());
        $this->assertStringContainsString('Existing data violates', $exception->getMessage());

        // Check migration hint
        $hint = $exception->getMigrationHint();
        $this->assertNotNull($hint);
        $this->assertStringContainsString('Common reasons', $hint);
        $this->assertStringContainsString('clean data first', $hint);
        $this->assertStringContainsString('drop it first', $hint);
        $this->assertStringContainsString('version compatibility', $hint);
    }

    /**
     * Test that static factory methods return correct type
     */
    public function test_static_factory_methods_return_correct_type(): void
    {
        $methods = [
            'uniqueConstraintViolation' => ['User', 'email', 'test@example.com'],
            'constraintCreationFailed' => ['constraint_name', 'reason'],
        ];

        foreach ($methods as $method => $params) {
            $exception = \Look\EloquentCypher\Exceptions\GraphConstraintException::$method(...$params);
            $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphConstraintException::class, $exception);
            $this->assertNotEmpty($exception->getMessage());
            $this->assertNotNull($exception->getMigrationHint());
        }
    }

    /**
     * Test detailed message includes all information
     */
    public function test_detailed_message_with_all_information(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphConstraintException::uniqueConstraintViolation('User', 'email', 'test@example.com');
        $exception->setCypher('CREATE (n:User {email: $email})')
            ->setParameters(['email' => 'test@example.com']);

        $detailed = $exception->getDetailedMessage();

        // Check base message
        $this->assertStringContainsString('Unique constraint violation', $detailed);

        // Check migration hint is included
        $this->assertStringContainsString('Migration Hint:', $detailed);
        $this->assertStringContainsString('MERGE instead of CREATE', $detailed);

        // Check Cypher query is included
        $this->assertStringContainsString('Cypher Query:', $detailed);
        $this->assertStringContainsString('CREATE (n:User', $detailed);

        // Check parameters are included
        $this->assertStringContainsString('Parameters:', $detailed);
        $this->assertStringContainsString('"email": "test@example.com"', $detailed);
    }

    /**
     * Test edge cases
     */
    public function test_empty_label_and_property(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphConstraintException::uniqueConstraintViolation('', '', '');

        $this->assertStringContainsString("label ''", $exception->getMessage());
        $this->assertStringContainsString("=''", $exception->getMessage());
    }

    public function test_long_constraint_name(): void
    {
        $longName = str_repeat('very_long_constraint_name_', 10);
        $exception = \Look\EloquentCypher\Exceptions\GraphConstraintException::constraintCreationFailed($longName, 'Test reason');

        $this->assertStringContainsString($longName, $exception->getMessage());
    }

    public function test_special_characters_in_messages(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphConstraintException::uniqueConstraintViolation(
            'User<Node>',
            'email@field',
            'user"with\'quotes@example.com'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('User<Node>', $message);
        $this->assertStringContainsString('email@field', $message);
        $this->assertStringContainsString('user"with\'quotes@example.com', $message);
    }
}
