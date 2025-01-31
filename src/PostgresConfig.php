<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Sql\SqlConfig;

final class PostgresConfig extends SqlConfig
{
    public const DEFAULT_PORT = 5432;

    public const SSL_MODES = [
        'disable',
        'allow',
        'prefer',
        'require',
        'verify-ca',
        'verify-full',
    ];

    private ?string $sslMode = null;

    private ?string $connectionString = null;

    public static function fromString(string $connectionString): self
    {
        $parts = self::parseConnectionString($connectionString);

        if (!isset($parts["host"])) {
            throw new \Error("Host must be provided in connection string");
        }

        $config = new self(
            $parts["host"],
            (int) ($parts["port"] ?? self::DEFAULT_PORT),
            $parts["user"] ?? null,
            $parts["password"] ?? null,
            $parts["db"] ?? null
        );

        if (isset($parts["sslmode"])) {
            $config = $config->withSslMode($parts["sslmode"]);
        }

        return $config;
    }

    public function __construct(
        string $host,
        int $port = self::DEFAULT_PORT,
        ?string $user = null,
        ?string $password = null,
        ?string $database = null,
        private readonly ?string $application_name = null
    ) {
        parent::__construct($host, $port, $user, $password, $database);
    }

    public function __clone()
    {
        $this->connectionString = null;
    }

    public function getSslMode(): ?string
    {
        return $this->sslMode;
    }

    public function withSslMode(string $mode): self
    {
        if (!\in_array($mode, self::SSL_MODES, true)) {
            throw new \Error('Invalid SSL mode, must be one of: ' . \implode(', ', self::SSL_MODES));
        }

        $new = clone $this;
        $new->sslMode = $mode;
        return $new;
    }

    public function withoutSslMode(): self
    {
        $new = clone $this;
        $new->sslMode = null;
        return $new;
    }

    /**
     * @return string Connection string used with ext-pgsql and pecl-pq.
     */
    public function getConnectionString(): string
    {
        if ($this->connectionString !== null) {
            return $this->connectionString;
        }

        $chunks = [
            "host=" . $this->getHost(),
            "port=" . $this->getPort(),
        ];

        $user = $this->getUser();
        if ($user !== null) {
            $chunks[] = "user=" . $user;
        }

        $password = $this->getPassword();
        if ($password !== null) {
            $chunks[] = "password=" . $password;
        }

        $database = $this->getDatabase();
        if ($database !== null) {
            $chunks[] = "dbname=" . $database;
        }

        if ($this->sslMode !== null) {
            $chunks[] = "sslmode=" . $this->sslMode;
        }

        if ($this->application_name !== null) {
            $chunks[] = "application_name='" . addslashes($this->application_name) . "'";
        }

        return $this->connectionString = \implode(" ", $chunks);
    }
}
