<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Aplica migrations/*.sql em ordem, uma única vez cada.
 *  - Lock no MySQL (GET_LOCK): duas execuções nunca migram ao mesmo tempo.
 *  - Checksum SHA-256: editar uma migration já aplicada aborta (evita divergência entre servidores).
 *  - DDL no MySQL faz commit implícito: migrations devem ser pequenas e ADITIVAS.
 * Compatível com bancos criados pela versão Node/Docker (mesma tabela schema_migrations).
 */
final class Migrator
{
    public static function files(string $dir): array
    {
        $f = array_filter(scandir($dir) ?: [], fn($x) => preg_match('/^\d+_.+\.sql$/', $x));
        sort($f, SORT_STRING);
        return array_values($f);
    }

    private static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY, checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, duration_ms INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /** @return array{pending:string[],applied:int} */
    public static function status(?string $dir = null): array
    {
        $dir = $dir ?: ROOT . '/migrations';
        $pdo = Db::pdo();
        self::ensureTable($pdo);
        $done = array_column(Db::all('SELECT version FROM schema_migrations'), 'version');
        return ['pending' => array_values(array_diff(self::files($dir), $done)), 'applied' => count($done)];
    }

    /** Confere (sem aplicar nada) se alguma migration já aplicada foi alterada no diretório informado. @return string[] erros */
    public static function checkIntegrity(string $dir): array
    {
        self::ensureTable(Db::pdo());
        $errors = [];
        foreach (Db::all('SELECT version, checksum FROM schema_migrations') as $r) {
            $f = "$dir/{$r['version']}";
            if (is_file($f) && hash('sha256', (string) file_get_contents($f)) !== $r['checksum']) {
                $errors[] = "{$r['version']} foi alterada depois de aplicada";
            }
        }
        return $errors;
    }

    /** @return string[] arquivos aplicados nesta execução */
    public static function run(?string $dir = null, ?callable $log = null): array
    {
        $dir = $dir ?: ROOT . '/migrations';
        $log = $log ?: static function (string $m): void {};
        $pdo = Db::pdo();
        if (!Db::lock('rotina_migrate', 120)) throw new \RuntimeException('Outra migração está em andamento. Tente de novo em instantes.');
        $applied = [];
        try {
            self::ensureTable($pdo);
            $known = [];
            foreach (Db::all('SELECT version, checksum FROM schema_migrations') as $r) $known[$r['version']] = $r['checksum'];

            foreach (self::files($dir) as $file) {
                $sql = (string) file_get_contents("$dir/$file");
                $sum = hash('sha256', $sql);
                if (isset($known[$file])) {
                    if ($known[$file] !== $sum) {
                        throw new \RuntimeException("A migration $file foi alterada depois de aplicada! Crie uma nova migration em vez de editar.");
                    }
                    continue;
                }
                $log("aplicando $file ...");
                $t0 = microtime(true);
                foreach (SqlSplitter::split($sql) as $stmt) $pdo->exec($stmt);
                Db::q('INSERT INTO schema_migrations (version, checksum, duration_ms) VALUES (?,?,?)',
                    [$file, $sum, (int) round((microtime(true) - $t0) * 1000)]);
                $applied[] = $file;
            }
        } finally {
            Db::unlock('rotina_migrate');
        }
        return $applied;
    }
}
