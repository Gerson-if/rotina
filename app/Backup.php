<?php
declare(strict_types=1);
namespace Rotina;

use PDO;

/**
 * Backup lógico em PHP puro (não depende de mysqldump nem de acesso ao shell).
 *  - Instantâneo consistente: transação REPEATABLE READ com snapshot (equivale a --single-transaction).
 *  - Gera storage/backups/rotina_AAAAMMDD_HHMMSS[_rotulo].sql.gz  (o .sql puro é aceito pelo phpMyAdmin > Importar).
 *  - Valida o arquivo ao final e aplica rotação.
 */
final class Backup
{
    public static function dir(): string
    {
        $d = ROOT . '/storage/backups';
        if (!is_dir($d) && !@mkdir($d, 0770, true)) throw new \RuntimeException("Não foi possível criar $d");
        if (!is_writable($d)) throw new \RuntimeException("Sem permissão de escrita em $d");
        return $d;
    }

    /** @return array{file:string,size:int,rows:array<string,int>} */
    public static function create(string $label = ''): array
    {
        $label = preg_replace('/[^A-Za-z0-9_-]/', '', $label);
        $dir = self::dir();
        $gz = function_exists('gzopen');
        $name = 'rotina_' . date('Ymd_His') . ($label !== '' ? "_$label" : '') . ($gz ? '.sql.gz' : '.sql');
        $final = "$dir/$name";
        $tmp = "$dir/.tmp_" . getmypid() . '_' . $name;

        $pdo = Db::connect(Config::get('db'), false);             // sem buffer: não carrega tabelas inteiras na memória
        $out = $gz ? gzopen($tmp, 'wb6') : fopen($tmp, 'wb');
        if (!$out) throw new \RuntimeException('Não foi possível criar o arquivo temporário de backup.');
        $w = $gz ? 'gzwrite' : 'fwrite';
        $close = $gz ? 'gzclose' : 'fclose';

        try {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            $w($out, "-- Sistema de Verificação de Rotina — backup " . date('c') . "\n-- Versão " . Config::version() . "\n");
            $w($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $rows = [];
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as [$table]) {
                $q = '`' . str_replace('`', '``', $table) . '`';
                $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
                $w($out, "-- ----- $table -----\nDROP TABLE IF EXISTS $q;\n$create;\n");

                // colunas geradas (ex.: active_pppoe) não aceitam INSERT: são recalculadas pelo banco
                $cols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM $q")->fetchAll() as $c) {
                    if (stripos((string) $c['Extra'], 'GENERATED') === false) $cols[] = '`' . str_replace('`', '``', $c['Field']) . '`';
                }
                $head = "INSERT INTO $q (" . implode(',', $cols) . ') VALUES ';
                $n = 0;
                $batch = [];
                $st = $pdo->query('SELECT ' . implode(',', $cols) . " FROM $q");
                while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
                    $vals = [];
                    foreach ($r as $v) {
                        if ($v === null) $vals[] = 'NULL';
                        elseif (is_int($v) || is_float($v)) $vals[] = (string) $v;
                        else $vals[] = $pdo->quote((string) $v);
                    }
                    $batch[] = '(' . implode(',', $vals) . ')';
                    $n++;
                    if (count($batch) >= 200) { $w($out, $head . implode(",\n", $batch) . ";\n"); $batch = []; }
                }
                $st->closeCursor();
                if ($batch) $w($out, $head . implode(",\n", $batch) . ";\n");
                $w($out, "-- rows: $table=$n\n\n");
                $rows[$table] = $n;
            }
            $pdo->exec('COMMIT');
            $w($out, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- Dump completed on " . date('c') . "\n");
            $close($out);
            $out = null;

            self::validate($tmp, array_keys($rows));
            if (!@rename($tmp, $final)) throw new \RuntimeException('Não foi possível gravar o arquivo final do backup.');
            @chmod($final, 0600);
        } catch (\Throwable $e) {
            if ($out) { try { $close($out); } catch (\Throwable $x) {} }
            @unlink($tmp);
            throw $e;
        }

        self::rotate($final, $label);
        file_put_contents($dir . '/.last_success', date('c') . ' ' . $name . "\n");
        return ['file' => $final, 'size' => (int) filesize($final), 'rows' => $rows];
    }

    /** Confere integridade do arquivo e se o dump está completo. Lança exceção se algo estiver errado. */
    public static function validate(string $file, ?array $expectTables = null): array
    {
        $gz = (bool) preg_match('/\.gz$/', $file);
        if ($gz && !function_exists('gzopen')) throw new \RuntimeException('Extensão zlib ausente: não é possível ler .gz');
        $h = $gz ? gzopen($file, 'rb') : fopen($file, 'rb');
        if (!$h) throw new \RuntimeException('Não foi possível abrir o backup para validação.');
        $read = $gz ? 'gzgets' : 'fgets';
        $eof = $gz ? 'gzeof' : 'feof';
        $tables = [];
        $rows = [];
        $completed = false;
        while (!$eof($h)) {
            $line = $read($h, 1048576);
            if ($line === false) break;
            if (strncmp($line, 'CREATE TABLE ', 13) === 0 && preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) $tables[] = $m[1];
            elseif (strncmp($line, '-- rows: ', 9) === 0 && preg_match('/^-- rows: (.+)=(\d+)/', $line, $m)) $rows[$m[1]] = (int) $m[2];
            elseif (strncmp($line, '-- Dump completed', 17) === 0) $completed = true;
        }
        ($gz ? 'gzclose' : 'fclose')($h);
        if (!$completed) throw new \RuntimeException('Backup incompleto (sem o marcador final) ou arquivo corrompido.');
        if (!in_array('users', $tables, true)) throw new \RuntimeException('Backup sem a tabela users.');
        if ($expectTables !== null) {
            $missing = array_diff($expectTables, $tables);
            if ($missing) throw new \RuntimeException('Backup sem as tabelas: ' . implode(', ', $missing));
        }
        if ((int) filesize($file) < 1024) throw new \RuntimeException('Backup suspeito: arquivo pequeno demais.');
        return ['tables' => $tables, 'rows' => $rows];
    }

    /** Lista backups (mais recentes primeiro) */
    public static function list(): array
    {
        $files = glob(self::dir() . '/rotina_*.sql*') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files;
    }

    private static function rotate(string $final, string $label): void
    {
        $dir = dirname($final);
        $cfg = Config::get('backup');
        $prune = static function (array $files, int $keep): void {
            foreach (array_slice($files, max(0, $keep)) as $f) @unlink($f);
        };
        $byTime = static function (array $files): array { usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a)); return $files; };

        // cópia mensal (1º dia do mês) dentro de monthly/
        if ($label === '' && date('d') === '01') {
            @mkdir("$dir/monthly", 0770, true);
            @copy($final, "$dir/monthly/" . basename($final));
        }
        $daily = array_filter(glob("$dir/rotina_????????_??????.sql*") ?: [], fn($f) => true);
        $prune($byTime($daily), (int) $cfg['keep_daily']);
        $labeled = glob("$dir/rotina_????????_??????_*.sql*") ?: [];
        $prune($byTime($labeled), (int) $cfg['keep_labeled']);
        $monthly = glob("$dir/monthly/rotina_*.sql*") ?: [];
        $prune($byTime($monthly), (int) $cfg['keep_monthly']);
    }

    // ------------------------------------------------------------------ restauração
    /** Executa um arquivo .sql/.sql.gz (nosso ou exportado pelo phpMyAdmin) no banco configurado. */
    public static function load(string $file, ?PDO $pdo = null, ?callable $progress = null): int
    {
        $pdo = $pdo ?: Db::connect(Config::get('db'));
        $gz = (bool) preg_match('/\.gz$/', $file);
        $h = $gz ? gzopen($file, 'rb') : fopen($file, 'rb');
        if (!$h) throw new \RuntimeException('Não foi possível abrir o arquivo de backup.');
        $read = $gz ? 'gzgets' : 'fgets';
        $eof = $gz ? 'gzeof' : 'feof';
        $sp = new SqlSplitter();
        $count = 0;
        try {
            while (!$eof($h)) {
                $piece = $read($h, 1048576);
                if ($piece === false) break;
                foreach ($sp->feed($piece) as $stmt) { $pdo->exec($stmt); $count++; }
            }
            foreach ($sp->finish() as $stmt) { $pdo->exec($stmt); $count++; }
        } finally {
            ($gz ? 'gzclose' : 'fclose')($h);
        }
        return $count;
    }

    /**
     * Teste de restauração: carrega o backup num banco descartável e confere as contagens.
     * Se o usuário do MySQL não puder criar bancos (comum em hospedagem), devolve ['skipped'=>motivo].
     */
    public static function verifyRestore(string $file): array
    {
        $info = self::validate($file);
        $tmpName = 'rotina_verify_' . bin2hex(random_bytes(4));
        $admin = Db::connect(Config::get('db'));
        try {
            $admin->exec("CREATE DATABASE `$tmpName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            return ['ok' => true, 'level' => 'estrutura', 'skipped' => 'sem permissão para criar um banco temporário (' . Db::errmsg($e) . ')', 'tables' => count($info['tables'])];
        }
        try {
            $cfg = Config::get('db');
            $cfg['name'] = $tmpName;
            $pdo = Db::connect($cfg);
            self::load($file, $pdo);
            foreach ($info['rows'] as $table => $expected) {
                $got = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
                if ($got !== $expected) throw new \RuntimeException("Restauração divergente em $table: esperado $expected linhas, restaurou $got.");
            }
            return ['ok' => true, 'level' => 'restauração completa', 'tables' => count($info['tables'])];
        } finally {
            try { $admin->exec("DROP DATABASE IF EXISTS `$tmpName`"); } catch (\Throwable $e) { /* ignora */ }
        }
    }
}
