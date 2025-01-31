<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\Common\PooledTransaction;

/**
 * @internal
 * @extends PooledTransaction<PostgresResult, PostgresStatement, PostgresTransaction>
 */
final class PostgresPooledTransaction extends PooledTransaction implements PostgresTransaction
{
    use PostgresTransactionDelegate;

    protected function getTransaction(): PostgresTransaction
    {
        return $this->transaction;
    }
}
