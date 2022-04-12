<?php

namespace Amp\Postgres;

use Amp\Sql\ConnectionException;
use Amp\Sql\Executor as SqlExecutor;
use Amp\Sql\Result;
use Amp\Sql\SqlException;

interface Executor extends SqlExecutor
{
    /**
     * @param string $channel Channel name.
     * @param string $payload Notification payload.
     *
     * @throws SqlException If the operation fails due to unexpected condition.
     * @throws ConnectionException If the connection to the database is lost.
     */
    public function notify(string $channel, string $payload = ""): Result;
}
