<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Férias dos técnicos.
 *
 * Quem está de férias (período APROVADO que inclui hoje) fica FORA das verificações:
 *   - não recebe pela redistribuição automática, nem pela designação do ADM, nem pela atribuição automática de novas demandas;
 *   - o ADM não consegue reatribuir/criar verificação para ele e ele não consegue "assumir" pendências;
 *   - as verificações que ele já tinha em aberto e NÃO iniciou voltam para a fila central (se o ADM deixou marcado), e a
 *     redistribuição dispensa a espera mínima nelas, porque quem as deixou está ausente.
 * A equipe vê o status no cartão e no perfil. Ao fim do período o técnico volta a receber sozinho (nada a fazer).
 *
 * Fluxos: o ADM registra direto (nasce APROVADA) ou o técnico solicita (SOLICITADA) e o ADM aprova ou recusa.
 */
final class Vacation
{
    public const MAX_DAYS = 90;      // por período
    public const NOTE_MAX = 300;
    public const AHEAD_DAYS = 366;   // quanto no futuro pode ser agendada

    /** @var array<string, ?string> cache por requisição (o Sync consulta o mesmo técnico muitas vezes numa planilha) */
    private static array $cache = [];

    /** SQL: o técnico do alias informado está de férias hoje. */
    public static function activeSql(string $alias = 'u'): string
    {
        return "EXISTS (SELECT 1 FROM vacations va_x WHERE va_x.user_id = $alias.id AND va_x.status = 'APROVADA' AND CURDATE() BETWEEN va_x.start_date AND va_x.end_date)";
    }

    /** Último dia das férias em andamento (AAAA-MM-DD) ou null. */
    public static function activeUntil(string $userId): ?string
    {
        if (array_key_exists($userId, self::$cache)) return self::$cache[$userId];
        $v = Db::val("SELECT MAX(end_date) FROM vacations WHERE user_id = ? AND status = 'APROVADA' AND CURDATE() BETWEEN start_date AND end_date", [$userId]);
        return self::$cache[$userId] = ($v ? (string) $v : null);
    }

    /** Erro padrão ao tentar enviar trabalho a quem está de férias. */
    public static function refuse(string $name, string $until): HttpError
    {
        return new HttpError(409, "$name está de férias até " . self::br($until) . ' e não recebe verificações. Escolha outro técnico.', 'CONFLICT');
    }

    public static function br(string $ymd): string
    {
        return substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2) . '/' . substr($ymd, 0, 4);
    }

    // ------------------------------------------------------------------ consultas

    /**
     * Férias APROVADAS que importam para a equipe: em andamento ou que começam em até 30 dias.
     * Devolve [userId => ['from','to','active']] (a mais próxima de cada técnico).
     */
    public static function forTeam(): array
    {
        $out = [];
        foreach (Db::all("SELECT user_id, start_date, end_date, CURDATE() BETWEEN start_date AND end_date AS active
                            FROM vacations
                           WHERE status = 'APROVADA' AND end_date >= CURDATE() AND start_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                           ORDER BY start_date") as $r) {
            if (isset($out[$r['user_id']])) continue;
            $out[$r['user_id']] = ['from' => $r['start_date'], 'to' => $r['end_date'], 'active' => (int) $r['active'] === 1];
        }
        return $out;
    }

    /** Lista para o ADM (todas, ou de um técnico) ou para o próprio técnico. */
    public static function list(?string $userId = null): array
    {
        $rows = Db::all("SELECT va.*, u.name AS user_name, u.shift AS user_shift, dn.name AS decided_name, rq.name AS requested_name,
                                (va.status = 'APROVADA' AND CURDATE() BETWEEN va.start_date AND va.end_date) AS is_active,
                                (va.status = 'APROVADA' AND va.start_date > CURDATE()) AS is_upcoming,
                                (SELECT COUNT(*) FROM verifications v WHERE v.tech_id = va.user_id AND v.finalizado = 0) AS open_n
                           FROM vacations va
                           JOIN users u ON u.id = va.user_id
                           LEFT JOIN users dn ON dn.id = va.decided_by
                           LEFT JOIN users rq ON rq.id = va.requested_by" . ($userId !== null ? ' WHERE va.user_id = ?' : '') . "
                          ORDER BY (va.status = 'SOLICITADA') DESC, (va.status = 'APROVADA' AND va.end_date >= CURDATE()) DESC, va.start_date DESC
                          LIMIT 500", $userId !== null ? [$userId] : []);
        return array_map(static function ($r) {
            $phase = $r['status'];
            if ((int) $r['is_active'] === 1) $phase = 'ATIVA';
            elseif ((int) $r['is_upcoming'] === 1) $phase = 'AGENDADA';
            elseif ($r['status'] === 'APROVADA') $phase = 'CONCLUIDA';
            return [
                'id' => $r['id'], 'userId' => $r['user_id'], 'user' => $r['user_name'], 'shift' => $r['user_shift'],
                'from' => $r['start_date'], 'to' => $r['end_date'], 'status' => $r['status'], 'phase' => $phase,
                'note' => (string) ($r['note'] ?? ''), 'requestedBy' => $r['requested_name'] ?? '', 'byTech' => $r['requested_by'] === $r['user_id'],
                'decidedBy' => $r['decided_name'] ?? '', 'decidedAt' => $r['decided_at'], 'decisionNote' => (string) ($r['decision_note'] ?? ''),
                'returnOpen' => (int) $r['return_open'] === 1, 'returnedN' => (int) $r['returned_n'], 'openNow' => (int) $r['open_n'], 'createdAt' => $r['created_at'],
            ];
        }, $rows);
    }

    // ------------------------------------------------------------------ ações

    private static function dates(array $b): array
    {
        $from = is_string($b['from'] ?? null) ? $b['from'] : '';
        $to = is_string($b['to'] ?? null) ? $b['to'] : '';
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new HttpError(400, 'Informe a data de início e a data final das férias.', 'VALIDATION');
            }
        }
        if ($to < $from) throw new HttpError(400, 'A data final não pode ser antes da inicial.', 'VALIDATION');
        if ($from < Db::today()) throw new HttpError(400, 'O início das férias não pode ser em uma data que já passou.', 'VALIDATION');
        if ($from > Db::today(self::AHEAD_DAYS)) throw new HttpError(400, 'O início das férias está longe demais (máximo de 1 ano à frente).', 'VALIDATION');
        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        if ($days > self::MAX_DAYS) throw new HttpError(400, 'Cada período de férias pode ter no máximo ' . self::MAX_DAYS . ' dias.', 'VALIDATION');
        return [$from, $to];
    }

    private static function note(array $b, string $k = 'note'): ?string
    {
        $n = trim((string) ($b[$k] ?? ''));
        if ($n === '') return null;
        if (preg_match('/[<>]/', $n)) throw new HttpError(400, 'A observação não pode ter os caracteres < ou >.', 'VALIDATION');
        if (Util::len($n) > self::NOTE_MAX) throw new HttpError(400, 'A observação aceita até ' . self::NOTE_MAX . ' caracteres.', 'VALIDATION');
        return $n;
    }

    private static function insert(string $userId, string $from, string $to, ?string $note, string $status, string $by, bool $returnOpen): string
    {
        return (string) Db::tx(static function () use ($userId, $from, $to, $note, $status, $by, $returnOpen): string {
            $u = Db::one("SELECT id, role, status FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if (!$u || $u['role'] !== 'TEC' || $u['status'] !== 'APROVADO') throw new HttpError(400, 'Férias valem para técnicos com acesso aprovado.', 'VALIDATION');
            $clash = Db::val("SELECT COUNT(*) FROM vacations WHERE user_id = ? AND status IN ('SOLICITADA', 'APROVADA') AND start_date <= ? AND end_date >= ?", [$userId, $to, $from]);
            if ((int) $clash > 0) throw new HttpError(409, 'Já existe uma férias (solicitada ou aprovada) que se sobrepõe a este período.', 'CONFLICT');
            $id = Util::newId('vac');
            Db::q('INSERT INTO vacations (id, user_id, start_date, end_date, status, requested_by, note, decided_by, decided_at, return_open) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$id, $userId, $from, $to, $status, $by, $note, $status === 'APROVADA' ? $by : null, $status === 'APROVADA' ? date('Y-m-d H:i:s') : null, $returnOpen ? 1 : 0]);
            return $id;
        });
    }

    /** O técnico pede as próprias férias; o ADM decide. */
    public static function request(array $me, array $b, string $ip): array
    {
        if ($me['role'] !== 'TEC') throw new HttpError(403, 'Só técnicos solicitam férias. O ADM registra direto em Gerenciar usuários.', 'FORBIDDEN');
        [$from, $to] = self::dates($b);
        $id = self::insert($me['id'], $from, $to, self::note($b), 'SOLICITADA', $me['id'], true);
        Util::audit($me['id'], 'VACATION_REQUEST', 'vacation', $id, ['de' => $from, 'ate' => $to], $ip ?: null);
        return ['id' => $id, 'status' => 'SOLICITADA'];
    }

    /** O ADM registra férias de um técnico (já aprovadas). */
    public static function create(array $adm, array $b, string $ip): array
    {
        $uid = is_string($b['userId'] ?? null) ? $b['userId'] : '';
        if (!preg_match(Util::ID_RE, $uid)) throw new HttpError(400, 'Escolha o técnico.', 'VALIDATION');
        [$from, $to] = self::dates($b);
        $ret = !array_key_exists('returnOpen', $b) || !empty($b['returnOpen']);
        $id = self::insert($uid, $from, $to, self::note($b), 'APROVADA', $adm['id'], $ret);
        Util::audit($adm['id'], 'VACATION_CREATE', 'vacation', $id, ['tecnico' => $uid, 'de' => $from, 'ate' => $to, 'devolver_abertas' => $ret ? 1 : 0], $ip ?: null);
        self::$cache = [];
        return ['id' => $id, 'status' => 'APROVADA', 'returned' => self::sweep()];
    }

    /** O ADM aprova ou recusa uma solicitação. */
    public static function decide(array $adm, array $b, string $ip): array
    {
        $id = is_string($b['id'] ?? null) ? $b['id'] : '';
        $ok = ($b['decision'] ?? '') === 'APROVAR';
        if (!preg_match(Util::ID_RE, $id) || (!$ok && ($b['decision'] ?? '') !== 'RECUSAR')) throw new HttpError(400, 'Solicitação inválida.', 'VALIDATION');
        $note = self::note($b, 'decisionNote');
        $ret = !array_key_exists('returnOpen', $b) || !empty($b['returnOpen']);
        Db::tx(static function () use ($id, $ok, $note, $ret, $adm): void {
            $v = Db::one('SELECT id, status, end_date FROM vacations WHERE id = ? FOR UPDATE', [$id]);
            if (!$v) throw new HttpError(404, 'Solicitação não encontrada.', 'NOT_FOUND');
            if ($v['status'] !== 'SOLICITADA') throw new HttpError(409, 'Esta solicitação já foi decidida ou cancelada. Atualize a tela.', 'CONFLICT');
            if ($ok && $v['end_date'] < Db::today()) throw new HttpError(409, 'O período solicitado já terminou. Recuse e peça uma nova solicitação.', 'CONFLICT');
            Db::q('UPDATE vacations SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?, return_open = ? WHERE id = ?',
                [$ok ? 'APROVADA' : 'RECUSADA', $adm['id'], $note, $ret ? 1 : 0, $id]);
        });
        Util::audit($adm['id'], $ok ? 'VACATION_APPROVE' : 'VACATION_REJECT', 'vacation', $id, array_filter(['nota' => $note], static fn($x) => $x !== null), $ip ?: null);
        self::$cache = [];
        return ['id' => $id, 'status' => $ok ? 'APROVADA' : 'RECUSADA', 'returned' => $ok ? self::sweep() : 0];
    }

    /**
     * Cancela. O técnico cancela a própria solicitação ou férias que ainda não começaram; o ADM também encerra as que já estão
     * em andamento (viram ENCERRADA e o técnico volta a receber já).
     */
    public static function cancel(array $me, array $b, string $ip): array
    {
        $id = is_string($b['id'] ?? null) ? $b['id'] : '';
        if (!preg_match(Util::ID_RE, $id)) throw new HttpError(400, 'Férias inválidas.', 'VALIDATION');
        $isAdm = $me['role'] === 'ADM';
        $new = Db::tx(static function () use ($id, $me, $isAdm): string {
            $v = Db::one('SELECT id, user_id, status, start_date, end_date FROM vacations WHERE id = ? FOR UPDATE', [$id]);
            if (!$v || (!$isAdm && $v['user_id'] !== $me['id'])) throw new HttpError(404, 'Férias não encontrada.', 'NOT_FOUND');
            $today = Db::today();
            if (!in_array($v['status'], ['SOLICITADA', 'APROVADA'], true) || $v['end_date'] < $today) throw new HttpError(409, 'Estas férias não podem mais ser canceladas.', 'CONFLICT');
            $started = $v['status'] === 'APROVADA' && $v['start_date'] <= $today;
            if ($started && !$isAdm) throw new HttpError(403, 'As férias já começaram. Peça ao administrador para encerrar.', 'FORBIDDEN');
            $st = $started ? 'ENCERRADA' : 'CANCELADA';
            Db::q('UPDATE vacations SET status = ? WHERE id = ?', [$st, $id]);
            return $st;
        });
        Util::audit($me['id'], $new === 'ENCERRADA' ? 'VACATION_END' : 'VACATION_CANCEL', 'vacation', $id, null, $ip ?: null);
        self::$cache = [];
        return ['id' => $id, 'status' => $new];
    }

    /**
     * Devolve à fila central o que o técnico de férias tinha em aberto e NÃO começou (uma vez por período). Roda junto da
     * redistribuição (a cada ciclo) e logo depois de aprovar/registrar. Não mexe no que está em atendimento.
     */
    public static function sweep(): int
    {
        $total = 0;
        $list = Db::all("SELECT va.id, va.user_id, va.end_date FROM vacations va
                          WHERE va.status = 'APROVADA' AND va.return_open = 1 AND va.returned_at IS NULL AND CURDATE() BETWEEN va.start_date AND va.end_date LIMIT 50");
        foreach ($list as $v) {
            $total += (int) Db::tx(static function () use ($v): int {
                $mine = Db::all('SELECT id, pppoe FROM verifications WHERE tech_id = ? AND finalizado = 0 AND pending_contact = 0 AND em_execucao = 0 FOR UPDATE', [$v['user_id']]);
                $note = 'Técnico de férias até ' . self::br((string) $v['end_date']) . ': devolvida à fila central.';
                $n = 0;
                foreach ($mine as $r) {
                    $ok = Db::q('UPDATE verifications SET pending_contact = 1, pending_since = NOW(), pending_note = ?, version = version + 1
                                  WHERE id = ? AND finalizado = 0 AND pending_contact = 0 AND em_execucao = 0', [$note, $r['id']])->rowCount();
                    if ($ok !== 1) continue;
                    Db::q('INSERT INTO verification_events (verification_id, pppoe, user_id, event, details) VALUES (?, ?, NULL, ?, ?)',
                        [$r['id'], $r['pppoe'], 'FERIAS_DEVOLVIDA', Util::json(['ferias_ate' => $v['end_date']])]);
                    $n++;
                }
                Db::q('UPDATE vacations SET returned_at = NOW(), returned_n = ? WHERE id = ?', [$n, $v['id']]);
                if ($n > 0) Util::audit(null, 'VACATION_RETURN_OPEN', 'vacation', $v['id'], ['devolvidas' => $n, 'tecnico' => $v['user_id']]);
                return $n;
            });
        }
        return $total;
    }
}
