<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Core;

final class Database
{
    public readonly \PDO $pdo;

    public function __construct(array $config)
    {
        foreach (['host', 'name'] as $field) {
            if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', (string)($config[$field] ?? ''))) {
                throw new AppException('数据库地址或名称格式不正确');
            }
        }
        $port = (int)($config['port'] ?? 3306);
        if ($port < 1 || $port > 65535) {
            throw new AppException('数据库端口不正确');
        }
        $this->pdo = new \PDO("mysql:host={$config['host']};port={$port};dbname={$config['name']};charset=utf8mb4", $config['user'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        return $query;
    }

    public function all(string $sql, array $params = []): array { return $this->run($sql, $params)->fetchAll(); }
    public function one(string $sql, array $params = []): ?array { return $this->run($sql, $params)->fetch() ?: null; }
    public function value(string $sql, array $params = []): mixed { return $this->run($sql, $params)->fetchColumn(); }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    public function transaction(callable $operation): mixed
    {
        $nested=$this->pdo->inTransaction();
        $savepoint='panel_'.bin2hex(random_bytes(6));
        if ($nested) { $this->pdo->exec('SAVEPOINT '.$savepoint); } else { $this->pdo->beginTransaction(); }
        try {
            $result = $operation();
            if ($nested) { $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint); } else { $this->pdo->commit(); }
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { if ($nested) { $this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint); } else { $this->pdo->rollBack(); } }
            throw $e;
        }
    }

    public function operationalLock(callable $operation,int $wait=0): mixed
    {
        if (!(int)$this->value("SELECT GET_LOCK('cf_panel_worker',?)",[max(0,min(30,$wait))])) { throw new AppException('后台正在执行一个步骤，请稍后再操作',409,'operation_busy'); }
        try { return $operation(); } finally { $this->value("SELECT RELEASE_LOCK('cf_panel_worker')"); }
    }

    public function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
        foreach (glob(BASE_PATH . '/database/migrations/*.sql') as $file) {
            $name = basename($file);
            if ($this->value('SELECT COUNT(*) FROM migrations WHERE name=?', [$name])) { continue; }
            foreach (preg_split('/;\s*(?:\r?\n|$)/', file_get_contents($file)) as $statement) {
                if (trim($statement) !== '') {
                    try { $this->pdo->exec($statement); }
                    catch (\PDOException $e) { if (($e->errorInfo[1]??0)!==1060||!preg_match('/^\s*ALTER TABLE \w+ ADD COLUMN /i',$statement)) { throw $e; } }
                }
            }
            $this->run('INSERT INTO migrations(name) VALUES(?)', [$name]);
        }
    }
}
