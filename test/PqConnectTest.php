<?php

namespace Amp\Postgres\Test;

use Amp\Cancellation;
use Amp\Postgres\PqConnection;
use Amp\Sql\ConnectionConfig;

/**
 * @requires extension pq
 */
class PqConnectTest extends AbstractConnectTest
{
    public function connect(ConnectionConfig $connectionConfig, Cancellation $cancellation = null): PqConnection
    {
        return PqConnection::connect($connectionConfig, $cancellation);
    }
}
