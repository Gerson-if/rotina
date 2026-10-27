<?php
declare(strict_types=1);
namespace Rotina;

use PDO;

/** Conexão PDO única + atalhos. Prepared statements nativos (sem multi-statement). */
final class Db
{
    private static ?PDO $pdo = null;

    /** Offset atual do fuso do negócio no formato +HH:MM (para SET time_zone) */
    public static function tzOffset(): string
    {
        return (new \DateTime('now', new \DateTimeZone(Config::timezone())))->format('P');
    }

    public static function connect(array $db, bool $buffered = true): PDO
    {
        $dbPart = ($db['name'] ?? '') !== '' ? 'dbname=' . $db['name'] . ';' : '';   // nome vazio: conecta só ao servidor (usado para criar o banco)
        $dsn = sprintf('mysql:host=%s;port=%d;%scharset=utf8mb4', $db['host'], (int) $db['port'], $dbPart);
        if (($db['host'] ?? '') === 'localhost' && !empty($db['socket'])) {
            $dsn = sprintf('mysql:unix_socket=%s;%scharset=utf8mb4', $db['socket'], $dbPart);
        }
        return new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => $buffered,
            // innodb_lock_wait_timeout: quem espera um bloqueio desiste em 10 s (padrão do MySQL: 50 s), antes de o navegador abortar a requisição
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '" . self::tzOffset() . "', innodb_lock_wait_timeout = 10",
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    }

    public static function reset(): void { self::$pdo = null; }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) self::$pdo = self::connect(Config::get('db'));
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }
    public static function all(string $sql, array $params = []): array { return self::q($sql, $params)->fetchAll(); }
    public static function one(string $sql, array $params = []): ?array { $r = self::q($sql, $params)->fetch(); return $r === false ? null : $r; }
    public static function val(string $sql, array $params = [])
    {
        $r = self::q($sql, $params)->fetch(PDO::FETCH_NUM);
        return $r === false ? null : $r[0];
    }

    /** "?,?,?" para IN (...) */
    public static function marks(array $values): string { return implode(',', array_fill(0, count($values), '?')); }

    /** Executa $fn dentro de uma transação (commit/rollback automático) */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Lock nomeado do MySQL; devolve true se obteve. */
    public static function lock(string $name, int $wait = 0): bool
    {
        return (int) self::val('SELECT GET_LOCK(?, ?)', [$name, $wait]) === 1;
    }
    public static function unlock(string $name): void { self::val('SELECT RELEASE_LOCK(?)', [$name]); }

    public static function today(int $offsetDays = 0): string
    {
        return (new \DateTime('now', new \DateTimeZone(Config::timezone())))
            ->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days')->format('Y-m-d');
    }

    /** Código de erro do MySQL (1062 duplicado, 1451/1452 chave estrangeira...) */
    public static function errno(\Throwable $e): int
    {
        return ($e instanceof \PDOException && isset($e->errorInfo[1])) ? (int) $e->errorInfo[1] : 0;
    }
    public static function errmsg(\Throwable $e): string
    {
        return ($e instanceof \PDOException && isset($e->errorInfo[2])) ? (string) $e->errorInfo[2] : $e->getMessage();
    }
}
