<?php
// ============================================================
// WhatsApp CRM OS — Database Connection
// PDO singleton with reconnect support
// Optimized for Hostinger shared hosting
// ============================================================

defined('CRM_APP') or die('Direct access not permitted');

class Database
{
    /** @var Database|null */
    private static ?Database $instance = null;

    /** @var PDO|null */
    private ?PDO $pdo = null;

    /** @var int Connection attempts counter */
    private int $connectAttempts = 0;

    private const MAX_ATTEMPTS   = 3;
    private const RETRY_DELAY_US = 500000; // 0.5 seconds in microseconds

    // ── Private constructor (singleton) ──────────────────────
    private function __construct() {}
    private function __clone() {}

    // ── Get singleton instance ────────────────────────────────
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Get PDO connection (lazy + reconnect) ─────────────────
    public function getConnection(): PDO
    {
        if ($this->pdo === null || !$this->isAlive()) {
            $this->connect();
        }
        return $this->pdo;
    }

    // ── Check connection is alive ─────────────────────────────
    private function isAlive(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    // ── Establish connection ──────────────────────────────────
    private function connect(): void
    {
        $this->connectAttempts = 0;

        while ($this->connectAttempts < self::MAX_ATTEMPTS) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    DB_HOST,
                    DB_PORT,
                    DB_NAME,
                    DB_CHARSET
                );

                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_PERSISTENT         => false, // Shared hosting: no persistent
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    PDO::ATTR_TIMEOUT            => 10,
                ];

                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

                // Shared hosting optimization: reduce memory footprint
                $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
                $this->pdo->exec("SET SESSION time_zone = '+05:30'");

                return; // Success

            } catch (PDOException $e) {
                $this->connectAttempts++;

                if ($this->connectAttempts >= self::MAX_ATTEMPTS) {
                    error_log('[CRM DB] Connection failed after ' . self::MAX_ATTEMPTS . ' attempts: ' . $e->getMessage());
                    throw new RuntimeException('Database connection failed: ' . $e->getMessage());
                }

                usleep(self::RETRY_DELAY_US);
            }
        }
    }

    // ── Helper: run a prepared query ──────────────────────────
    /**
     * @param string $sql
     * @param array  $params
     * @return PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $pdo  = $this->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // ── Helper: fetch single row ──────────────────────────────
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    // ── Helper: fetch all rows ────────────────────────────────
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    // ── Helper: insert and return last insert ID ──────────────
    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Helper: execute and return affected rows ──────────────
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    // ── Helper: begin transaction ─────────────────────────────
    public function beginTransaction(): void
    {
        $this->getConnection()->beginTransaction();
    }

    // ── Helper: commit transaction ────────────────────────────
    public function commit(): void
    {
        $this->getConnection()->commit();
    }

    // ── Helper: rollback transaction ──────────────────────────
    public function rollback(): void
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    // ── Helper: get last insert ID ────────────────────────────
    public function lastInsertId(): int
    {
        return (int) $this->getConnection()->lastInsertId();
    }

    // ── Helper: check if table exists ────────────────────────
    public function tableExists(string $table): bool
    {
        try {
            $result = $this->fetchOne(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = ? LIMIT 1",
                [DB_NAME, $table]
            );
            return $result !== null;
        } catch (Exception $e) {
            return false;
        }
    }

    // ── Close connection ──────────────────────────────────────
    public function close(): void
    {
        $this->pdo = null;
    }
}

// ── Global shortcut function ──────────────────────────────────
/**
 * Get the database instance (convenience wrapper)
 * Usage: db()->fetchAll($sql, $params)
 */
function db(): Database
{
    return Database::getInstance();
}

/**
 * Get PDO connection directly
 * Usage: pdo()->prepare($sql)
 */
function pdo(): PDO
{
    return Database::getInstance()->getConnection();
}

// ── Settings cache (avoid repeated DB reads) ─────────────────
$_settingsCache = null;

/**
 * Get a setting value from DB (cached per request)
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function setting(string $key, mixed $default = null): mixed
{
    global $_settingsCache;

    if ($_settingsCache === null) {
        try {
            $rows = db()->fetchAll("SELECT setting_key, setting_value, setting_type FROM settings");
            $_settingsCache = [];
            foreach ($rows as $row) {
                $val = $row['setting_value'];
                switch ($row['setting_type']) {
                    case 'integer': $val = (int)    $val; break;
                    case 'boolean': $val = (bool)   (int) $val; break;
                    case 'json':    $val = json_decode($val, true); break;
                }
                $_settingsCache[$row['setting_key']] = $val;
            }
        } catch (Exception $e) {
            error_log('[CRM] Settings load failed: ' . $e->getMessage());
            $_settingsCache = [];
        }
    }

    return $_settingsCache[$key] ?? $default;
}

/**
 * Update a setting in DB (clears cache)
 */
function updateSetting(string $key, mixed $value): void
{
    global $_settingsCache;

    db()->execute(
        "UPDATE settings SET setting_value = ? WHERE setting_key = ?",
        [is_array($value) ? json_encode($value) : (string) $value, $key]
    );

    $_settingsCache = null; // Invalidate cache
}
