<?php
declare(strict_types=1);
namespace Rotina;

/** Backup, restauração e atualização pela tela do administrador (aba "Sistema"). */
final class System
{
    private static function iniBytes(string $k): int
    {
        $v = trim((string) ini_get($k));
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; // no break
            case 'm': $n *= 1024; // no break
            case 'k': $n *= 1024; }
        return $n;
    }

    private static function backupInfo(string $f): array
    {
        $name = basename($f);
        $label = preg_match('/^rotina_\d{8}_\d{6}_([A-Za-z0-9_-]+)\.sql/', $name, $m) ? $m[1] : '';
        return ['name' => $name, 'size' => (int) filesize($f), 'sizeHuman' => Util::human((int) filesize($f)),
                'at' => date('c', (int) filemtime($f)), 'label' => $label];
    }

    /** Testa a conexão com o banco usando a configuração gravada (abre uma conexão NOVA, não reaproveita a do sistema). */
    public static function dbTest(): array
    {
        $db = (array) Config::get('db');
        $info = ['host' => (string) ($db['host'] ?? ''), 'port' => (int) ($db['port'] ?? 3306), 'name' => (string) ($db['name'] ?? ''), 'user' => (string) ($db['user'] ?? '')];
        $t0 = microtime(true);
        try {
            $pdo = Db::connect($db);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
            $m = Migrator::status();
            return $info + ['ok' => true, 'message' => 'Conexão OK. O banco existe e está acessível.', 'latencyMs' => $ms, 'serverVersion' => $ver,
                            'tables' => $tables, 'pendingMigrations' => count($m['pending']), 'appliedMigrations' => (int) $m['applied']];
        } catch (\Throwable $e) {
            $code = Db::errno($e);
            $why = $code === 1045 ? 'Usuário ou senha do banco incorretos (confira config/config.php).'
                 : ($code === 1049 ? "O banco '{$info['name']}' não existe no servidor."
                 : ($code === 1044 ? "O usuário '{$info['user']}' não tem acesso ao banco '{$info['name']}'."
                 : ($code === 2002 || $code === 2006 ? 'O servidor de banco não respondeu (está ligado? host/porta/socket corretos?).'
                 : 'Não foi possível conectar.')));
            return $info + ['ok' => false, 'message' => $why, 'detail' => Db::errmsg($e), 'latencyMs' => (int) round((microtime(true) - $t0) * 1000)];
        }
    }

    public static function status(): array
    {
        $m = Migrator::status();
        $last = @file_get_contents(Backup::dir() . '/.last_success');
        $limit = min(self::iniBytes('upload_max_filesize') ?: PHP_INT_MAX, self::iniBytes('post_max_size') ?: PHP_INT_MAX);
        return [
            'version' => Config::version(),
            'pendingMigrations' => count($m['pending']),
            'maintenance' => is_file(Updater::maintenanceFlag()),
            'lastBackup' => $last ? trim($last) : null,
            'backups' => array_map([self::class, 'backupInfo'], Backup::list()),
            'releases' => array_map('basename', Updater::releases()),
            'canUpdate' => class_exists('ZipArchive') && is_writable(ROOT) && is_writable(ROOT . '/storage'),
            'updateWhy' => !class_exists('ZipArchive') ? 'A extensão PHP "zip" não está instalada no servidor.'
                : ((is_writable(ROOT) && is_writable(ROOT . '/storage')) ? '' : 'O servidor web não tem permissão para gravar nas pastas do sistema.'),
            'uploadLimit' => $limit === PHP_INT_MAX ? null : Util::human($limit),
            'uploadLimitBytes' => $limit === PHP_INT_MAX ? null : $limit,
        ];
    }

    public static function createBackup(): array
    {
        @set_time_limit(300);
        $r = Backup::create('manual');
        return self::backupInfo($r['file']);
    }

    /** Caminho de um backup existente (só aceita nomes que o próprio sistema lista). */
    public static function backupPath(string $name): string
    {
        foreach (Backup::list() as $f) if (basename($f) === $name) return $f;
        throw new HttpError(404, 'Backup não encontrado.');
    }

    /** Exclui um backup (e a cópia mensal de mesmo nome, se houver). */
    public static function deleteBackup(string $name): array
    {
        $f = self::backupPath($name);
        $size = (int) filesize($f);
        if (!@unlink($f)) throw new HttpError(500, 'Não consegui excluir o arquivo (permissão negada em storage/backups).');
        @unlink(dirname($f) . '/monthly/' . basename($f));
        return ['deleted' => 1, 'freed' => Util::human($size)];
    }

    /** Mantém só os $keep backups mais recentes e exclui o restante. */
    public static function pruneBackups(int $keep): array
    {
        if ($keep < 1) throw new HttpError(400, 'Mantenha pelo menos 1 backup.');
        $files = Backup::list();
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        $n = 0; $bytes = 0;
        foreach (array_slice($files, $keep) as $f) {
            $bytes += (int) @filesize($f);
            if (@unlink($f)) { $n++; @unlink(dirname($f) . '/monthly/' . basename($f)); }
        }
        return ['deleted' => $n, 'freed' => Util::human($bytes), 'kept' => min($keep, count($files))];
    }

    public static function download(string $name): void
    {
        $f = self::backupPath($name);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        header('Content-Length: ' . filesize($f));
        header('Cache-Control: no-store');
        readfile($f);
    }

    /** @return string[] mensagens do processo */
    public static function restore(string $name): array
    {
        @set_time_limit(600);
        ignore_user_abort(true);
        $f = self::backupPath($name);
        $log = [];
        try { Backup::validate($f); } catch (\Throwable $e) { throw new HttpError(422, 'Backup inválido: ' . $e->getMessage()); }
        $log[] = 'Guardando o estado atual (pre-restore)...';
        $pre = Backup::create('pre-restore');
        Updater::maintenance(true);
        try {
            $log[] = 'Restaurando ' . basename($f) . '...';
            Backup::load($f);
        } catch (\Throwable $e) {
            try { Backup::load($pre['file']); $back = 'O estado anterior foi restaurado.'; }
            catch (\Throwable $e2) { $back = 'Não consegui voltar sozinho; restaure manualmente ' . basename($pre['file']) . '.'; }
            Updater::maintenance(false);
            throw new HttpError(500, 'A restauração falhou (' . $e->getMessage() . '). ' . $back);
        }
        Updater::maintenance(false);
        $log[] = 'Restauração concluída. Cópia do estado anterior: ' . basename($pre['file']);
        return $log;
    }

    /** Recebe o .zip enviado pelo navegador e aplica a atualização segura. @return array{log:string[],version:string} */
    public static function update(array $file, bool $force): array
    {
        @set_time_limit(900);
        ignore_user_abort(true);
        if (!class_exists('ZipArchive')) throw new HttpError(422, 'O servidor não tem a extensão PHP "zip". Peça para instalar (apt install php-zip) ou atualize pelo terminal.');
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new HttpError(413, 'O arquivo é maior que o limite do servidor (upload_max_filesize).');
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) throw new HttpError(400, 'Nenhum arquivo recebido. Escolha o .zip da nova versão.');
        if (!preg_match('/\.zip$/i', (string) ($file['name'] ?? ''))) throw new HttpError(400, 'Envie o pacote no formato .zip.');

        $dir = ROOT . '/storage/tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) throw new HttpError(500, 'Não consegui criar storage/tmp.');
        $dest = "$dir/upload_" . bin2hex(random_bytes(4)) . '.zip';
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) throw new HttpError(500, 'Não consegui guardar o arquivo enviado.');
        $log = [];
        try {
            Updater::run($dest, $force, static function (string $m) use (&$log): void { $log[] = $m; });
        } catch (\RuntimeException $e) {
            throw new HttpError(422, $e->getMessage());
        } finally {
            @unlink($dest);
        }
        return ['log' => $log, 'version' => Config::version()];
    }

    /** @return string[] */
    public static function rollback(): array
    {
        $log = [];
        try {
            Updater::rollback(null, static function (string $m) use (&$log): void { $log[] = $m; });
        } catch (\RuntimeException $e) {
            throw new HttpError(422, $e->getMessage());
        }
        return $log;
    }
}
