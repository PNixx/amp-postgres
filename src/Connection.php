<?php

namespace Amp\Postgres;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Sql\Link;
use Amp\Sql\Result;
use Amp\Sql\Statement;
use Amp\Sql\TransactionIsolation;

abstract class Connection implements Link, Handle
{
    private readonly Handle $handle;

    /** @var DeferredFuture|null Used to only allow one transaction at a time. */
    private ?DeferredFuture $busy = null;

    abstract public static function connect(PostgresConfig $connectionConfig, ?Cancellation $cancellation = null): self;

    public function __construct(Handle $handle)
    {
        $this->handle = $handle;
    }

    final public function isAlive(): bool
    {
        return $this->handle->isAlive();
    }

    final public function getLastUsedAt(): int
    {
        return $this->handle->getLastUsedAt();
    }

    final public function close(): void
    {
        $this->handle->close();
    }

    /**
     * @param string $methodName Method to execute.
     * @param mixed ...$args Arguments to pass to function.
     */
    private function send(string $methodName, ...$args): Result|Statement|Listener
    {
        $this->awaitPending();
        return $this->handle->{$methodName}(...$args);
    }

    private function awaitPending(): void
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }
    }

    /**
     * Reserves the connection for a transaction.
     */
    private function reserve(): void
    {
        \assert($this->busy === null);
        $this->busy = new DeferredFuture;
    }

    /**
     * Releases the transaction lock.
     */
    private function release(): void
    {
        \assert($this->busy !== null);

        $this->busy->complete(null);
        $this->busy = null;
    }

    final public function query(string $sql): Result
    {
        $this->awaitPending();
        return $this->handle->query($sql);
    }

    final public function execute(string $sql, array $params = []): Result
    {
        $this->awaitPending();
        return $this->handle->execute($sql, $params);
    }

    final public function prepare(string $sql): Statement
    {
        $this->awaitPending();
        return $this->handle->prepare($sql);
    }

    final public function notify(string $channel, string $payload = ""): Result
    {
        $this->awaitPending();
        return $this->handle->notify($channel, $payload);
    }

    final public function listen(string $channel): Listener
    {
        $this->awaitPending();
        return $this->handle->listen($channel);
    }

    final public function beginTransaction(
        TransactionIsolation $isolation = TransactionIsolation::Committed
    ): Transaction {
        $this->reserve();

        try {
            $this->handle->query("BEGIN TRANSACTION ISOLATION LEVEL " . $isolation->toSql());
        } catch (\Throwable $exception) {
            $this->release();
            throw $exception;
        }

        return new ConnectionTransaction($this->handle, $this->release(...), $isolation);
    }

    final public function quoteString(string $data): string
    {
        return $this->handle->quoteString($data);
    }

    final public function quoteName(string $name): string
    {
        return $this->handle->quoteName($name);
    }
}
