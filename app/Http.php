<?php
declare(strict_types=1);
namespace Rotina;

final class HttpError extends \Exception
{
    public int $status;
    public string $errCode;
    public function __construct(int $status, string $message, string $code = 'ERROR')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errCode = $code;
    }
}

final class Http
{
    public static function headers(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        if (Util::isHttps()) header('Strict-Transport-Security: max-age=31536000');
        header_remove('X-Powered-By');
    }

    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $body = Util::json($data);
        // Compressão: o painel baixa a lista inteira a cada mudança; em JSON isso encolhe ~10x. Só se o navegador aceita e o
        // servidor/PHP ainda não estiver comprimindo.
        if (strlen($body) > 1400 && function_exists('gzencode') && !headers_sent() && !ini_get('zlib.output_compression')
            && stripos((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false) {
            $z = gzencode($body, 5);
            if ($z !== false) { header('Content-Encoding: gzip'); header('Vary: Accept-Encoding'); $body = $z; }
        }
        echo $body;
    }

    /** Envia a resposta ao navegador já, e deixa o PHP continuar trabalhando (rotinas agendadas). */
    public static function finish(): void
    {
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
        if (!headers_sent() && ob_get_level() > 0) {
            header('Connection: close');
            header('Content-Length: ' . ob_get_length());
            while (ob_get_level() > 0) ob_end_flush();
            flush();
        }
    }

    public static function fail(int $status, string $message, ?string $code = null): void
    {
        self::json($code ? ['error' => $message, 'code' => $code] : ['error' => $message], $status);
    }

    /** Corpo JSON com limite de tamanho. */
    public static function body(int $maxBytes): array
    {
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > $maxBytes) throw new HttpError(413, 'Envio grande demais.');
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes) throw new HttpError(413, 'Envio grande demais.');
        if (trim($raw) === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new HttpError(400, 'JSON inválido.');
        return $data;
    }

    /** Limite de tentativas por chave (IP) numa janela de tempo; guarda no MySQL. */
    public static function rateLimit(string $key, int $limit, int $windowSec, string $msg): void
    {
        $key = substr($key, 0, 80);
        Db::q('INSERT INTO rate_limits (rk, hits, window_start) VALUES (?, 1, NOW())
               ON DUPLICATE KEY UPDATE
                 hits = IF(window_start < NOW() - INTERVAL ? SECOND, 1, hits + 1),
                 window_start = IF(window_start < NOW() - INTERVAL ? SECOND, NOW(), window_start)',
            [$key, $windowSec, $windowSec]);
        if ((int) Db::val('SELECT hits FROM rate_limits WHERE rk = ?', [$key]) > $limit) {
            throw new HttpError(429, $msg, 'RATE_LIMIT');
        }
    }
}
