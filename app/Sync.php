<?php
declare(strict_types=1);
namespace Rotina;

/**
 * POST api.php?r=sync aplica, em UMA transação, as mudanças feitas pela interface:
 *   { users:{upsert,delete}, actions:{upsert,delete}, tasks:{upsert,delete} }
 * As regras de segurança são aplicadas AQUI (servidor), nunca confiando no front-end:
 *   ADM -> pode tudo (exceto se auto-bloquear / remover o último administrador).
 *   TEC -> só atualiza o andamento das próprias verificações, assume (takeover) verificações
 *          abertas de outros e edita o próprio perfil.
 * Qualquer violação aborta a transação inteira (nada é gravado pela metade).
 */
final class Sync
{
    private const UNSAFE = '/[<>"\'`\\\\]/';
    private const MAX_ITEMS = 20000;
    private const SHIFTS = ['GERAL', 'MANHA', 'TARDE', 'NOITE', 'MADRUGADA'];
    // Campos do técnico que o TEC pode alterar nas próprias verificações
    public const PEND_NOTE_MAX = 200;   // a nota de "pendente" é um recado curto, não uma redação
    private const TEC_EDITABLE = ['obs', 'ligou', 'naoAtende', 'resolvido', 'osCriada', 'actionId',
        'handleTimeMin', 'finalizado', 'emExecucao', 'techEmail', 'pendente', 'pendenteNota'];
    private const TASK_FIELDS = ['techEmail', 'shift', 'date', 'pppoe', 'lote', 'priority', 'obs', 'ligou',
        'naoAtende', 'resolvido', 'osCriada', 'actionId', 'handleTimeMin', 'finalizado', 'emExecucao', 'pendente', 'pendenteNota'];
    private const COL = ['shift' => 'shift', 'date' => 'due_date', 'pppoe' => 'pppoe', 'priority' => 'priority',
        'obs' => 'obs', 'ligou' => 'ligou', 'naoAtende' => 'nao_atende', 'resolvido' => 'resolvido',
        'osCriada' => 'os_criada', 'actionId' => 'action_id', 'handleTimeMin' => 'handle_time_min',
        'finalizado' => 'finalizado', 'emExecucao' => 'em_execucao',
        'pendente' => 'pending_contact', 'pendenteNota' => 'pending_note'];

    // =============================== VALIDAÇÃO ===============================
    private static function err(string $path, string $msg): HttpError
    {
        return new HttpError(400, "Dados inválidos ($path): $msg", 'VALIDATION');
    }

    private static function id($v, string $path): string
    {
        if (!is_string($v) || !preg_match(Util::ID_RE, $v)) throw self::err($path, 'id inválido');
        return $v;
    }

    private static function email($v, string $path): string
    {
        if (!is_string($v)) throw self::err($path, 'e-mail inválido');
        $v = strtolower(trim($v));
        if ($v === '' || strlen($v) > 190 || !filter_var($v, FILTER_VALIDATE_EMAIL)) throw self::err($path, 'e-mail inválido');
        return $v;
    }

    /** Texto curto sem < > " ' ` \  (a interface monta HTML por template: evita XSS armazenado) */
    private static function safeText($v, string $path, int $max, string $label): string
    {
        if (!is_string($v)) throw self::err($path, "$label inválido");
        $v = trim($v);
        $n = Util::len($v);
        if ($n < 1 || $n > $max) throw self::err($path, "$label deve ter de 1 a $max caracteres");
        if (preg_match(self::UNSAFE, $v)) throw self::err($path, "$label contém caracteres não permitidos (< > \" ' ` \\)");
        return $v;
    }

    /** O nome do conjunto entra em onclick="...('nome')": só aceita um conjunto seguro de caracteres */
    private static function safeBatch($v, string $path): string
    {
        if (!is_string($v)) throw self::err($path, 'Nome do conjunto inválido');
        $v = trim($v);
        $n = Util::len($v);
        if ($n < 1 || $n > 160) throw self::err($path, 'O nome do conjunto deve ter de 1 a 160 caracteres');
        if (!preg_match('/^[\p{L}\p{N} _.,:;()\/#+@%-]+$/u', $v)) {
            throw self::err($path, 'O nome do conjunto só pode ter letras, números, espaço e _ . , : ; ( ) / # + @ % -');
        }
        return $v;
    }

    private static function enum($v, array $allowed, string $path, $default = null): string
    {
        if ($v === null && $default !== null) return $default;
        if (!is_string($v) || !in_array($v, $allowed, true)) throw self::err($path, 'valor inválido');
        return $v;
    }

    private static function bool(array $a, string $k, string $path): bool
    {
        if (!array_key_exists($k, $a) || $a[$k] === null) return false;
        if (!is_bool($a[$k])) throw self::err("$path.$k", 'esperado verdadeiro/falso');
        return $a[$k];
    }

    private static function date($v, string $path): string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw self::err($path, 'data inválida');
        }
        return $v;
    }

    private static function isList(array $a): bool { return $a === [] || array_keys($a) === range(0, count($a) - 1); }

    private static function parseTask($t, string $p): array
    {
        if (!is_array($t)) throw self::err($p, 'objeto esperado');
        $ht = $t['handleTimeMin'] ?? 8;
        if (!is_int($ht) || $ht < 0 || $ht > 32000) throw self::err("$p.handleTimeMin", 'tempo inválido');
        $ver = $t['version'] ?? null;
        if ($ver !== null && (!is_int($ver) || $ver < 1)) throw self::err("$p.version", 'versão inválida');
        $obs = $t['obs'] ?? '';
        if (!is_string($obs) || Util::len($obs) > 5000) throw self::err("$p.obs", 'observação inválida (máx. 5000 caracteres)');
        $pnote = $t['pendenteNota'] ?? '';
        if (!is_string($pnote) || Util::len($pnote) > 1000) throw self::err("$p.pendenteNota", 'observação do pendente inválida (máx. 1000 caracteres)');
        return [
            'id' => self::id($t['id'] ?? null, "$p.id"),
            'techEmail' => self::email($t['techEmail'] ?? null, "$p.techEmail"),
            'shift' => self::enum($t['shift'] ?? null, self::SHIFTS, "$p.shift"),
            'date' => self::date($t['date'] ?? null, "$p.date"),
            'pppoe' => self::safeText($t['pppoe'] ?? null, "$p.pppoe", 120, 'PPPoE'),
            'lote' => array_key_exists('lote', $t) && $t['lote'] !== null ? self::safeBatch($t['lote'], "$p.lote") : 'Sem Lote Definido',
            'priority' => self::enum($t['priority'] ?? null, ['NORMAL', 'ALTA', 'URGENTE'], "$p.priority", 'NORMAL'),
            'kind' => self::enum($t['kind'] ?? null, ['NORMAL', 'ADICIONAL', 'REABERTA'], "$p.kind", 'NORMAL'),
            'single' => !empty($t['single']) && $t['single'] === true,   // só vale na criação (atribuição única)
            // Só para a AUDITORIA (não são gravados na verificação): como ela foi criada e a nota do ADM ao reatribuir.
            // Assim as observações ficam exclusivas do técnico.
            'origem' => self::enum($t['origem'] ?? null, ['PLANILHA', 'PLANILHA_COTA', 'AUTOMATICA', 'UNICA'], "$p.origem", ''),
            'nota' => is_string($t['nota'] ?? null) ? Util::cut(trim($t['nota']), 500) : '',
            'reopenOf' => isset($t['reopenOf']) && $t['reopenOf'] !== null ? self::id($t['reopenOf'], "$p.reopenOf") : null,
            'obs' => $obs,
            'pendente' => self::bool($t, 'pendente', $p), 'pendenteNota' => trim($pnote),
            'ligou' => self::bool($t, 'ligou', $p), 'naoAtende' => self::bool($t, 'naoAtende', $p),
            'resolvido' => self::bool($t, 'resolvido', $p), 'osCriada' => self::bool($t, 'osCriada', $p),
            'emExecucao' => self::bool($t, 'emExecucao', $p), 'finalizado' => self::bool($t, 'finalizado', $p),
            'actionId' => array_key_exists('actionId', $t) && $t['actionId'] !== null ? self::id($t['actionId'], "$p.actionId") : 'NONE',
            'handleTimeMin' => $ht,
            'version' => $ver,
        ];
    }

    private static function parseUser($u, string $p): array
    {
        if (!is_array($u)) throw self::err($p, 'objeto esperado');
        $pw = $u['password'] ?? null;
        if ($pw !== null) {
            if (!is_string($pw) || strlen($pw) < 4) throw self::err("$p.password", 'A senha precisa ter ao menos 4 caracteres.');
            if (strlen($pw) > 200) throw self::err("$p.password", 'Senha longa demais.');
        }
        return [
            'id' => self::id($u['id'] ?? null, "$p.id"),
            'name' => self::safeText($u['name'] ?? null, "$p.name", 120, 'Nome'),
            'email' => self::email($u['email'] ?? null, "$p.email"),
            'role' => self::enum($u['role'] ?? null, ['ADM', 'TEC'], "$p.role"),
            'shift' => self::enum($u['shift'] ?? null, self::SHIFTS, "$p.shift"),
            'status' => self::enum($u['status'] ?? null, ['PENDENTE', 'APROVADO', 'BLOQUEADO'], "$p.status"),
            'password' => $pw,
        ];
    }

    private static function parseAction($a, string $p): array
    {
        if (!is_array($a)) throw self::err($p, 'objeto esperado');
        return ['id' => self::id($a['id'] ?? null, "$p.id"), 'label' => self::safeText($a['label'] ?? null, "$p.label", 160, 'Procedimento')];
    }

    private static function parse(array $raw): array
    {
        $out = [];
        foreach (['users' => 'parseUser', 'actions' => 'parseAction', 'tasks' => 'parseTask'] as $sec => $fn) {
            $s = $raw[$sec] ?? [];
            if (!is_array($s)) throw self::err($sec, 'objeto esperado');
            $up = $s['upsert'] ?? [];
            $del = $s['delete'] ?? [];
            if (!is_array($up) || !self::isList($up)) throw self::err("$sec.upsert", 'lista esperada');
            if (!is_array($del) || !self::isList($del)) throw self::err("$sec.delete", 'lista esperada');
            if (count($up) > self::MAX_ITEMS || count($del) > self::MAX_ITEMS) throw self::err($sec, 'itens demais');
            $items = [];
            foreach ($up as $i => $item) $items[] = self::$fn($item, "$sec.upsert.$i");
            $ids = [];
            foreach ($del as $i => $d) $ids[] = self::id($d, "$sec.delete.$i");
            $out[$sec] = ['upsert' => $items, 'delete' => $ids];
        }
        return $out;
    }

    private static function short($v)
    {
        return (is_string($v) && Util::len($v) > 200) ? Util::cut($v, 200) . '…' : $v;
    }

    private static function friendlyDup(\Throwable $e): HttpError
    {
        $m = Db::errmsg($e);
        if (strpos($m, 'uq_verif_active_pppoe') !== false) {
            // diz com quem está (o texto do erro do MySQL traz o PPPoE duplicado)
            $with = '';
            try {
                if (preg_match("/Duplicate entry '(.*)' for key/s", $m, $mm)) {
                    $o = Db::one('SELECT u.name, v.em_execucao, v.pending_contact FROM verifications v JOIN users u ON u.id = v.tech_id WHERE v.active_pppoe = ? LIMIT 1', [$mm[1]]);
                    if ($o) {
                        $st = (int) $o['pending_contact'] === 1 ? 'pendente de contato' : ((int) $o['em_execucao'] === 1 ? 'em atendimento' : 'na fila');
                        $with = ' Está com ' . $o['name'] . ' (' . $st . ').';
                    }
                }
            } catch (\Throwable $x) { /* sem o detalhe: mantém a mensagem simples */ }
            return new HttpError(409, 'Já existe uma verificação em aberto para este PPPoE (alguém acabou de criá-la).' . $with . ' Os dados foram recarregados.', 'CONFLICT');
        }
        if (strpos($m, 'uq_users_email') !== false) return new HttpError(409, 'E-mail já cadastrado!', 'CONFLICT');
        return new HttpError(409, 'Conflito de dados. Os dados foram recarregados.', 'CONFLICT');
    }

    // =============================== APLICAÇÃO ===============================
    public static function apply(array $actor, array $raw, string $ip): array
    {
        $p = self::parse($raw);
        $isAdm = $actor['role'] === 'ADM';
        $audits = [];
        $A = static function (string $event, string $entity, ?string $entityId, $details) use (&$audits, $actor, $ip): void {
            $audits[] = [$actor['id'], $event, $entity, $entityId, $details === null ? null : Util::json($details), $ip];
        };
        // Histórico por verificação (uma linha por acontecimento): alimenta o modal de Auditoria
        $events = [];
        $E = static function (string $verifId, string $pppoe, string $event, $details = null) use (&$events, $actor): void {
            $events[] = [$verifId, $pppoe, $actor['id'], $event, $details === null ? null : Util::json($details)];
        };
        $touchedTasks = [];
        $touchedUsers = [];
        $reissueSelf = false;

        try {
            Db::tx(function () use ($p, $isAdm, $actor, $A, $E, &$audits, &$events, &$touchedTasks, &$touchedUsers, &$reissueSelf) {
                // ---- caches ---- (ADM trava a tabela de usuários: serializa mudanças de acesso; TEC só lê)
                $usersById = [];
                $usersByEmail = [];
                foreach (Db::all('SELECT id, email, role, shift, status FROM users' . ($isAdm ? ' FOR UPDATE' : '')) as $r) {
                    $usersById[$r['id']] = $r;
                    $usersByEmail[$r['email']] = $r['id'];
                }

                // ================= PROCEDIMENTOS =================
                if ($p['actions']['upsert'] || $p['actions']['delete']) {
                    if (!$isAdm) throw new HttpError(403, 'Apenas administradores gerenciam procedimentos.', 'FORBIDDEN');
                    foreach ($p['actions']['upsert'] as $a) {
                        $next = (int) Db::val('SELECT COALESCE(MAX(sort_order),0)+1 FROM actions');
                        Db::q('INSERT INTO actions (id, label, sort_order) VALUES (?,?,?)
                               ON DUPLICATE KEY UPDATE label = VALUES(label), deleted_at = NULL', [$a['id'], $a['label'], $next]);
                        $A('ACTION_UPSERT', 'action', $a['id'], ['label' => $a['label']]);
                    }
                    foreach ($p['actions']['delete'] as $aid) {
                        if ($aid === 'NONE') throw new HttpError(400, 'O procedimento padrão "Nenhuma Ação" não pode ser removido.', 'VALIDATION');
                    }
                }

                // ================= USUÁRIOS (upsert) =================
                foreach ($p['users']['upsert'] as $u) {
                    $cur = $usersById[$u['id']] ?? null;

                    if (!$isAdm) {
                        if (!$cur || $cur['id'] !== $actor['id']) throw new HttpError(403, 'Você só pode editar o seu próprio perfil.', 'FORBIDDEN');
                        if ($u['role'] !== $cur['role'] || $u['shift'] !== $cur['shift'] || $u['status'] !== $cur['status']) {
                            throw new HttpError(403, 'Você não pode alterar papel, turno ou status.', 'FORBIDDEN');
                        }
                    }
                    if (isset($usersByEmail[$u['email']]) && $usersByEmail[$u['email']] !== $u['id']) {
                        throw new HttpError(409, 'E-mail já cadastrado!', 'CONFLICT');
                    }

                    if (!$cur) {
                        if (!$isAdm) throw new HttpError(403, 'Sem permissão para criar usuários.', 'FORBIDDEN');
                        if (!$u['password']) throw new HttpError(400, 'Informe uma senha com ao menos 4 caracteres.', 'VALIDATION');
                        Db::q('INSERT INTO users (id, name, email, password_hash, role, shift, status) VALUES (?,?,?,?,?,?,?)',
                            [$u['id'], $u['name'], $u['email'], Util::hashPassword($u['password']), $u['role'], $u['shift'], $u['status']]);
                        $usersById[$u['id']] = ['id' => $u['id'], 'email' => $u['email'], 'role' => $u['role'], 'shift' => $u['shift'], 'status' => $u['status']];
                        $usersByEmail[$u['email']] = $u['id'];
                        $A('USER_CREATE', 'user', $u['id'], ['email' => $u['email'], 'role' => $u['role']]);
                    } else {
                        if ($isAdm && $u['id'] === $actor['id'] && ($u['role'] !== 'ADM' || $u['status'] !== 'APROVADO')) {
                            throw new HttpError(400, 'Você não pode rebaixar ou bloquear a si mesmo.', 'VALIDATION');
                        }
                        $sets = ['name = ?', 'email = ?'];
                        $vals = [$u['name'], $u['email']];
                        $bump = false;
                        $changes = [];
                        if ($isAdm) {
                            array_push($sets, 'role = ?', 'shift = ?', 'status = ?');
                            array_push($vals, $u['role'], $u['shift'], $u['status']);
                            if ($cur['role'] !== $u['role']) { $changes['role'] = [$cur['role'], $u['role']]; $bump = true; }
                            if ($cur['shift'] !== $u['shift']) $changes['shift'] = [$cur['shift'], $u['shift']];
                            if ($cur['status'] !== $u['status']) { $changes['status'] = [$cur['status'], $u['status']]; if ($u['status'] !== 'APROVADO') $bump = true; }
                        }
                        if ($cur['email'] !== $u['email']) $changes['email'] = [$cur['email'], $u['email']];
                        if ($u['password']) {
                            $sets[] = 'password_hash = ?';
                            $vals[] = Util::hashPassword($u['password']);
                            $changes['password'] = 'alterada';
                            $bump = true;
                            if ($u['id'] === $actor['id']) $reissueSelf = true;
                        }
                        if ($bump) $sets[] = 'token_version = token_version + 1';
                        $vals[] = $u['id'];
                        Db::q('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);

                        if ($cur['status'] === 'PENDENTE' && $u['status'] === 'APROVADO') $A('USER_APPROVE', 'user', $u['id'], ['email' => $u['email']]);
                        elseif ($changes) $A('USER_UPDATE', 'user', $u['id'], $changes);

                        unset($usersByEmail[$cur['email']]);
                        $usersById[$u['id']] = ['id' => $u['id'], 'email' => $u['email'], 'role' => $u['role'], 'shift' => $u['shift'], 'status' => $u['status']];
                        $usersByEmail[$u['email']] = $u['id'];
                    }
                    $touchedUsers[$u['id']] = true;
                }

                // ================= VERIFICAÇÕES: exclusões =================
                if ($p['tasks']['delete']) {
                    if (!$isAdm) throw new HttpError(403, 'Apenas administradores excluem verificações.', 'FORBIDDEN');
                    foreach (array_chunk($p['tasks']['delete'], 1000) as $part) {
                        $in = Db::marks($part);
                        $rows = Db::all("SELECT v.id, v.pppoe, bt.name AS batch FROM verifications v
                                         JOIN batches bt ON bt.id = v.batch_id WHERE v.id IN ($in) FOR UPDATE", $part);
                        foreach ($rows as $r) $A('TASK_DELETE', 'verification', $r['id'], ['pppoe' => $r['pppoe'], 'lote' => $r['batch']]);
                        // Exclusão de verdade: some também o histórico (linha do tempo) e os registros de auditoria dessas verificações.
                        // Só fica a linha TASK_DELETE (quem excluiu, quando e qual PPPoE), gravada abaixo junto com as demais auditorias.
                        Db::q("DELETE FROM verification_events WHERE verification_id IN ($in)", $part);
                        Db::q("DELETE FROM audit_log WHERE entity = 'verification' AND entity_id IN ($in)", $part);
                        Db::q("DELETE FROM verifications WHERE id IN ($in)", $part);
                    }
                }

                // ================= VERIFICAÇÕES: criar / atualizar =================
                if ($p['tasks']['upsert']) {
                    $existing = [];
                    foreach (array_chunk(array_column($p['tasks']['upsert'], 'id'), 1000) as $part) {
                        $in = Db::marks($part);
                        Db::all("SELECT id FROM verifications WHERE id IN ($in) FOR UPDATE", $part);
                        foreach (Db::all('SELECT ' . Mappers::VERIF_SELECT . " WHERE v.id IN ($in)", $part) as $r) {
                            $existing[$r['id']] = Mappers::verif($r);
                        }
                    }

                    $batchCache = [];
                    $batchId = static function (string $name) use (&$batchCache, $actor): int {
                        if (isset($batchCache[$name])) return $batchCache[$name];
                        Db::q('INSERT INTO batches (name, created_by) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', [$name, $actor['id']]);
                        return $batchCache[$name] = (int) Db::pdo()->lastInsertId();
                    };

                    // PPPoE que já tiveram verificação concluída antes => nova verificação é da classe ADICIONAL.
                    // Quem decide é o servidor (o que o navegador mandar em 'kind' é ignorado).
                    $prior = [];
                    $newLogins = [];
                    foreach ($p['tasks']['upsert'] as $t) if (!isset($existing[$t['id']])) $newLogins[Util::lower($t['pppoe'])] = $t['pppoe'];
                    foreach (array_chunk(array_values($newLogins), 1000) as $part) {
                        foreach (Db::all('SELECT pppoe, id FROM verifications WHERE finalizado = 1 AND pppoe IN (' . Db::marks($part) . ')
                                          ORDER BY COALESCE(finished_at, created_at) DESC, created_at DESC', $part) as $r) {
                            $k = Util::lower($r['pppoe']);
                            if (!isset($prior[$k])) $prior[$k] = ['n' => 0, 'last' => $r['id']];     // 1ª linha = a mais recente
                            $prior[$k]['n']++;
                        }
                    }

                    $toInsert = [];
                    foreach ($p['tasks']['upsert'] as $t) {
                        $techId = $usersByEmail[$t['techEmail']] ?? null;
                        if (!$techId) throw new HttpError(400, 'Técnico não encontrado: ' . $t['techEmail'], 'VALIDATION');
                        $tech = $usersById[$techId];
                        if ($tech['status'] !== 'APROVADO') throw new HttpError(400, 'Técnico sem acesso aprovado: ' . $t['techEmail'], 'VALIDATION');

                        $cur = $existing[$t['id']] ?? null;

                        // ---------- criação ----------
                        if (!$cur) {
                            // Reabertura: o técnico (dono da anterior) ou o ADM reabre uma verificação CONCLUÍDA, o que gera uma
                            // NOVA verificação de classe REABERTA. A anterior não é alterada (resultado preservado).
                            $reopen = null;
                            if ($t['reopenOf'] !== null) {
                                $old = Db::one('SELECT id, pppoe, tech_id, finalizado, is_single FROM verifications WHERE id = ?', [$t['reopenOf']]);
                                if (!$old || (int) $old['finalizado'] !== 1 || Util::lower($old['pppoe']) !== Util::lower($t['pppoe'])) {
                                    throw new HttpError(400, 'Só é possível reabrir uma verificação concluída do mesmo PPPoE.', 'VALIDATION');
                                }
                                if (!$isAdm && ($old['tech_id'] !== $actor['id'] || $tech['id'] !== $actor['id'])) {
                                    throw new HttpError(403, 'Você só pode reabrir as suas próprias verificações.', 'FORBIDDEN');
                                }
                                $reopen = $old;
                            }
                            if (!$isAdm && !$reopen) throw new HttpError(403, 'Apenas administradores criam verificações.', 'FORBIDDEN');
                            $pr = $prior[Util::lower($t['pppoe'])] ?? null;
                            $kind = $reopen ? 'REABERTA' : ($pr ? 'ADICIONAL' : 'NORMAL');
                            $toInsert[] = [
                                $t['id'], $t['pppoe'], $batchId($t['lote']), $tech['id'], $t['shift'], $t['date'], $t['priority'],
                                $kind, $reopen ? (int) $reopen['is_single'] : (int) $t['single'], $pr ? min($pr['n'], 65000) : 0, $reopen ? $reopen['id'] : ($pr ? $pr['last'] : null),
                                $t['obs'] === '' ? null : $t['obs'], (int) $t['ligou'], (int) $t['naoAtende'], (int) $t['resolvido'],
                                (int) $t['osCriada'], $t['actionId'], $t['handleTimeMin'], (int) $t['emExecucao'], (int) $t['finalizado'], $actor['id'],
                            ];
                            $E($t['id'], $t['pppoe'], 'CRIADA', array_filter([
                                'verificacao' => $t['lote'], 'tecnico' => $t['techEmail'], 'prazo' => $t['date'], 'turno' => $t['shift'],
                                'prioridade' => $t['priority'], 'classe' => $kind, 'anteriores' => $pr ? $pr['n'] : null, 'reaberta_de' => $reopen ? $reopen['id'] : null,
                                'origem' => $reopen || $t['origem'] === '' ? null : $t['origem'],
                            ], static fn($v) => $v !== null));
                            continue;
                        }

                        // ---------- atualização ----------
                        $changes = [];
                        foreach (self::TASK_FIELDS as $f) {
                            if ($cur[$f] !== $t[$f]) $changes[$f] = [self::short($cur[$f]), self::short($t[$f])];
                        }
                        if (!$changes) continue;

                        // ---- pendente de contato: regras ----
                        if ($t['pendente'] && ($t['finalizado'] || $t['emExecucao'])) {
                            throw new HttpError(400, 'Uma verificação pendente de contato não pode estar concluída nem em atendimento.', 'VALIDATION');
                        }
                        $leavingPending = isset($changes['pendente']) && $t['pendente'];
                        $resumingPending = isset($changes['pendente']) && !$t['pendente'];
                        if ($leavingPending && Util::len($t['pendenteNota']) > self::PEND_NOTE_MAX) {
                            throw new HttpError(400, 'A nota da pendência aceita até ' . self::PEND_NOTE_MAX . ' caracteres. Seja breve: o que já tentou e o que falta.', 'VALIDATION');
                        }
                        if ($leavingPending && Util::len($t['pendenteNota']) < 3) {
                            throw new HttpError(400, 'Descreva o que já foi tentado e o que falta para deixar pendente de contato.', 'VALIDATION');
                        }

                        if ($t['version'] && $t['version'] !== $cur['version']) {
                            throw new HttpError(409, 'Esta verificação foi alterada por outra pessoa. Os dados foram recarregados — refaça a ação.', 'CONFLICT');
                        }

                        $takeover = false;
                        if (!$isAdm) {
                            $mine = $cur['techEmail'] === $actor['email'];
                            $takeover = !$mine && $t['techEmail'] === $actor['email'];
                            if (!$mine && !$takeover) throw new HttpError(403, 'Esta verificação pertence a outro técnico.', 'FORBIDDEN');
                            if ($takeover && $cur['finalizado']) throw new HttpError(409, 'Esta verificação já foi finalizada.', 'CONFLICT');
                            $forbidden = array_diff(array_keys($changes), self::TEC_EDITABLE);
                            if ($forbidden) throw new HttpError(403, 'Campos não permitidos para técnico: ' . implode(', ', $forbidden), 'FORBIDDEN');
                            if (isset($changes['techEmail']) && !$takeover) throw new HttpError(403, 'Técnicos só podem assumir para si mesmos.', 'FORBIDDEN');
                        }

                        // Quem RECEBE uma pendência (assume) começa com as observações em branco: o que existia antes não vai para o campo
                        // dele (o aviso "estava pendente" cuida do contexto). O texto anterior fica guardado na auditoria (RETOMADA.obs_anterior).
                        $freshObs = $resumingPending && $takeover;
                        $obsAnterior = '';
                        if ($freshObs) {
                            $obsAnterior = (string) $cur['obs'];
                            $t['obs'] = '';
                            if ($obsAnterior !== '') $changes['obs'] = [self::short($obsAnterior), '']; else unset($changes['obs']);
                        }

                        $sets = [];
                        $vals = [];
                        // Passagem de bastão: se OUTRO técnico assume uma pendência, a nota de quem deixou vira "entrega" e acompanha a
                        // verificação. Vem ANTES das demais atribuições (o MySQL aplica da esquerda para a direita: aqui ainda valem o
                        // dono e a nota antigos). Se o técnico deixa pendente de novo, a entrega antiga sai (vale a nota nova).
                        if ($resumingPending && $takeover) { $sets[] = 'handoff_note = pending_note'; $sets[] = 'handoff_from = tech_id'; $sets[] = 'handoff_at = NOW()'; }
                        elseif ($leavingPending) { $sets[] = 'handoff_note = NULL'; $sets[] = 'handoff_from = NULL'; $sets[] = 'handoff_at = NULL'; }
                        foreach (array_keys($changes) as $f) {
                            if ($f === 'techEmail') { $sets[] = 'tech_id = ?'; $vals[] = $tech['id']; }
                            elseif ($f === 'lote') { $sets[] = 'batch_id = ?'; $vals[] = $batchId($t['lote']); }
                            else {
                                $sets[] = self::COL[$f] . ' = ?';
                                $v = $t[$f];
                                $vals[] = is_bool($v) ? (int) $v : (($f === 'obs' || $f === 'pendenteNota') && $v === '' ? null : $v);
                            }
                        }
                        if (isset($changes['pendente'])) $sets[] = $t['pendente'] ? 'pending_since = NOW()' : 'pending_since = NULL';
                        // quem assume uma pendência recomeça do zero: o início do atendimento anterior não conta. Se já iniciou junto, começa agora;
                        // se só assumiu para a fila, o início fica vazio e é gravado quando ele clicar em Iniciar (tempo de espera nunca vira atendimento)
                        if ($resumingPending) $sets[] = $t['emExecucao'] ? 'started_at = NOW()' : 'started_at = NULL';
                        elseif (isset($changes['emExecucao']) && $t['emExecucao']) $sets[] = 'started_at = COALESCE(started_at, NOW())';
                        if (isset($changes['finalizado'])) $sets[] = $t['finalizado'] ? 'finished_at = NOW()' : 'finished_at = NULL';
                        $sets[] = 'version = version + 1';
                        $vals[] = $t['id'];
                        Db::q('UPDATE verifications SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);

                        $ev = $takeover ? 'TASK_TAKEOVER'
                            : (isset($changes['finalizado']) ? ($t['finalizado'] ? 'TASK_FINISH' : 'TASK_REOPEN')
                            : ($leavingPending ? 'TASK_PENDING'
                            : (isset($changes['techEmail']) ? 'TASK_REASSIGN' : 'TASK_UPDATE')));
                        $A($ev, 'verification', $t['id'], $changes);
                        $touchedTasks[$t['id']] = true;

                        // ---- linhas de histórico desta atualização ----
                        $pp = $cur['pppoe'];
                        if (isset($changes['techEmail'])) {
                            $E($t['id'], $pp, $takeover ? 'ASSUMIDA' : 'REATRIBUIDA', array_filter(['de' => $cur['techEmail'], 'para' => $t['techEmail'],
                                'nota' => (!$takeover && $isAdm && $t['nota'] !== '') ? $t['nota'] : null], static fn($v) => $v !== null));
                        }
                        if ($leavingPending) $E($t['id'], $pp, 'PENDENTE', ['nota' => Util::cut($t['pendenteNota'], 300)]);
                        if ($resumingPending && $takeover) $E($t['id'], $pp, 'RETOMADA', array_filter(['de' => $cur['techEmail'], 'para' => $t['techEmail'],
                            'obs_anterior' => $obsAnterior !== '' ? Util::cut($obsAnterior, 2000) : null], static fn($v) => $v !== null));
                        if (isset($changes['emExecucao']) && $t['emExecucao']) $E($t['id'], $pp, 'INICIADA');
                        $chk = array_intersect_key($changes, array_flip(['ligou', 'naoAtende', 'resolvido', 'osCriada']));
                        if ($chk) $E($t['id'], $pp, 'CHECKLIST', $chk);
                        if (isset($changes['actionId'])) $E($t['id'], $pp, 'ACAO', ['de' => $cur['actionId'], 'para' => $t['actionId']]);
                        if (isset($changes['obs']) && !$freshObs) $E($t['id'], $pp, 'OBS', ['texto' => Util::cut((string) $t['obs'], 300)]);
                        $adj = array_intersect_key($changes, array_flip(['date', 'shift', 'priority', 'lote']));
                        if ($adj) $E($t['id'], $pp, 'AJUSTE', $adj);
                        if (isset($changes['finalizado'])) {
                            $E($t['id'], $pp, $t['finalizado'] ? 'CONCLUIDA' : 'REABERTA', $t['finalizado'] ? [
                                'resolvido' => $t['resolvido'], 'osCriada' => $t['osCriada'], 'ligou' => $t['ligou'],
                                'naoAtende' => $t['naoAtende'], 'acao' => $t['actionId'],
                            ] : null);
                        }
                    }

                    // inserção em lote (muito mais rápido que 1 a 1)
                    foreach (array_chunk($toInsert, 500) as $part) {
                        $row = '(' . implode(',', array_fill(0, 21, '?')) . ')';
                        Db::q('INSERT INTO verifications
                               (id, pppoe, batch_id, tech_id, shift, due_date, priority, kind, is_single, prev_count, repeat_of, obs, ligou, nao_atende,
                                resolvido, os_criada, action_id, handle_time_min, em_execucao, finalizado, created_by)
                               VALUES ' . implode(',', array_fill(0, count($part), $row)), array_merge(...$part));
                        foreach ($part as $r) $touchedTasks[$r[0]] = true;
                    }
                    if ($toInsert) {
                        $lotes = array_keys($batchCache);
                        $A('TASKS_CREATE', 'batch', substr(implode(', ', $lotes), 0, 60), ['count' => count($toInsert), 'adicionais' => count(array_filter($toInsert, static fn($r) => $r[7] === 'ADICIONAL')), 'lotes' => array_slice($lotes, 0, 5)]);
                    }
                }

                // ================= USUÁRIOS (exclusão) =================
                if ($p['users']['delete']) {
                    if (!$isAdm) throw new HttpError(403, 'Apenas administradores removem usuários.', 'FORBIDDEN');
                    foreach ($p['users']['delete'] as $uid) {
                        if ($uid === $actor['id']) throw new HttpError(400, 'Você não pode excluir a si mesmo.', 'VALIDATION');
                        $cur = $usersById[$uid] ?? null;
                        if (!$cur) continue;
                        try {
                            Db::q('DELETE FROM users WHERE id = ?', [$uid]);
                        } catch (\PDOException $e) {
                            if (Db::errno($e) === 1451) {
                                throw new HttpError(409, 'Este usuário possui verificações registradas e não pode ser excluído. Bloqueie o acesso em vez disso.', 'CONFLICT');
                            }
                            throw $e;
                        }
                        unset($usersById[$uid], $usersByEmail[$cur['email']]);
                        $A('USER_DELETE', 'user', $uid, ['email' => $cur['email'], 'status' => $cur['status']]);
                    }
                }

                // ================= PROCEDIMENTOS (exclusão lógica) =================
                foreach ($p['actions']['delete'] as $aid) {
                    Db::q("UPDATE actions SET deleted_at = NOW() WHERE id = ? AND id <> 'NONE'", [$aid]);
                    $A('ACTION_DELETE', 'action', $aid, null);
                }

                // Lotes que ficaram sem nenhuma verificação
                if ($p['tasks']['delete']) {
                    Db::q('DELETE FROM batches WHERE id NOT IN (SELECT DISTINCT batch_id FROM verifications)');
                }

                // Invariante: sempre deve restar ao menos um ADM aprovado
                if ((int) Db::val("SELECT COUNT(*) FROM users WHERE role='ADM' AND status='APROVADO'") < 1) {
                    throw new HttpError(400, 'Operação cancelada: o sistema precisa de ao menos um administrador ativo.', 'VALIDATION');
                }

                // Salvamento em tempo real das observações: em vez de uma linha de auditoria a cada pausa na digitação,
                // a última observação do mesmo técnico (nos últimos 15 min) é atualizada no lugar.
                $merged = [];
                foreach ($events as $ev) {
                    if ($ev[3] === 'OBS') {
                        $last = Db::one('SELECT id, event, user_id FROM verification_events WHERE verification_id = ? ORDER BY id DESC LIMIT 1', [$ev[0]]);
                        if ($last && $last['event'] === 'OBS' && $last['user_id'] === $ev[2]
                            && Db::one('SELECT 1 AS ok FROM verification_events WHERE id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)', [$last['id']])) {
                            Db::q('UPDATE verification_events SET details = ?, created_at = NOW() WHERE id = ?', [$ev[4], $last['id']]);
                            continue;
                        }
                    }
                    $merged[] = $ev;
                }
                $events = $merged;
                foreach (array_chunk($events, 500) as $part) {
                    Db::q('INSERT INTO verification_events (verification_id, pppoe, user_id, event, details) VALUES '
                        . implode(',', array_fill(0, count($part), '(?,?,?,?,?)')), array_merge(...$part));
                }

                foreach (array_chunk($audits, 500) as $part) {
                    Db::q('INSERT INTO audit_log (user_id, event, entity, entity_id, details, ip) VALUES '
                        . implode(',', array_fill(0, count($part), '(?,?,?,?,?,?)')), array_merge(...$part));
                }
            });
        } catch (HttpError $e) {
            throw $e;
        } catch (\PDOException $e) {
            $n = Db::errno($e);
            if ($n === 1062) throw self::friendlyDup($e);
            if ($n === 1452) throw new HttpError(400, 'Referência inválida (procedimento, usuário ou verificação inexistente).', 'VALIDATION');
            if ($n === 1213 || $n === 1205) throw new HttpError(409, 'Muitas alterações simultâneas. Os dados foram recarregados — tente de novo.', 'CONFLICT');
            throw $e;
        }

        // Devolve as linhas canônicas (com a versão nova) para a interface mesclar
        $result = ['ok' => true, 'tasks' => [], 'users' => [], 'reissueSelf' => $reissueSelf];
        foreach (array_chunk(array_keys($touchedTasks), 1000) as $part) {
            foreach (Db::all('SELECT ' . Mappers::VERIF_SELECT . ' WHERE v.id IN (' . Db::marks($part) . ')', $part) as $r) {
                $result['tasks'][] = Mappers::verif($r);
            }
        }
        if ($touchedUsers) {
            $ids = array_map('strval', array_keys($touchedUsers));
            foreach (Db::all('SELECT id, name, email, role, shift, status FROM users WHERE id IN (' . Db::marks($ids) . ')', $ids) as $r) {
                $result['users'][] = Mappers::user($r);
            }
        }
        return $result;
    }
}
