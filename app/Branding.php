<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Plano de fundo da tela de login (imagem OU vídeo), escolhido pelo ADM.
 *  - O arquivo fica em storage/branding/ (fora da pasta pública) e é entregue por api.php, com suporte a "Range"
 *    (o Safari/iPhone só toca vídeo se o servidor aceitar pedidos por trecho).
 *  - O envio é feito em PEDAÇOS de 1 MB: assim não esbarra em upload_max_filesize / post_max_size do PHP nem em client_max_body_size do Nginx.
 *  - O formato é conferido pelo CONTEÚDO do arquivo (não pelo nome nem pelo tipo informado pelo navegador).
 *  - Os dados ficam em app_state (chave login_bg); sem migration.
 */
final class Branding
{
    private const KEY = 'login_bg';
    public const CHUNK = 1024 * 1024;              // tamanho de cada pedaço enviado
    public const MAX_VIDEO = 40 * 1024 * 1024;
    public const MAX_IMAGE = 6 * 1024 * 1024;      // o navegador já reduz a imagem antes de enviar

    private static function dir(): string
    {
        $d = ROOT . '/storage/branding';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    private static function part(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) throw new HttpError(400, 'Identificador de envio inválido.');
        return ROOT . "/storage/tmp/brand-$id.part";
    }

    /** Dados do plano de fundo atual (ou null). Chaves curtas: o valor cabe em app_state.v (255). */
    public static function meta(): ?array
    {
        $raw = Db::val('SELECT v FROM app_state WHERE k = ?', [self::KEY]);
        if (!is_string($raw) || $raw === '') return null;
        $m = json_decode($raw, true);
        if (!is_array($m) || !isset($m['f'], $m['k'], $m['v'])) return null;
        if (!is_file(self::dir() . '/' . basename((string) $m['f']))) return null;   // arquivo sumiu (ex.: restauração): volta ao padrão
        return $m;
    }

    /** Público (a tela de login ainda não tem sessão): só o necessário para montar o fundo. */
    public static function publicInfo(): array
    {
        $m = self::meta();
        return $m ? ['kind' => $m['k'], 'v' => (int) $m['v']] : ['kind' => null, 'v' => 0];
    }

    /** ADM: detalhes e limites para a tela do Sistema. */
    public static function adminInfo(): array
    {
        $m = self::meta();
        return [
            'current' => $m ? ['kind' => $m['k'], 'v' => (int) $m['v'], 'name' => $m['n'] ?? '', 'size' => (int) $m['s'], 'sizeHuman' => Util::human((int) $m['s']), 'at' => $m['at'] ?? null] : null,
            'chunkBytes' => self::CHUNK, 'maxVideo' => self::MAX_VIDEO, 'maxImage' => self::MAX_IMAGE,
            'maxVideoHuman' => Util::human(self::MAX_VIDEO),
        ];
    }

    /** Recebe UM pedaço (corpo cru). offset = quantos bytes já chegaram; fora de ordem é recusado. */
    public static function chunk(string $id, int $offset): array
    {
        $part = self::part($id);
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len <= 0) throw new HttpError(400, 'Pedaço vazio.');
        if ($len > self::CHUNK + 1024) throw new HttpError(413, 'Pedaço grande demais.');
        clearstatcache(true, $part);
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($offset === 0 && $have > 0) { @unlink($part); $have = 0; }   // recomeço do envio
        if ($offset !== $have) throw new HttpError(409, 'Pedaço fora de ordem. Recomece o envio.', 'CHUNK_ORDER');
        $data = file_get_contents('php://input', false, null, 0, self::CHUNK + 1025);
        if ($data === false || $data === '' || strlen($data) > self::CHUNK + 1024) throw new HttpError(400, 'Pedaço inválido.');
        if ($have + strlen($data) > self::MAX_VIDEO) { @unlink($part); throw new HttpError(413, 'O arquivo passa do limite de ' . Util::human(self::MAX_VIDEO) . '.'); }
        if (@file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) throw new HttpError(500, 'Não foi possível gravar o arquivo (permissão em storage/tmp).');
        return ['received' => $have + strlen($data)];
    }

    /** Fecha o envio: confere o formato pelo conteúdo, troca o plano de fundo e apaga o anterior. */
    public static function finish(string $id, string $name): array
    {
        $part = self::part($id);
        clearstatcache(true, $part);
        if (!is_file($part)) throw new HttpError(400, 'Envio não encontrado. Tente de novo.');
        $size = (int) filesize($part);
        $fh = fopen($part, 'rb'); $head = (string) fread($fh, 16); fclose($fh);

        $kind = null; $mime = null; $ext = null;
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') { $kind = 'video'; $mime = 'video/mp4'; $ext = 'mp4'; }
        elseif (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) { $kind = 'video'; $mime = 'video/webm'; $ext = 'webm'; }
        else {
            $info = @getimagesize($part);
            $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if ($info && isset($types[$info['mime']]) && $info[0] <= 6000 && $info[1] <= 6000) { $kind = 'image'; $mime = $info['mime']; $ext = $types[$mime]; }
        }
        if ($kind === null) { @unlink($part); throw new HttpError(400, 'Formato não suportado. Use imagem JPG, PNG ou WebP, ou vídeo MP4 ou WebM.'); }
        if ($kind === 'image' && $size > self::MAX_IMAGE) { @unlink($part); throw new HttpError(413, 'Imagem grande demais (máx. ' . Util::human(self::MAX_IMAGE) . ').'); }
        if ($kind === 'video' && $size > self::MAX_VIDEO) { @unlink($part); throw new HttpError(413, 'Vídeo grande demais (máx. ' . Util::human(self::MAX_VIDEO) . ').'); }

        $v = time();
        $file = "login-bg-$v.$ext";
        $dest = self::dir() . '/' . $file;
        if (!@rename($part, $dest)) {
            if (!@copy($part, $dest)) { @unlink($part); throw new HttpError(500, 'Não foi possível gravar em storage/branding (permissão da pasta).'); }
            @unlink($part);
        }
        @chmod($dest, 0644);

        $clean = trim((string) preg_replace('/[^\p{L}\p{N} ._()\-]/u', '', $name));
        $old = self::meta();
        $meta = ['k' => $kind, 'm' => $mime, 'f' => $file, 's' => $size, 'n' => Util::cut($clean, 60), 'v' => $v, 'at' => date('Y-m-d H:i')];
        Db::q('INSERT INTO app_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [self::KEY, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
        if ($old && basename((string) $old['f']) !== $file) @unlink(self::dir() . '/' . basename((string) $old['f']));
        return self::adminInfo();
    }

    /** Volta ao visual padrão: apaga o arquivo e o registro. */
    public static function remove(): array
    {
        $m = self::meta();
        if ($m) @unlink(self::dir() . '/' . basename((string) $m['f']));
        foreach (glob(self::dir() . '/login-bg-*') ?: [] as $f) @unlink($f);   // qualquer sobra
        Db::q('DELETE FROM app_state WHERE k = ?', [self::KEY]);
        return self::adminInfo();
    }

    /** Pedaços de envios abandonados (mais de 1 dia): rotina de manutenção. */
    public static function cleanStale(): int
    {
        $n = 0;
        foreach (glob(ROOT . '/storage/tmp/brand-*.part') ?: [] as $f) {
            if (@filemtime($f) < time() - 86400 && @unlink($f)) $n++;
        }
        return $n;
    }

    /** Entrega o arquivo (sem login: a tela de login precisa dele), com suporte a Range. */
    public static function serve(): void
    {
        $m = self::meta();
        if (!$m) throw new HttpError(404, 'Sem plano de fundo.');
        $path = self::dir() . '/' . basename((string) $m['f']);
        $size = (int) filesize($path);
        if ($size <= 0) throw new HttpError(404, 'Sem plano de fundo.');
        @set_time_limit(0);

        $start = 0; $end = $size - 1; $status = 200;
        $rng = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
        if ($rng !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $rng, $r) && ($r[1] !== '' || $r[2] !== '')) {
            if ($r[1] === '') { $start = max(0, $size - min((int) $r[2], $size)); }   // "últimos N bytes"
            else { $start = (int) $r[1]; if ($r[2] !== '') $end = min((int) $r[2], $size - 1); }
            if ($start > $end || $start >= $size) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                return;
            }
            $status = 206;
            header("Content-Range: bytes $start-$end/$size");
        }
        $len = $end - $start + 1;
        header('Content-Type: ' . $m['m']);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $len);
        header('Content-Disposition: inline');
        // a URL leva ?v=<versão>: com a versão certa pode ficar em cache por muito tempo; trocou o fundo, a versão muda
        header('Cache-Control: ' . (((string) ($_GET['v'] ?? '')) === (string) $m['v'] ? 'public, max-age=31536000, immutable' : 'no-cache'));
        http_response_code($status);
        while (ob_get_level() > 0) @ob_end_clean();
        $fh = fopen($path, 'rb');
        if ($fh === false) return;
        fseek($fh, $start);
        $left = $len;
        while ($left > 0 && !feof($fh)) {
            $buf = fread($fh, min(65536, $left));
            if ($buf === false || $buf === '') break;
            echo $buf;
            $left -= strlen($buf);
            flush();
            if (connection_aborted()) break;
        }
        fclose($fh);
    }
}
