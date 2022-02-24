<?php

namespace Amp\Postgres;

use Amp\Sql\QueryError;

class QueryExecutionError extends QueryError
{
    private readonly array $diagnostics;

    public function __construct(string $message, array $diagnostics, string $query, \Throwable $previous = null)
    {
        parent::__construct($message, $query, $previous);
        $this->diagnostics = $diagnostics;
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }
}
