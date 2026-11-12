<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Rotinas agendadas (a redistribuição de pendências roda a cada ~1 min):
 *   - métricas do dia a cada 30 min
 *   - após 00:10: fecha métricas de ontem + manutenção (retenção da auditoria, conjuntos órfãos, destravar contas)
 *   - backup diário depois do horário configurado
 * Quem dispara runDue():
 *   - O AGENDADOR DO SERVIDOR (recomendado, não depende de ninguém logado): `./rotina cron-install` (cron a cada minuto)
 *     ou `php bin/rotina.php worker` (serviço contínuo, veja deploy/rotina-worker.service);
 *   - "de carona" no uso do sistema (depois de responder ao usuário), enquanto houver acessos.
 * Cada rotina usa GET_LOCK: execuções simultâneas não duplicam trabalho.
 */
final class Jobs
{
    private static function state(string $k): ?string
    {
        $v = Db::val('SELECT v FROM app_state WHERE k = ?', [$k]);
        return $v === null ? null : (string) $v;
    }
    private static function setState(string $k, string $v): void
    {
        Db::q('INSERT INTO app_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }

    private static function locked(string $name, callable $fn): void
    {
        if (!Db::lock("rotina_job_$name", 0)) return;
        try { $fn(); } finally { Db::unlock("rotina_job_$name"); }
    }

    /** Roda uma rotina isolada: se ela falhar, registra no log e as demais seguem (uma não trava a outra). */
    private static function safe(string $tag, callable $fn): void
    {
        try { $fn(); } catch (\Throwable $e) { Util::log($tag, $e->getMessage()); }
    }

    /**
     * Marca "o agendador do servidor (cron ou worker) está vivo". A tela da Redistribuição usa isso para avisar o ADM
     * quando ninguém está disparando as rotinas (nesse caso elas só rodam com alguém usando o sistema).
     */
    public static function heartbeat(): void
    {
        self::setState('sched_ts', (string) time());
    }

    public static function runDue(?callable $log = null): void
    {
        $log = $log ?: static function (string $m): void {};
        $now = time();
        $hm = date('H:i');

        // redistribuição de pendências PRIMEIRO: é a rotina sensível ao tempo e não pode esperar (nem ser derrubada) por outra.
        // Só age se o ADM ligou em Redistribuição. Não depende de ninguém estar logado quando o disparo vem do cron/worker.
        self::safe('redist', static function () use ($log) { if (Redistribution::due()) Redistribution::tick($log); });

        // agendador do servidor: se ninguém o disparou (ainda não instalado), tenta instalar sozinho, no máximo 1x por dia.
        // Só em requisições web: no CLI (cron/worker) o agendador, por definição, já está rodando.
        if (PHP_SAPI !== 'cli') self::safe('sched', static function () { $m = Scheduler::ensure(); if ($m !== '') Util::log('sched', $m); });

        // métricas: a cada 30 min
        if ($now - (int) self::state('metrics_ts') >= 1800) {
            self::safe('metrics', static function () use ($now, $log) {
                self::locked('metrics', function () use ($now, $log) {
                    if ($now - (int) self::state('metrics_ts') < 1800) return;
                    self::refreshMetrics(Db::today());
                    self::setState('metrics_ts', (string) time());
                    $log('métricas de hoje atualizadas');
                });
            });
        }

        // fechamento noturno
        if ($hm >= '00:10' && self::state('nightly_date') !== Db::today()) {
            self::safe('nightly', static function () use ($log) {
                self::locked('nightly', function () use ($log) {
                    if (self::state('nightly_date') === Db::today()) return;
                    self::refreshMetrics(Db::today(-1));
                    $log('manutenção: ' . self::maintenance());
                    self::setState('nightly_date', Db::today());
                });
            });
        }

        // backup diário
        $bk = Config::get('backup');
        if (!empty($bk['auto']) && $hm >= (string) $bk['time'] && self::state('backup_date') !== Db::today()) {
            self::locked('backup', function () use ($log) {
                if (self::state('backup_date') === Db::today()) return;
                self::setState('backup_date', Db::today());          // marca antes: não repete em loop se falhar
                try {
                    $r = Backup::create('');
                    $log('backup diário: ' . basename($r['file']) . ' (' . Util::human($r['size']) . ')');
                } catch (\Throwable $e) {
                    Util::log('backup', 'FALHOU: ' . $e->getMessage());
                    $log('backup FALHOU: ' . $e->getMessage());
                }
            });
        }
    }

    /** Recalcula (idempotente) as métricas de um dia a partir de verifications */
    public static function refreshMetrics(string $day): void
    {
        Db::q(
            'INSERT INTO daily_metrics (metric_date, tech_id, assigned, finished, resolved_direct, os_created, overdue)
             SELECT ?, v.tech_id,
                    SUM(v.due_date = ?),
                    SUM(v.finalizado = 1 AND DATE(v.finished_at) = ?),
                    SUM(v.finalizado = 1 AND v.resolvido = 1 AND v.os_criada = 0 AND DATE(v.finished_at) = ?),
                    SUM(v.os_criada = 1 AND v.due_date = ?),
                    SUM(v.finalizado = 0 AND v.due_date < ?)
               FROM verifications v
              WHERE v.due_date = ? OR DATE(v.finished_at) = ? OR (v.finalizado = 0 AND v.due_date < ?)
              GROUP BY v.tech_id
             ON DUPLICATE KEY UPDATE assigned = VALUES(assigned), finished = VALUES(finished),
               resolved_direct = VALUES(resolved_direct), os_created = VALUES(os_created), overdue = VALUES(overdue)',
            array_fill(0, 9, $day));
    }

    public static function maintenance(): string
    {
        $a = Db::q('DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [(int) Config::get('audit_retention_days')])->rowCount();
        $b = Db::q('DELETE FROM batches WHERE id NOT IN (SELECT DISTINCT batch_id FROM verifications)')->rowCount();
        $c = Db::q('UPDATE users SET locked_until = NULL, failed_logins = 0 WHERE locked_until IS NOT NULL AND locked_until < NOW()')->rowCount();
        $d = Db::q('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY')->rowCount();
        // histórico de verificações: só limpa o que ficou órfão (verificação excluída) e já passou da retenção
        $e = Db::q('DELETE FROM verification_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND verification_id NOT IN (SELECT id FROM verifications)', [(int) Config::get('audit_retention_days')])->rowCount();
        $f = Branding::cleanStale();   // pedaços de envios de plano de fundo abandonados
        return "auditoria removida=$a conjuntos órfãos=$b desbloqueios=$c limites antigos=$d histórico órfão=$e envios abandonados=$f";
    }
}
