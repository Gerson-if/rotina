<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Atualização segura, feita pelo CLI:   php bin/rotina.php update  novo-pacote.zip
 *
 *  1. pré-checagens e trava contra execução dupla
 *  2. valida o pacote (estrutura, sintaxe PHP, versão, integridade das migrations)
 *  3. backup "pre-update" + teste de restauração (se não passar, NADA é alterado)
 *  4. modo manutenção; guarda cópia do código atual em storage/releases/
 *  5. aplica migrations novas (aditivas: o código antigo continua compatível)
 *  6. troca o código; confere o resultado; se falhar, volta sozinho ao código anterior
 */
final class Updater
{
    private const DIRS = ['app', 'public', 'bin', 'migrations', 'deploy'];
    private const FILES = ['VERSION', 'install.sh', 'rotina', 'README.md', 'CHANGELOG.md', 'index.php', '.htaccess'];

    /** copy() não preserva o bit de execução dos atalhos */
    private static function fixModes(): void { foreach (['install.sh', 'rotina'] as $f) @chmod(ROOT . "/$f", 0755); }
    private const KEEP_RELEASES = 3;

    public static function maintenanceFlag(): string { return ROOT . '/storage/maintenance.flag'; }
    public static function maintenance(bool $on): void
    {
        if ($on) file_put_contents(self::maintenanceFlag(), date('c') . "\n"); else @unlink(self::maintenanceFlag());
    }

    private static function canExec(): bool
    {
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('exec') && !in_array('exec', $off, true);
    }

    /**
     * Caminho do PHP de LINHA DE COMANDO (php / php.exe), ou null se não achar.
     * Dentro do Apache (Windows/XAMPP) PHP_BINARY aponta para o httpd.exe, que NÃO serve para `php -l`.
     */
    private static ?string $cli = null;
    private static bool $cliSearched = false;
    private static function phpCli(): ?string
    {
        if (self::$cliSearched) return self::$cli;
        self::$cliSearched = true;
        $win = DIRECTORY_SEPARATOR === '\\';
        $exe = $win ? 'php.exe' : 'php';
        $c = [];
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') $c[] = PHP_BINARY;
        if (defined('PHP_BINDIR')) $c[] = PHP_BINDIR . DIRECTORY_SEPARATOR . $exe;
        if (PHP_BINARY !== '') $c[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $exe;
        $ini = php_ini_loaded_file();
        if ($ini) $c[] = dirname($ini) . DIRECTORY_SEPARATOR . $exe;
        $ext = (string) ini_get('extension_dir');
        if ($ext !== '') { $c[] = dirname($ext) . DIRECTORY_SEPARATOR . $exe; $c[] = dirname($ext, 2) . DIRECTORY_SEPARATOR . $exe; }
        if ($win) { foreach (['C:\\xampp\\php\\php.exe', 'C:\\php\\php.exe', 'C:\\wamp64\\bin\\php\\php.exe'] as $x) $c[] = $x; }
        $c[] = $exe;                                                   // está no PATH?
        foreach (array_unique($c) as $bin) {
            if ($bin !== $exe && !is_file($bin)) continue;
            $o = []; $rc = 1;
            @exec(escapeshellarg($bin) . ' -v 2>&1', $o, $rc);
            $first = (string) ($o[0] ?? '');
            if ($rc === 0 && strpos($first, 'PHP ') === 0 && stripos($first, '(cli)') !== false) return self::$cli = $bin;
        }
        return self::$cli = null;
    }

    private static function say(callable $log, string $m): void
    {
        $log($m);
        @file_put_contents(ROOT . '/storage/logs/update.log', '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n", FILE_APPEND);
    }

    /** Localiza a raiz do pacote (aceita pasta direta ou uma subpasta única, como em zips) */
    private static function packageRoot(string $dir): string
    {
        $ok = static fn(string $d) => is_file("$d/app/bootstrap.php") && is_file("$d/VERSION");
        if ($ok($dir)) return $dir;
        foreach (glob("$dir/*", GLOB_ONLYDIR) ?: [] as $sub) if ($ok($sub)) return $sub;
        throw new \RuntimeException('Pacote inválido: não encontrei app/bootstrap.php e VERSION.');
    }

    private static function extractZip(string $zip, string $to): void
    {
        if (!class_exists('ZipArchive')) throw new \RuntimeException('A extensão PHP "zip" não está instalada. Descompacte o pacote e informe a PASTA no lugar do .zip.');
        $z = new \ZipArchive();
        if ($z->open($zip) !== true) throw new \RuntimeException('Não foi possível abrir o .zip (arquivo corrompido?).');
        for ($i = 0; $i < $z->numFiles; $i++) {               // proteção contra "zip slip"
            $n = (string) $z->getNameIndex($i);
            if ($n[0] === '/' || strpos($n, '..') !== false || strpos($n, ':') !== false) {
                $z->close();
                throw new \RuntimeException("Pacote rejeitado: caminho suspeito ($n).");
            }
        }
        mkdir($to, 0775, true);
        if (!$z->extractTo($to)) { $z->close(); throw new \RuntimeException('Falha ao extrair o .zip.'); }
        $z->close();
    }

    private static function lint(string $dir, string $php): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (substr($f->getFilename(), -4) !== '.php') continue;
            $out = [];
            exec(escapeshellarg($php) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $rc);
            if ($rc !== 0) throw new \RuntimeException('Erro de sintaxe no pacote (' . substr($f->getPathname(), strlen($dir) + 1) . '): ' . implode(' ', $out));
        }
    }

    private static function swapIn(string $new, string $target, string $suffix, array &$undo): void
    {
        $old = $target . '.old_' . $suffix;
        if (file_exists($target) && !@rename($target, $old)) throw new \RuntimeException("Sem permissão para substituir $target (rode como o dono dos arquivos).");
        $undo[] = [$target, $old];                           // registra ANTES de mover: qualquer falha pode ser desfeita
        if (!@rename($new, $target)) {
            Util::copyDir($new, $target);                    // fallback: outro sistema de arquivos
        }
    }

    private static function revert(array $undo): void
    {
        foreach (array_reverse($undo) as [$target, $old]) {
            Util::rmDir($target);                            // remove a versão nova (ou parcial)
            if (file_exists($old)) @rename($old, $target);   // devolve a anterior (se havia)
        }
    }

    // =========================================================================================
    public static function run(string $source, bool $force, callable $log): void
    {
        foreach (['Util', 'Config', 'Db', 'Migrator', 'Backup', 'SqlSplitter'] as $c) class_exists('Rotina\\' . $c);   // carrega antes da troca

        // ---- 1. pré-checagens ----
        if (!Config::installed()) throw new \RuntimeException('Sistema não instalado.');
        if (Config::problems()) throw new \RuntimeException('Configuração inválida: ' . implode('; ', Config::problems()));
        foreach ([ROOT, ROOT . '/storage'] as $d) if (!is_writable($d)) throw new \RuntimeException("Sem permissão de escrita em $d. Rode o comando com o usuário dono dos arquivos (ex.: sudo -u www-data php bin/rotina.php update ...).");
        if ((float) disk_free_space(ROOT) < 100 * 1048576) throw new \RuntimeException('Pouco espaço em disco (menos de 100 MB livres).');
        try { Db::pdo(); } catch (\Throwable $e) { throw new \RuntimeException('Sem conexão com o banco: ' . $e->getMessage()); }

        @mkdir(ROOT . '/storage/logs', 0775, true);
        $lock = fopen(ROOT . '/storage/update.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Já existe uma atualização em andamento.');

        $ts = date('Ymd_His');
        $tmp = ROOT . "/storage/tmp/update_$ts";
        $maintenanceOn = false;
        $undo = [];
        try {
            $current = Config::version();
            self::say($log, "Versão atual: $current");

            // ---- 2. pacote ----
            if (is_dir($source)) { $pkg = self::packageRoot($source); }
            elseif (is_file($source)) {
                self::say($log, 'Extraindo ' . basename($source) . ' ...');
                self::extractZip($source, $tmp);
                $pkg = self::packageRoot($tmp);
            } else throw new \RuntimeException("Pacote não encontrado: $source");

            foreach (['app/Api.php', 'public/api.php', 'public/index.html', 'bin/rotina.php', 'migrations'] as $req) {
                if (!file_exists("$pkg/$req")) throw new \RuntimeException("Pacote incompleto: falta $req");
            }
            $new = trim((string) file_get_contents("$pkg/VERSION"));
            if (!preg_match('/^\d+\.\d+\.\d+([-+.\w]*)$/', $new)) throw new \RuntimeException("VERSION inválida no pacote: $new");
            $cmp = version_compare($new, $current);
            if ($cmp === 0 && !$force) throw new \RuntimeException("O pacote é da mesma versão ($new). Use --force para reaplicar.");
            if ($cmp < 0 && !$force) throw new \RuntimeException("O pacote ($new) é mais antigo que o instalado ($current). Use rollback, ou --force.");
            self::say($log, "Nova versão: $new");

            if (self::canExec() && ($cli = self::phpCli())) { self::say($log, 'Verificando sintaxe PHP do pacote ...'); self::lint($pkg, $cli); }
            else self::say($log, 'AVISO: o PHP de linha de comando não está acessível (ou exec() está desativado); a verificação de sintaxe do pacote foi pulada.');
            if ($errs = Migrator::checkIntegrity("$pkg/migrations")) throw new \RuntimeException('Pacote incompatível com o banco: migration(s) ' . implode('; ', $errs) . '. Nunca edite migrations já aplicadas.');
            $pending = Migrator::status("$pkg/migrations")['pending'];
            self::say($log, $pending ? 'Migrations novas: ' . implode(', ', $pending) : 'Sem migrations novas.');

            // ---- 3. backup + teste de restauração ----
            self::say($log, 'Fazendo backup do banco (pre-update) ...');
            $bk = Backup::create('pre-update');
            self::say($log, 'Backup: ' . basename($bk['file']) . ' (' . Util::human($bk['size']) . ')');
            $v = Backup::verifyRestore($bk['file']);
            self::say($log, 'Teste do backup: ' . $v['level'] . (isset($v['skipped']) ? ' — restauração completa pulada: ' . $v['skipped'] : ' OK'));

            // ---- 4. manutenção + cópia do código atual ----
            self::maintenance(true);
            $maintenanceOn = true;
            self::say($log, 'Modo manutenção LIGADO.');
            $rel = ROOT . "/storage/releases/{$ts}_v$current";
            foreach (self::DIRS as $d) if (is_dir(ROOT . "/$d")) Util::copyDir(ROOT . "/$d", "$rel/$d");
            foreach (self::FILES as $f) if (is_file(ROOT . "/$f")) { @mkdir($rel, 0775, true); copy(ROOT . "/$f", "$rel/$f"); }
            self::say($log, 'Código atual guardado em storage/releases/' . basename($rel));

            // ---- 5. migrations (com o código NOVO; o antigo continua válido pois são aditivas) ----
            if ($pending) {
                $applied = Migrator::run("$pkg/migrations", fn($m) => self::say($log, "  $m"));
                self::say($log, count($applied) . ' migration(s) aplicada(s).');
            }

            // ---- 6. troca do código ----
            if (is_file(ROOT . '/config/installed.lock')) @unlink("$pkg/public/install.php");   // instalador não volta depois de instalado
            foreach (self::DIRS as $d) if (is_dir("$pkg/$d")) self::swapIn("$pkg/$d", ROOT . "/$d", $ts, $undo);
            foreach (self::FILES as $f) if (is_file("$pkg/$f")) copy("$pkg/$f", ROOT . "/$f");
            self::fixModes();
            foreach (['config.sample.php', '.htaccess'] as $f) if (is_file("$pkg/config/$f")) copy("$pkg/config/$f", ROOT . "/config/$f");
            self::say($log, 'Código substituído.');

            // ---- conferência; se falhar, volta ----
            $problem = self::postCheck($new);
            if ($problem !== null) {
                self::say($log, "FALHA na conferência: $problem — voltando ao código anterior ...");
                self::revert($undo);
                foreach (self::FILES as $f) if (is_file("$rel/$f")) copy("$rel/$f", ROOT . "/$f");
                self::fixModes();
                self::maintenance(false);
                $maintenanceOn = false;
                throw new \RuntimeException("A nova versão não passou na conferência ($problem). O código anterior foi restaurado. As migrations aplicadas são aditivas e não atrapalham a versão antiga. Backup dos dados: " . basename($bk['file']));
            }

            // ---- sucesso ----
            foreach ($undo as [, $old]) Util::rmDir($old);
            self::maintenance(false);
            $maintenanceOn = false;
            self::prune();
            self::say($log, "Atualização concluída: $current → $new. Modo manutenção DESLIGADO.");
        } catch (\Throwable $e) {
            if ($undo && $maintenanceOn) {
                // erro no meio da troca de pastas: desfaz o que já foi trocado
                self::revert($undo);
            }
            if ($maintenanceOn) { self::maintenance(false); self::say($log, 'Modo manutenção DESLIGADO (atualização abortada).'); }
            throw $e;
        } finally {
            Util::rmDir($tmp);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Roda o `status` do código NOVO em outro processo. @return string|null motivo da falha */
    private static function postCheck(string $expectVersion): ?string
    {
        if (!self::canExec() || !self::phpCli()) {
            return is_file(ROOT . '/app/bootstrap.php') && is_file(ROOT . '/public/api.php') ? null : 'arquivos essenciais ausentes';
        }
        $out = [];
        exec(escapeshellarg((string) self::phpCli()) . ' ' . escapeshellarg(ROOT . '/bin/rotina.php') . ' status --quiet --expect=' . escapeshellarg($expectVersion) . ' 2>&1', $out, $rc);
        if ($rc !== 0) return trim(implode(' ', $out)) ?: "status retornou código $rc";
        return null;
    }

    private static function prune(): void
    {
        $r = self::releases();
        foreach (array_slice($r, self::KEEP_RELEASES) as $d) Util::rmDir($d);
    }

    /** Cópias de código guardadas, da mais recente para a mais antiga */
    public static function releases(): array
    {
        $d = glob(ROOT . '/storage/releases/*', GLOB_ONLYDIR) ?: [];
        rsort($d, SORT_STRING);
        return $d;
    }

    // =========================================================================================
    /** Volta o CÓDIGO para uma cópia guardada (os dados do banco não são tocados). */
    public static function rollback(?string $which, callable $log): void
    {
        foreach (['Util', 'Config'] as $c) class_exists('Rotina\\' . $c);
        $all = self::releases();
        if (!$all) throw new \RuntimeException('Não há versões anteriores guardadas em storage/releases/.');
        $rel = $all[0];
        if ($which) {
            $rel = null;
            foreach ($all as $d) if (basename($d) === $which || strpos(basename($d), $which) !== false) { $rel = $d; break; }
            if (!$rel) throw new \RuntimeException("Versão '$which' não encontrada. Disponíveis: " . implode(', ', array_map('basename', $all)));
        }
        if (!is_file("$rel/app/bootstrap.php")) throw new \RuntimeException('A cópia escolhida está incompleta.');
        foreach ([ROOT, ROOT . '/storage'] as $d) if (!is_writable($d)) throw new \RuntimeException("Sem permissão de escrita em $d.");

        $ts = date('Ymd_His');
        $undo = [];
        self::maintenance(true);
        try {
            self::say($log, 'Voltando para ' . basename($rel) . ' ...');
            $tmp = ROOT . "/storage/tmp/rollback_$ts";
            foreach (self::DIRS as $d) if (is_dir("$rel/$d")) Util::copyDir("$rel/$d", "$tmp/$d");
            foreach (self::DIRS as $d) if (is_dir("$tmp/$d")) self::swapIn("$tmp/$d", ROOT . "/$d", $ts, $undo);
            foreach (self::FILES as $f) if (is_file("$rel/$f")) copy("$rel/$f", ROOT . "/$f");
            self::fixModes();
            if (is_file(ROOT . '/config/installed.lock')) @unlink(ROOT . '/public/install.php');
            foreach ($undo as [, $old]) Util::rmDir($old);
            Util::rmDir($tmp);
        } catch (\Throwable $e) {
            self::revert($undo);
            self::maintenance(false);
            throw $e;
        }
        self::maintenance(false);
        self::say($log, 'Código restaurado. Modo manutenção DESLIGADO. (Os dados do banco não foram alterados.)');
    }
}
