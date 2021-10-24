<?php

namespace Amp\Postgres\Test;

use Amp\Delayed;
use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline;
use Amp\Postgres\Link;
use Amp\Postgres\Listener;
use Amp\Postgres\QueryExecutionError;
use Amp\Postgres\Transaction;
use Amp\Sql\QueryError;
use Amp\Sql\Result;
use Amp\Sql\Statement;
use Amp\Sql\Transaction as SqlTransaction;
use Amp\Sql\TransactionError;
use function Amp\async;
use function Amp\asyncCallable;
use function Amp\await;

abstract class AbstractLinkTest extends AsyncTestCase
{
    protected const CREATE_QUERY = "CREATE TABLE test (domain VARCHAR(63) NOT NULL,
                                                       tld VARCHAR(63) NOT NULL,
                                                       keys INTEGER[] NOT NULL,
                                                       enabled BOOLEAN NOT NULL, 
                                                       number DOUBLE PRECISION NOT NULL,
                                                       nullable CHAR(1) DEFAULT NULL,
                                                       PRIMARY KEY (domain, tld))";
    protected const DROP_QUERY = "DROP TABLE IF EXISTS test";
    protected const INSERT_QUERY = 'INSERT INTO test VALUES ($1, $2, $3, $4, $5, $6)';
    protected const FIELD_COUNT = 6;

    /** @var \Amp\Postgres\Connection */
    protected $connection;

    /**
     * @return array Start test data for database.
     */
    public function getData(): array
    {
        return [
            ['amphp', 'org', [1], true, 3.14159, null],
            ['github', 'com', [1, 2, 3, 4, 5], false, 2.71828, null],
            ['google', 'com', [1, 2, 3, 4], true, 1.61803, null],
            ['php', 'net', [1, 2], false, 0.0, null],
        ];
    }

    protected function verifyResult(ResultSet $result, array $data): \Generator
    {
        $this->assertSame(self::FIELD_COUNT, $result->getFieldCount());

        for ($i = 0; (yield $result->advance()) && isset($data[$i]); ++$i) {
            $row = $result->getCurrent();
            $this->assertSame($data[$i][0], $row['domain']);
            $this->assertSame($data[$i][1], $row['tld']);
            $this->assertSame($data[$i][2], $row['keys']);
            $this->assertSame($data[$i][3], $row['enabled']);
            $this->assertIsFloat($data[$i][4], $row['number']);
            $this->assertNull($row['nullable']);
        }
    }

    /**
     * @param string $connectionString
     *
     * @return Link Connection or Link object to be tested.
     */
    abstract public function createLink(string $connectionString): Link;

    public function setUp(): void
    {
        parent::setUp();
        $this->ignoreLoopWatchers();
        $this->link = $this->createLink('host=localhost user=postgres');
    }

    public function cleanup(): void
    {
        parent::cleanup();
        $this->link->close();
    }

    public function testQueryWithTupleResult()
    {
        $result = $this->link->query("SELECT * FROM test");

        $data = $this->getData();

        for ($i = 0; $row = $result->continue(); ++$i) {
            $this->assertSame($data[$i][0], $row['domain']);
            $this->assertSame($data[$i][1], $row['tld']);
        }

        $this->assertNull($result->getNextResult());
    }

    public function testMultipleQueryWithTupleResult()
    {
        $result = $this->link->query("SELECT * FROM test; SELECT * FROM test");

        $data = $this->getData();

        for ($i = 0; $row = $result->continue(); ++$i) {
            $this->assertSame($data[$i][0], $row['domain']);
            $this->assertSame($data[$i][1], $row['tld']);
        }

        $result = $result->getNextResult();

        $this->assertInstanceOf(Result::class, $result);

        for ($i = 0; $row = $result->continue(); ++$i) {
            $this->assertSame($data[$i][0], $row['domain']);
            $this->assertSame($data[$i][1], $row['tld']);
        }

        $this->assertNull($result->getNextResult());
    }

    public function testMultipleQueryWithCommandResultFirst()
    {
        $result = $this->link->query("INSERT INTO test (domain, tld) VALUES ('gitlab', 'com'); SELECT * FROM test");

        $this->assertNull($result->continue());

        $this->assertSame(1, $result->getRowCount());

        $result = $result->getNextResult();

        $this->assertInstanceOf(Result::class, $result);

        $data = $this->getData();
        $data[] = ['gitlab', 'com']; // Add inserted row to expected data.

        for ($i = 0; $row = $result->continue(); ++$i) {
            $this->assertSame($data[$i][0], $row['domain']);
            $this->assertSame($data[$i][1], $row['tld']);
        }

        $this->assertNull($result->getNextResult());
    }

    public function testMultipleQueryWithCommandResultSecond()
    {
        $result = $this->link->query("SELECT * FROM test; INSERT INTO test (domain, tld) VALUES ('gitlab', 'com')");

        $data = $this->getData();

        for ($i = 0; $row = $result->continue(); ++$i) {
            $this->assertSame($data[$i][0], $row['domain']);
            $this->assertSame($data[$i][1], $row['tld']);
        }

        $result = $result->getNextResult();

        $this->assertNull($result->continue());

        $this->assertSame(1, $result->getRowCount());

        $this->assertNull($result->getNextResult());
    }

    public function testQueryWithUnconsumedTupleResult()
    {
        $result = $this->link->query("SELECT * FROM test");

        $this->assertInstanceOf(Result::class, $result);

        unset($result); // Force destruction of result object.

        /** @var \Amp\Postgres\ResultSet $result */
        $result = yield $this->connection->query("SELECT * FROM test");

        $this->assertInstanceOf(ResultSet::class, $result);

        $data = $this->getData();

        yield from $this->verifyResult($result, $data);
    }

    public function testQueryWithCommandResult(): \Generator
    {
        /** @var CommandResult $result */
        $result = yield $this->connection->query("INSERT INTO test VALUES ('canon', 'jp', '{1}', true, 4.2)");

        $this->assertInstanceOf(CommandResult::class, $result);
        $this->assertSame(1, $result->getAffectedRowCount());
    }

    public function testQueryWithEmptyQuery(): Promise
    {
        $this->expectException(QueryError::class);

        $this->link->query('');
    }

    public function testQueryWithSyntaxError()
    {
        /** @var Result $result */
        try {
            $result = $this->link->query("SELECT & FROM test");
            $this->fail(\sprintf("An instance of %s was expected to be thrown", QueryExecutionError::class));
        } catch (QueryExecutionError $exception) {
            $diagnostics = $exception->getDiagnostics();
            $this->assertArrayHasKey("sqlstate", $diagnostics);
        }
    }

    public function testPrepare()
    {
        $query = "SELECT * FROM test WHERE domain=\$1";

        /** @var Statement $statement */
        $statement = $this->link->prepare($query);

        $this->assertSame($query, $statement->getQuery());

        $data = $this->getData()[0];

        /** @var \Amp\Postgres\ResultSet $result */
        $result = yield $statement->execute([$data[0]]);

        $this->assertInstanceOf(ResultSet::class, $result);

        yield from $this->verifyResult($result, [$data]);
    }

    /**
     * @depends testPrepare
     */
    public function testPrepareWithNamedParams()
    {
        $query = "SELECT * FROM test WHERE domain=:domain AND tld=:tld";

        $statement = $this->link->prepare($query);

        $data = $this->getData()[0];

        $this->assertSame($query, $statement->getQuery());

        /** @var \Amp\Postgres\ResultSet $result */
        $result = yield $statement->execute(['domain' => $data[0], 'tld' => $data[1]]);

        $this->assertInstanceOf(ResultSet::class, $result);

        yield from $this->verifyResult($result, [$data]);
    }

    /**
     * @depends testPrepare
     */
    public function testPrepareWithUnnamedParams()
    {
        $query = "SELECT * FROM test WHERE domain=? AND tld=?";

        $statement = $this->link->prepare($query);

        $data = $this->getData()[0];

        $this->assertSame($query, $statement->getQuery());

        /** @var \Amp\Postgres\ResultSet $result */
        $result = yield $statement->execute([$data[0], $data[1]]);

        $this->assertInstanceOf(ResultSet::class, $result);

        yield from $this->verifyResult($result, [$data]);
    }

    /**
     * @depends testPrepare
     */
    public function testPrepareWithNamedParamsWithDataAppearingAsNamedParam()
    {
        $query = "SELECT * FROM test WHERE domain=:domain OR domain=':domain'";

        $statement = $this->link->prepare($query);

        $data = $this->getData()[0];

        $this->assertSame($query, $statement->getQuery());

        /** @var \Amp\Postgres\ResultSet $result */
        $result = yield $statement->execute(['domain' => $data[0]]);

        $this->assertInstanceOf(ResultSet::class, $result);

        yield from $this->verifyResult($result, [$data]);
    }

    /**
     * @depends testPrepare
     */
    public function testPrepareInvalidQuery()
    {
        $this->expectException(QueryExecutionError::class);
        $this->expectExceptionMessage('column "invalid" does not exist');

        $query = "SELECT * FROM test WHERE invalid=\$1";

        $statement = $this->link->prepare($query);

        $statement->execute(['param']);
    }

    /**
     * @depends testPrepare
     */
    public function testPrepareSameQuery()
    {
        $sql = "SELECT * FROM test WHERE domain=\$1";

        $statement1 = $this->link->prepare($sql);

        $statement2 = $this->link->prepare($sql);

        $this->assertInstanceOf(Statement::class, $statement1);
        $this->assertInstanceOf(Statement::class, $statement2);

        unset($statement1);

        $data = $this->getData()[0];

        /** @var \Amp\Postgres\ResultSet $result */
        $result = yield $statement2->execute([$data[0]]);

        $this->assertInstanceOf(ResultSet::class, $result);

        yield from $this->verifyResult($result, [$data]);
    }

    /**
     * @depends testPrepareSameQuery
     */
    public function testSimultaneousPrepareSameQuery()
    {
        $sql = "SELECT * FROM test WHERE domain=\$1";

        $statement1 = async(fn() => $this->link->prepare($sql));
        $statement2 = async(fn() => $this->link->prepare($sql));

        [$statement1, $statement2] = await([$statement1, $statement2]);

        $data = $this->getData()[0];

        /** @var \Amp\Postgres\ResultSet $result */
        $result = yield $statement1->execute([$data[0]]);

        $this->assertInstanceOf(ResultSet::class, $result);

        yield from $this->verifyResult($result, [$data]);

        unset($statement1);

        /** @var \Amp\Postgres\ResultSet $result */
        $result = yield $statement2->execute([$data[0]]);

        $this->assertInstanceOf(ResultSet::class, $result);

        yield from $this->verifyResult($result, [$data]);
    }

    public function testPrepareSimilarQueryReturnsDifferentStatements()
    {
        $statement1 = async(fn() => $this->link->prepare("SELECT * FROM test WHERE domain=\$1"));

        $statement2 = async(fn() => $this->link->prepare("SELECT * FROM test WHERE domain=:domain"));

        [$statement1, $statement2] = await([$statement1, $statement2]);

        $this->assertInstanceOf(Statement::class, $statement1);
        $this->assertInstanceOf(Statement::class, $statement2);

        $this->assertNotSame($statement1, $statement2);

        $data = $this->getData()[0];

        $results = [];

        $results[] = Pipeline\toArray($statement1->execute([$data[0]]));
        $results[] = Pipeline\toArray($statement2->execute(['domain' => $data[0]]));

        foreach ($results as $result) {
            /** @var Result $result */
            foreach ($result as $row) {
                $this->assertSame($data[0], $row['domain']);
                $this->assertSame($data[1], $row['tld']);
            }
        }
    }

    public function testPrepareThenExecuteWithUnconsumedTupleResult()
    {
        $statement = $this->link->prepare("SELECT * FROM test");

        $result = $statement->execute();

        unset($result); // Force destruction of result object.

        $result = $statement->execute();

        $data = $this->getData();

        yield from $this->verifyResult($result, $data);
    }

    public function testExecute()
    {
        $data = $this->getData()[0];

        /** @var \Amp\Postgres\ResultSet $result */
        $result = yield $this->connection->execute("SELECT * FROM test WHERE domain=\$1", [$data[0]]);

        $this->assertInstanceOf(ResultSet::class, $result);

        yield from $this->verifyResult($result, [$data]);
    }

    /**
     * @depends testExecute
     */
    public function testExecuteWithNamedParams()
    {
        $data = $this->getData()[0];

        $result = $this->link->execute(
            "SELECT * FROM test WHERE domain=:domain",
            ['domain' => $data[0]]
        );

        $this->assertInstanceOf(ResultSet::class, $result);

        yield from $this->verifyResult($result, [$data]);
    }

    /**
     * @depends testExecute
     */
    public function testExecuteWithInvalidParams()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Value for unnamed parameter at position 0 missing");

        $this->link->execute("SELECT * FROM test WHERE domain=\$1");
    }

    /**
     * @depends testExecute
     */
    public function testExecuteWithInvalidNamedParams()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Value for named parameter 'domain' missing");

        $this->link->execute("SELECT * FROM test WHERE domain=:domain", ['tld' => 'com']);
    }

    /**
     * @depends testQueryWithTupleResult
     */
    public function testSimultaneousQuery()
    {
        $callback = asyncCallable(function ($value) {
            $result = $this->link->query("SELECT {$value} as value");

            if ($value) {
                new Delayed(100);
            }

            while ($row = $result->continue()) {
                $this->assertEquals($value, $row['value']);
            }
        });

        await([$callback(0), $callback(1)]);
    }

    /**
     * @depends testSimultaneousQuery
     */
    public function testSimultaneousQueryWithOneFailing()
    {
        $callback = asyncCallable(function ($query) {
            $result = $this->link->query($query);

            $data = $this->getData();

            for ($i = 0; $row = $result->continue(); ++$i) {
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }

            return $result;
        });

        $result = null;

        try {
            $successful = $callback("SELECT * FROM test");
            $failing = $callback("SELECT & FROM test");

            $result = await($successful);
            await($failing);
        } catch (QueryError $exception) {
            $this->assertInstanceOf(Result::class, $result);
            return;
        }

        $this->fail(\sprintf("Test did not throw an instance of %s", QueryError::class));
    }

    public function testSimultaneousQueryAndPrepare()
    {
        $promises = [];
        $promises[] = async(function () {
            $result = $this->link->query("SELECT * FROM test");

            $data = $this->getData();

            for ($i = 0; $row = $result->continue(); ++$i) {
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        });

        $promises[] = async(function () {
            $statement = ($this->link->prepare("SELECT * FROM test"));

            $result = $statement->execute();

            $data = $this->getData();

            for ($i = 0; $row = $result->continue(); ++$i) {
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        });

        await($promises);
    }

    public function testSimultaneousPrepareAndExecute()
    {
        $promises[] = async(function () {
            $statement = $this->link->prepare("SELECT * FROM test");

            $result = $statement->execute();

            $data = $this->getData();

            for ($i = 0; $row = $result->continue(); ++$i) {
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        });

        $promises[] = async(function () {
            $result = $this->link->execute("SELECT * FROM test");

            $data = $this->getData();

            for ($i = 0; $row = $result->continue(); ++$i) {
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        });

        await($promises);
    }

    public function testTransaction()
    {
        $isolation = SqlTransaction::ISOLATION_COMMITTED;

        $transaction = $this->link->beginTransaction($isolation);

        $this->assertInstanceOf(Transaction::class, $transaction);

        $data = $this->getData()[0];

        $this->assertTrue($transaction->isAlive());
        $this->assertTrue($transaction->isActive());
        $this->assertSame($isolation, $transaction->getIsolationLevel());

        $transaction->createSavepoint('test');

        $statement = $transaction->prepare("SELECT * FROM test WHERE domain=:domain");
        $result = $statement->execute(['domain' => $data[0]]);

        $this->assertInstanceOf(Result::class, $result);

        unset($result); // Force destruction of result object.

        $result = $transaction->execute("SELECT * FROM test WHERE domain=\$1 FOR UPDATE", [$data[0]]);

        $this->assertInstanceOf(Result::class, $result);

        unset($result); // Force destruction of result object.

        $transaction->rollbackTo('test');

        $transaction->commit();

        $this->assertFalse($transaction->isAlive());
        $this->assertFalse($transaction->isActive());

        try {
            $result = $transaction->execute("SELECT * FROM test");
            $this->fail('Query should fail after transaction commit');
        } catch (TransactionError $exception) {
            // Exception expected.
        }
    }

    public function testListen()
    {
        $channel = "test";
        $listener = $this->link->listen($channel);

        $this->assertInstanceOf(Listener::class, $listener);
        $this->assertSame($channel, $listener->getChannel());

        Loop::delay(100, asyncCallable(function () use ($channel) {
            $this->link->query(\sprintf("NOTIFY %s, '%s'", $channel, '0'));
            $this->link->query(\sprintf("NOTIFY %s, '%s'", $channel, '1'));
        }));

        $count = 0;
        Loop::delay(200, asyncCallable(function () use ($listener) {
            $listener->unlisten();
        }));

        while ($notification = $listener->continue()) {
            $this->assertSame($notification->payload, (string) $count++);
        }

        $this->assertSame(2, $count);
    }

    /**
     * @depends testListen
     */
    public function testNotify()
    {
        $channel = "test";
        $listener = $this->link->listen($channel);

        Loop::delay(100, asyncCallable(function () use ($channel) {
            $this->link->notify($channel, '0');
            $this->link->notify($channel, '1');
        }));

        $count = 0;
        Loop::delay(200, asyncCallable(function () use ($listener) {
            $listener->unlisten();
        }));

        while ($notification = $listener->continue()) {
            $this->assertSame($notification->payload, (string) $count++);
        }

        $this->assertSame(2, $count);
    }

    /**
     * @depends testListen
     */
    public function testListenOnSameChannel()
    {
        $this->expectException(QueryError::class);
        $this->expectExceptionMessage('Already listening on channel');

        $channel = "test";
        await([$this->link->listen($channel), $this->link->listen($channel)]);
    }

    public function testQueryAfterErroredQuery()
    {
        try {
            $result = yield $this->connection->query("INSERT INTO test VALUES ('github', 'com', '{1, 2, 3}', true, 4.2)");
        } catch (QueryExecutionError $exception) {
            // Expected exception due to duplicate key.
        }

        /** @var CommandResult $result */
        $result = yield $this->connection->query("INSERT INTO test VALUES ('gitlab', 'com', '{1, 2, 3}', true, 4.2)");

        $this->assertSame(1, $result->getRowCount());
    }
}
