<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Exceptions;

class GraphTransientException extends \Look\EloquentCypher\Exceptions\GraphTransactionException
{
    protected int $attemptNumber = 0;

    protected int $maxAttempts = 0;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        string $query = '',
        array $parameters = []
    ) {
        parent::__construct($message, $code, $previous);

        if ($query) {
            $this->setCypher($query);
        }
        if (! empty($parameters)) {
            $this->setParameters($parameters);
        }

        $this->setMigrationHint('This is a transient error that can be resolved by retrying the operation. '.
                               'Consider using managed transactions with automatic retry logic.');
    }

    public function setAttemptInfo(int $attemptNumber, int $maxAttempts): self
    {
        $this->attemptNumber = $attemptNumber;
        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function isRetryable(): bool
    {
        return true;
    }

    public function getSuggestedDelay(): int
    {
        // Suggest exponential backoff starting at 100ms
        return min(100 * (2 ** $this->attemptNumber), 5000);
    }

    public function getQuery(): string
    {
        return $this->getCypher() ?? '';
    }

    public function getHint(): string
    {
        return $this->getMigrationHint() ?? '';
    }
}
