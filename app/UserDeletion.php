<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Exclusão DEFINITIVA de usuário, feita para não corromper o sistema.
 *
 * Por que não é um simples DELETE: as verificações apontam para o técnico (chave estrangeira RESTRICT) e há estatísticas
 * e histórico ligados a ele. Tudo roda numa única transação (ou conclui inteiro, ou nada muda):
 *   1. Verificações EM ABERTO do usuário são transferidas para outro técnico escolhido pelo ADM (prazo, PPPoE e anotações
 *      continuam; o atendimento em andamento é zerado; fica um evento "REATRIBUIDA" no histórico).
 *   2. Verificações JÁ CONCLUÍDAS passam para o usuário-sistema "Usuário removido" (bloqueado, não entra no sistema): os
 *      totais da equipe, os gráficos e o histórico do PPPoE continuam corretos, só sem o nome da pessoa.
 *   3. Eventos do histórico passam para o "Usuário removido"; a auditoria guarda o e-mail de quem foi excluído.
 *   4. As métricas diárias individuais do usuário são apagadas junto (o banco faz isso sozinho).
 *   5. O usuário é apagado; a sessão dele deixa de valer na hora.
 */
final class UserDeletion
{
    public const GHOST_ID = 'removido';

    private static function load(string $id, bool $lock = false): array
    {
        if (!preg_match(Util::ID_RE, $id)) throw new HttpError(400, 'Usuário inválido.', 'VALIDATION');
        $u = Db::one('SELECT id, name, email, role, shift, status FROM users WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (!$u) throw new HttpError(404, 'Usuário não encontrado.', 'NOT_FOUND');
        return $u;
    }

    private static function guard(array $actor, array $u): void
    {
        if ($u['id'] === self::GHOST_ID) throw new HttpError(400, 'Este usuário é do sistema e não pode ser excluído.', 'VALIDATION');
        if ($u['id'] === $actor['id']) throw new HttpError(400, 'Você não pode excluir a si mesmo.', 'VALIDATION');
        if ($u['role'] === 'ADM' && $u['status'] === 'APROVADO'
            && (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'ADM' AND status = 'APROVADO' AND id <> ?", [$u['id']]) < 1) {
            throw new HttpError(400, 'Este é o único administrador ativo: o sistema precisa de ao menos um.', 'VALIDATION');
        }
    }

    /** O que será afetado: mostrado ao ADM ANTES de confirmar. */
    public static function preview(array $actor, string $id): array
    {
        $u = self::load($id);
        self::guard($actor, $u);
        $v = Db::one('SELECT COALESCE(SUM(finalizado = 0), 0) AS open_n,
                             COALESCE(SUM(finalizado = 0 AND pending_contact = 1), 0) AS pending_n,
                             COALESCE(SUM(finalizado = 0 AND em_execucao = 1), 0) AS running_n,
                             COALESCE(SUM(finalizado = 1), 0) AS done_n
                        FROM verifications WHERE tech_id = ?', [$u['id']]);
        return [
            'user' => ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'shift' => $u['shift']],
            'open' => (int) $v['open_n'],
            'pending' => (int) $v['pending_n'],
            'running' => (int) $v['running_n'],
            'done' => (int) $v['done_n'],
            'events' => (int) Db::val('SELECT COUNT(*) FROM verification_events WHERE user_id = ?', [$u['id']]),
            'metricDays' => (int) Db::val('SELECT COUNT(*) FROM daily_metrics WHERE tech_id = ?', [$u['id']]),
            'auditRows' => (int) Db::val('SELECT COUNT(*) FROM audit_log WHERE user_id = ?', [$u['id']]),
        ];
    }

    public static function delete(array $actor, string $id, ?string $transferTo, string $confirmEmail, string $ip): array
    {
        $result = Db::tx(function () use ($actor, $id, $transferTo, $confirmEmail, $ip) {
            $u = self::load($id, true);
            self::guard($actor, $u);
            if (strtolower(trim($confirmEmail)) !== strtolower($u['email'])) {
                throw new HttpError(400, 'Para confirmar, digite exatamente o e-mail do usuário.', 'VALIDATION');
            }

            // Verificações em aberto: travadas para ninguém mexer nelas durante a transferência
            $open = Db::all('SELECT id, pppoe FROM verifications WHERE tech_id = ? AND finalizado = 0 FOR UPDATE', [$u['id']]);
            $moved = 0;
            $dest = null;
            if ($open) {
                if (!$transferTo || !preg_match(Util::ID_RE, $transferTo)) {
                    throw new HttpError(400, 'Este usuário tem verificações em aberto. Escolha para qual técnico transferi-las.', 'NEEDS_TRANSFER');
                }
                $dest = Db::one("SELECT id, email, shift FROM users WHERE id = ? AND role = 'TEC' AND status = 'APROVADO'", [$transferTo]);
                if (!$dest || $dest['id'] === $u['id']) {
                    throw new HttpError(400, 'Técnico de destino inválido. Escolha um técnico aprovado.', 'VALIDATION');
                }
                Db::q('UPDATE verifications
                          SET tech_id = ?, shift = ?, pending_contact = 0, pending_since = NULL, pending_note = NULL,
                              handoff_note = NULL, handoff_from = NULL, handoff_at = NULL,
                              em_execucao = 0, started_at = NULL, version = version + 1
                        WHERE tech_id = ? AND finalizado = 0', [$dest['id'], $dest['shift'], $u['id']]);
                foreach (array_chunk($open, 200) as $part) {
                    $row = '(?, ?, ?, ?, ?)';
                    $vals = [];
                    foreach ($part as $o) {
                        array_push($vals, $o['id'], $o['pppoe'], $actor['id'], 'REATRIBUIDA',
                            Util::json(['de' => $u['email'], 'para' => $dest['email'], 'motivo' => 'Usuário excluído pelo administrador']));
                    }
                    Db::q('INSERT INTO verification_events (verification_id, pppoe, user_id, event, details) VALUES '
                        . implode(',', array_fill(0, count($part), $row)), $vals);
                }
                $moved = count($open);
            }

            // Usuário-sistema que herda o histórico (não consegue entrar: BLOQUEADO)
            Db::q("INSERT IGNORE INTO users (id, name, email, password_hash, role, shift, status)
                   VALUES (?, 'Usuário removido', 'removido@sistema.invalid', ?, 'TEC', 'GERAL', 'BLOQUEADO')", [self::GHOST_ID, Util::DUMMY_HASH]);

            $done = Db::q('UPDATE verifications SET tech_id = ?, handoff_from = NULL, version = version + 1 WHERE tech_id = ?', [self::GHOST_ID, $u['id']])->rowCount();
            Db::q('UPDATE verifications SET handoff_from = NULL WHERE handoff_from = ?', [$u['id']]);
            Db::q('UPDATE verification_events SET user_id = ? WHERE user_id = ?', [self::GHOST_ID, $u['id']]);
            // A auditoria continua mostrando quem fez cada coisa, mesmo depois da exclusão
            Db::q("UPDATE audit_log SET details = JSON_SET(COALESCE(details, JSON_OBJECT()), '$.autorRemovido', ?) WHERE user_id = ?", [$u['email'], $u['id']]);

            Db::q('DELETE FROM users WHERE id = ?', [$u['id']]);   // daily_metrics sai junto (CASCADE); created_by vira NULL (SET NULL)

            if ((int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'ADM' AND status = 'APROVADO'") < 1) {
                throw new HttpError(400, 'Operação cancelada: o sistema precisa de ao menos um administrador ativo.', 'VALIDATION');
            }

            Util::audit($actor['id'], 'USER_DELETE', 'user', $u['id'], [
                'email' => $u['email'], 'name' => $u['name'], 'role' => $u['role'], 'status' => $u['status'],
                'abertas_transferidas' => $moved, 'para' => $dest['email'] ?? null, 'concluidas_preservadas' => $done, 'definitiva' => true,
            ], $ip);

            return ['ok' => true, 'moved' => $moved, 'kept' => $done, 'to' => $dest['email'] ?? null];
        });
        // Faz os demais navegadores recarregarem na hora (o carimbo guarda o resultado por alguns segundos)
        try { Db::q("DELETE FROM app_state WHERE k = 'stamp_cache'"); } catch (\Throwable $e) { /* só otimização */ }
        return $result;
    }
}
