<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Garante o agendador do servidor (cron a cada minuto), para a redistribuição e os backups rodarem SEM ninguém logado.
 *
 * Tentativa "no melhor esforço" (nunca derruba nada): instala no crontab do USUÁRIO que roda o sistema (www-data etc.),
 * que não exige root. Quem tem root pode instalar o definitivo em /etc/cron.d com `sudo ./rotina cron-install`.
 * Chamado: ao concluir uma atualização (tela ou `./rotina update`) e, uma vez por dia no máximo, pelo uso normal do sistema
 * enquanto não houver sinal do agendador. Desligue com 'auto_cron' => false em config/config.php.
 */
final class Scheduler
{
    public const TAG = '# rotina-agendador';
    private const RETRY_SECONDS = 86400;

    private static function canExec(): bool
    {
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('exec') && !in_array('exec', $off, true);
    }

    private static function state(string $k): ?string
    {
        $v = Db::val('SELECT v FROM app_state WHERE k = ?', [$k]);
        return $v === null ? null : (string) $v;
    }

    /** O cron/worker deixou sinal de vida há menos de 3 min? */
    public static function alive(): bool
    {
        return time() - (int) self::state('sched_ts') < 180;
    }

    /**
     * @param bool $force ignora o intervalo de 1 tentativa por dia (usado na atualização)
     * @return string resultado em texto (para o log da atualização); '' = nada a fazer
     */
    public static function ensure(bool $force = false): string
    {
        if (Config::get('auto_cron') === false) return '';
        if (self::alive()) return '';
        if (!$force && time() - (int) self::state('sched_try') < self::RETRY_SECONDS) return '';
        Db::q('INSERT INTO app_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['sched_try', (string) time()]);

        if (DIRECTORY_SEPARATOR === '\\') return 'Agendador: Windows não suportado (use o Agendador de Tarefas para rodar "php bin/rotina.php cron" a cada minuto).';
        if (is_file('/etc/cron.d/rotina')) return 'Agendador: já instalado em /etc/cron.d/rotina.';
        if (!self::canExec()) return 'Agendador: o PHP não pode executar comandos aqui. No servidor, rode: sudo ./rotina cron-install';

        $o = []; $rc = 1;
        @exec('command -v crontab 2>/dev/null', $o, $rc);
        if ($rc !== 0 || empty($o[0])) return 'Agendador: "crontab" não encontrado. Instale o cron e rode: sudo ./rotina cron-install';

        $cur = []; @exec('crontab -l 2>/dev/null', $cur, $rc);
        if ($rc > 1) return 'Agendador: este usuário não pode usar o cron. No servidor, rode: sudo ./rotina cron-install';
        foreach ($cur as $l) if (strpos($l, self::TAG) !== false) return 'Agendador: já instalado no crontab do usuário (aguardando o primeiro sinal).';

        $wrapper = ROOT . '/rotina';
        if (!is_file($wrapper)) return 'Agendador: atalho ./rotina não encontrado.';
        $cur[] = '* * * * * ' . escapeshellarg($wrapper) . ' cron >/dev/null 2>&1 ' . self::TAG;
        $tmp = ROOT . '/storage/tmp/crontab.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, implode("\n", $cur) . "\n") === false) return 'Agendador: sem permissão para gravar em storage/tmp.';
        $out = []; @exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        @unlink($tmp);
        if ($rc !== 0) return 'Agendador: não consegui gravar o crontab (' . trim(implode(' ', $out)) . '). No servidor, rode: sudo ./rotina cron-install';

        Util::audit(null, 'SCHED_INSTALL', 'system', null, ['onde' => 'crontab do usuário do sistema'], null);
        return 'Agendador instalado no crontab do usuário (roda a cada minuto, sem depender de login).';
    }
}
