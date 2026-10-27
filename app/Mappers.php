<?php
declare(strict_types=1);
namespace Rotina;

/**
 * A interface usa nomes camelCase (techEmail, naoAtende, lote...).
 * Estes mapeadores traduzem do/para o modelo relacional, mantendo a UI intacta.
 */
final class Mappers
{
    public const VERIF_SELECT = '
      v.id, v.pppoe, v.shift, v.due_date, v.priority, v.kind, v.is_single, v.prev_count, v.repeat_of, v.obs, v.ligou, v.nao_atende,
      v.resolvido, v.os_criada, v.action_id, v.handle_time_min, v.em_execucao, v.pending_contact, v.pending_since, v.pending_note,
      v.handoff_note, v.handoff_at, v.redist_count, hu.name AS handoff_name,
      v.finalizado, v.version, v.started_at, v.finished_at, v.created_at,
      (SELECT COUNT(*) FROM verifications o WHERE o.pppoe = v.pppoe AND o.id <> v.id
          AND (o.created_at < v.created_at OR (o.created_at = v.created_at AND o.id < v.id))) AS hist_prev,
      bt.name AS batch_name, u.email AS tech_email
      FROM verifications v
      JOIN batches bt ON bt.id = v.batch_id
      JOIN users u ON u.id = v.tech_id
      LEFT JOIN users hu ON hu.id = v.handoff_from';

    public static function verif(array $r): array
    {
        return [
            'id' => $r['id'], 'techEmail' => $r['tech_email'], 'shift' => $r['shift'], 'date' => $r['due_date'],
            'pppoe' => $r['pppoe'], 'lote' => $r['batch_name'], 'priority' => $r['priority'],
            // A classe vem do HISTÓRICO REAL (quantas verificações anteriores existem para o PPPoE), não só do que foi gravado na criação:
            // assim PPPoE verificados antes da v1.3 ou lançados de novo nunca aparecem como "primeira" por engano.
            'prevCount' => $prev = max((int) ($r['prev_count'] ?? 0), (int) ($r['hist_prev'] ?? 0)),
            'kind' => ($r['kind'] ?? '') === 'REABERTA' ? 'REABERTA' : ($prev > 0 ? 'ADICIONAL' : ($r['kind'] ?? 'NORMAL')), 'repeatOf' => $r['repeat_of'] ?? null,
            'obs' => $r['obs'] ?? '',
            'pendente' => (int) ($r['pending_contact'] ?? 0) === 1,
            'pendenteNota' => $r['pending_note'] ?? '',
            'pendenteDesde' => $r['pending_since'] ?? null,
            // Passagem de bastão: o que o técnico anterior deixou como pendente (só vale enquanto a verificação não for concluída)
            'entregaNota' => $r['handoff_note'] ?? '', 'entregaDe' => $r['handoff_name'] ?? '', 'entregaEm' => $r['handoff_at'] ?? null,
            'trocas' => (int) ($r['redist_count'] ?? 0),
            'single' => (int) ($r['is_single'] ?? 0) === 1,
            'ligou' => (int) $r['ligou'] === 1, 'naoAtende' => (int) $r['nao_atende'] === 1,
            'resolvido' => (int) $r['resolvido'] === 1, 'osCriada' => (int) $r['os_criada'] === 1,
            'actionId' => $r['action_id'], 'handleTimeMin' => (int) $r['handle_time_min'],
            'emExecucao' => (int) $r['em_execucao'] === 1, 'finalizado' => (int) $r['finalizado'] === 1,
            'version' => (int) $r['version'], 'startedAt' => $r['started_at'], 'finishedAt' => $r['finished_at'],
            'createdAt' => $r['created_at'],
        ];
    }

    /** NUNCA devolve password_hash */
    public static function user(array $r): array
    {
        return ['id' => $r['id'], 'name' => $r['name'], 'email' => $r['email'],
                'role' => $r['role'], 'shift' => $r['shift'], 'status' => $r['status'],
                'avatarV' => (int) ($r['avatar_v'] ?? 0)];
    }

    public static function action(array $r): array { return ['id' => $r['id'], 'label' => $r['label']]; }
}
