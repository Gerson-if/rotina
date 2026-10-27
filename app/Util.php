<?php
declare(strict_types=1);
namespace Rotina;

final class Util
{
    // Hash válido (bcrypt, custo 12) usado só para igualar o tempo de resposta quando o e-mail não existe
    public const DUMMY_HASH = '$2y$12$D2e.MqG1gf4cO0og5.Zv4O/enYkkjzTjIaxR3fIsZGnce/lVoF5p.';
    public const ID_RE = '/^[A-Za-z0-9_-]{1,40}$/';

    public static function newId(string $prefix): string
    {
        return $prefix . '-' . substr(bin2hex(random_bytes(10)), 0, 20);
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => (int) Config::get('bcrypt_cost', 12)]);
    }

    public static function len(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }
    public static function lower(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }
    public static function cut(string $s, int $n): string { return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); }

    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (Config::get('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        $ip = str_replace('::ffff:', '', $ip);
        return substr(filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '', 0, 45);
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        return (bool) Config::get('trust_proxy')
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** Auditoria nunca derruba a operação principal */
    public static function audit(?string $userId, string $event, ?string $entity = null, ?string $entityId = null, $details = null, ?string $ip = null): void
    {
        try {
            Db::q('INSERT INTO audit_log (user_id, event, entity, entity_id, details, ip) VALUES (?,?,?,?,?,?)',
                [$userId, $event, $entity, $entityId, $details === null ? null : self::json($details), $ip]);
        } catch (\Throwable $e) {
            self::log('audit', 'falha ao gravar: ' . $e->getMessage());
        }
    }

    public static function json($v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public static function log(string $tag, string $msg): void
    {
        $dir = ROOT . '/storage/logs';
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents("$dir/app.log", sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $tag, $msg), FILE_APPEND | LOCK_EX);
        } else {
            error_log("[rotina:$tag] $msg");
        }
    }

    // ---------- arquivos ----------
    public static function copyDir(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) throw new \RuntimeException("Não foi possível criar $to");
        foreach (new \DirectoryIterator($from) as $f) {
            if ($f->isDot()) continue;
            $dst = $to . '/' . $f->getFilename();
            if ($f->isDir() && !$f->isLink()) self::copyDir($f->getPathname(), $dst);
            else copy($f->getPathname(), $dst);
        }
    }

    public static function rmDir(string $dir): void
    {
        if (!file_exists($dir) && !is_link($dir)) return;
        if (is_link($dir) || is_file($dir)) { @unlink($dir); return; }
        foreach (new \DirectoryIterator($dir) as $f) {
            if ($f->isDot()) continue;
            if ($f->isDir() && !$f->isLink()) self::rmDir($f->getPathname()); else @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    public static function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $u) { if ($bytes < 1024 || $u === 'GB') return round($bytes, 1) . " $u"; $bytes /= 1024; }
        return '';
    }
}
