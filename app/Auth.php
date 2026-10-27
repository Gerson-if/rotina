<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Sessão por cookie httpOnly + SameSite=Strict, assinado com HMAC-SHA256 (equivalente ao JWT anterior,
 * sem arquivos de sessão no servidor). A cada requisição o usuário é relido do banco: bloqueio,
 * rebaixamento ou troca de senha (token_version) valem imediatamente.
 */
final class Auth
{
    public const COOKIE = 'rotina_session';

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function unb64(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/')); }

    private static function cookiePath(): string
    {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $dir === '' ? '/' : $dir . '/';
    }

    private static function cookieOpts(int $expires): array
    {
        $mode = (string) Config::get('cookie_secure', 'auto');
        $secure = $mode === 'true' ? true : ($mode === 'false' ? false : Util::isHttps());
        return ['expires' => $expires, 'path' => self::cookiePath(), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'];
    }

    public static function sign(array $payload): string
    {
        $body = self::b64(Util::json($payload));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, (string) Config::get('secret'), true));
    }

    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        $expected = self::b64(hash_hmac('sha256', $parts[0], (string) Config::get('secret'), true));
        if (!hash_equals($expected, $parts[1])) return null;
        $p = json_decode(self::unb64($parts[0]), true);
        if (!is_array($p) || ($p['exp'] ?? 0) < time()) return null;
        return $p;
    }

    public static function issue(array $user): void
    {
        $exp = time() + (int) Config::get('session_hours', 12) * 3600;
        $token = self::sign(['sub' => $user['id'], 'tv' => (int) $user['token_version'], 'exp' => $exp]);
        setcookie(self::COOKIE, $token, self::cookieOpts($exp));
    }

    public static function clear(): void
    {
        setcookie(self::COOKIE, '', self::cookieOpts(time() - 86400));
    }

    /** Defesa CSRF adicional ao SameSite=Strict: exige cabeçalho customizado em escrita. */
    public static function csrfGuard(): void
    {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) return;
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'rotina-web') {
            throw new HttpError(403, 'Requisição bloqueada (CSRF).');
        }
    }

    public static function require(): array
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($token === '') throw new HttpError(401, 'Não autenticado.');
        $p = self::verify($token);
        if (!$p) throw new HttpError(401, 'Sessão expirada.');
        $u = Db::one('SELECT id, name, email, role, shift, status, token_version, avatar_v FROM users WHERE id = ?', [$p['sub']]);
        if (!$u || $u['status'] !== 'APROVADO' || (int) $u['token_version'] !== (int) $p['tv']) {
            self::clear();
            throw new HttpError(401, 'Sessão inválida.');
        }
        return $u;
    }

    public static function requireRole(array $user, string $role): void
    {
        if ($user['role'] !== $role) throw new HttpError(403, 'Acesso restrito.');
    }
}
