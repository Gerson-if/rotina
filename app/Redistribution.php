<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Redistribuição automática das verificações "Pendentes de contato" (a fila central) entre os técnicos elegíveis.
 *
 * Princípios:
 *  - A fila central é a própria área "Pendentes de contato" (pending_contact = 1). Nada é criado ou copiado:
 *    redistribuir é trocar o tech_id DA MESMA verificação (igual ao "Assumir" manual), então histórico, prazo,
 *    observações e classe ficam intactos e o índice único active_pppoe garante que não haja duplicidade.
 *  - Quem recebe NÃO depende de o técnico estar online. Elegível = técnico aprovado, não bloqueado, de um turno habilitado
 *    (ou "GERAL") e com a fila abaixo do teto. Entre os elegíveis, escolhe-se pela carga de trabalho (ou rodízio).
 *  - Sem técnico elegível, a verificação simplesmente continua na fila central (rede de segurança).
 *  - Cada troca é um UPDATE condicional (pendente, não concluída, mesma version): se um técnico assumir à mão
 *    no mesmo instante, quem chegou primeiro vence e a outra tentativa é ignorada.
 *  - Desligado por padrão. Não conta nem cobra ninguém: só mantém o trabalho andando e equilibra a carga.
 *
 * Configuração (JSON curto em app_state.redist, cabe nos 255 caracteres):
 *   on    0|1          liga/desliga
 *   when  C            sempre contínuo: a rotina roda sozinha a cada ciclo, depois da espera (mantido por compatibilidade)
 *   how   L|R          L = menor carga · R = rodízio
 *   wait  minutos      espera mínima para o técnico assumir a pendência; passou disso, o sistema distribui sozinho
 *   cap   n            teto de fila por técnico; acima disso ele não recebe
 *   max   n            máximo de redistribuições por verificação; depois dela fica na fila central p/ o ADM
 *   en    [turnos]     turnos habilitados a receber
 */
final class Redistribution
{
    public const SHIFTS = ['MANHA', 'TARDE', 'NOITE', 'MADRUGADA'];
    public const SHIFT_NAMES = ['MANHA' => 'Manhã', 'TARDE' => 'Tarde', 'NOITE' => 'Noite', 'MADRUGADA' => 'Madrugada'];
    public const DEFAULT_TIMES = ['MANHA' => '06:00', 'TARDE' => '12:00', 'NOITE' => '18:00', 'MADRUGADA' => '00:00'];

    private const KEY = 'redist';
    private const LOCK = 'rotina_job_redistribute';
    // Intervalo mínimo entre execuções automáticas. Fica um pouco abaixo de 60 s DE PROPÓSITO: o cron dispara "a cada minuto" com
    // alguns décimos de segundo de variação; com 60 exatos, um disparo que chegasse 1 s adiantado seria descartado e a rotina
    // passaria a rodar só de 2 em 2 minutos.
    private const TICK_SECONDS = 55;
    private const PER_RUN = 200;         // máximo por execução (o resto sai na próxima)
    private const PLAN_PREVIEW = 50;     // linhas mostradas na simulação

    // ------------------------------------------------------------------ configuração

    public static function defaults(): array
    {
        return ['on' => 0, 'when' => 'C', 'how' => 'L', 'wait' => 30, 'win' => 120, 'cap' => 15, 'max' => 3,
                'en' => self::SHIFTS, 'sh' => self::DEFAULT_TIMES];
    }

    /** Valida/normaliza. $strict = true (salvar): erro claro em português; false (ler do banco): corrige em silêncio. */
    public static function normalize(array $in, bool $strict = false): array
    {
        $c = self::defaults();
        $bad = static function (string $m) use ($strict): void { if ($strict) throw new HttpError(400, $m, 'VALIDATION'); };

        $c['on'] = !empty($in['on']) ? 1 : 0;
        foreach (['when' => ['A', 'C'], 'how' => ['L', 'R']] as $k => $allowed) {
            if (!array_key_exists($k, $in)) continue;
            if (in_array($in[$k], $allowed, true)) $c[$k] = $in[$k]; else $bad('Opção inválida em "' . ($k === 'when' ? 'Quando distribuir' : 'Como escolher o técnico') . '".');
        }
        $num = static function (string $k, int $min, int $max, string $label) use (&$c, $in, $bad): void {
            if (!array_key_exists($k, $in)) return;
            $v = $in[$k];
            if (!is_numeric($v) || (int) $v != $v || $v < $min || $v > $max) { $bad("$label: informe um número inteiro entre $min e $max."); return; }
            $c[$k] = (int) $v;
        };
        $num('wait', 0, 720, 'Espera mínima (minutos)');
        $num('win', 15, 720, 'Janela após a abertura (minutos)');
        $num('cap', 1, 200, 'Limite de fila por técnico');
        $num('max', 1, 10, 'Máximo de redistribuições');

        if (array_key_exists('en', $in)) {
            if (is_array($in['en'])) $c['en'] = array_values(array_intersect(self::SHIFTS, $in['en']));
            else $bad('Turnos habilitados inválidos.');
        }
        if (isset($in['sh']) && is_array($in['sh'])) {
            foreach (self::SHIFTS as $s) {
                if (!isset($in['sh'][$s])) continue;
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['sh'][$s])) $c['sh'][$s] = (string) $in['sh'][$s];
                else $bad('Horário inválido no turno ' . self::SHIFT_NAMES[$s] . ' (use HH:MM, ex.: 06:00).');
            }
        }
        if ($c['on'] && !$c['en']) $bad('Habilite ao menos um turno para ligar a redistribuição.');
        $c['when'] = 'C';   // não há mais janela de abertura: a rotina distribui a cada ciclo, depois da espera
        return $c;
    }

    public static function config(): array
    {
        $raw = Db::val('SELECT v FROM app_state WHERE k = ?', [self::KEY]);
        $a = $raw ? json_decode((string) $raw, true) : null;
        return self::normalize(is_array($a) ? $a : []);
    }

    public static function save(array $in): array
    {
        $c = self::normalize($in, true);
        $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen((string) $json) > 255) throw new HttpError(400, 'Configuração grande demais.', 'VALIDATION');
        self::setState(self::KEY, (string) $json);
        return $c;
    }

    private static function state(string $k): ?string
    {
        $v = Db::val('SELECT v FROM app_state WHERE k = ?', [$k]);
        return $v === null ? null : (string) $v;
    }
    private static function setState(string $k, string $v): void
    {
        Db::q('INSERT INTO app_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }

    // ------------------------------------------------------------------ regras puras (sem banco: testáveis)

    /**
     * Escolhe quem recebe. $cands: lista de ['id','name','shift','load','got'] (load = fila atual; got = recebidas hoje).
     * - ninguém acima do teto $cap;
     * - evita devolver ao mesmo técnico que deixou ($donorId), a menos que seja o único;
     * - 'L' (menos trabalho), nesta ordem: 1º o turno com menor carga média por técnico (equilibra os turnos);
     *   2º o técnico com menos verificações; 3º quem recebeu menos hoje; 4º desempate que GIRA (o próximo, em ordem de id,
     *   depois de quem recebeu por último), para que o mesmo nome nunca seja sempre o primeiro da fila;
     * - 'R' rodízio em ordem estável de id, após $rr.
     */
    public static function pick(array $cands, int $cap, string $how, ?string $donorId, ?string $rr): ?array
    {
        $ok = array_values(array_filter($cands, static fn($c) => $c['load'] < $cap));
        if (!$ok) return null;
        $other = array_values(array_filter($ok, static fn($c) => $c['id'] !== $donorId));
        if ($other) $ok = $other;
        $after = static fn(string $id): int => ($rr !== null && strcmp($id, $rr) > 0) ? 0 : 1;   // 0 = vem depois do último que recebeu
        if ($how === 'R') {
            usort($ok, static fn($a, $b) => [$after($a['id']), $a['id']] <=> [$after($b['id']), $b['id']]);
            return $ok[0];
        }
        $sum = []; $cnt = [];
        foreach ($ok as $c) { $g = (string) ($c['shift'] ?? ''); $sum[$g] = ($sum[$g] ?? 0) + $c['load']; $cnt[$g] = ($cnt[$g] ?? 0) + 1; }
        $avg = static fn(array $c): float => $sum[(string) ($c['shift'] ?? '')] / $cnt[(string) ($c['shift'] ?? '')];
        usort($ok, static fn($a, $b) => [$avg($a), $a['load'], $a['got'], $after($a['id']), $a['id']] <=> [$avg($b), $b['load'], $b['got'], $after($b['id']), $b['id']]);
        return $ok[0];
    }

    // ------------------------------------------------------------------ execução

    /** Barato: roda a cada chamada de /stamp. Só devolve true quando está ligado e já passou o intervalo. */
    public static function due(): bool
    {
        $m = array_column(Db::all("SELECT k, v FROM app_state WHERE k IN ('redist', 'redist_ts')"), 'v', 'k');
        if (!isset($m[self::KEY])) return false;
        $a = json_decode((string) $m[self::KEY], true);
        if (!is_array($a) || empty($a['on'])) return false;
        return time() - (int) ($m['redist_ts'] ?? 0) >= self::TICK_SECONDS;
    }

    /**
     * Execução automática. Quem chama: o agendador do servidor (cron / worker, via Jobs::runDue, SEM precisar de ninguém logado)
     * e, de carona, o /stamp, o bootstrap e a tela de login. Um lock evita execuções simultâneas.
     */
    public static function tick(?callable $log = null): void
    {
        if (!Db::lock(self::LOCK, 0)) return;
        try {
            if (!self::due()) return;                       // outro processo pode ter acabado de rodar
            self::setState('redist_ts', (string) time());
            try { Vacation::sweep(); } catch (\Throwable $e) { Util::log('vacation', $e->getMessage()); }   // quem entrou de férias devolve o que não começou
            $cfg = self::config();
            try {
                $r = self::execute($cfg, false, false, 'auto', null, '');
            } catch (\Throwable $e) {
                self::logRun('auto', ['state' => 'error', 'moved' => 0, 'left' => 0, 'message' => 'Erro: ' . Util::cut($e->getMessage(), 200)], $cfg);
                throw $e;
            }
            self::logRun('auto', $r, $cfg);
            if ($log && $r['moved'] > 0) $log("redistribuição: {$r['moved']} verificação(ões) distribuída(s)");
        } finally {
            Db::unlock(self::LOCK);
        }
    }

    /** Botões "Simular" e "Executar agora" do ADM: distribui já, entre os técnicos elegíveis dos turnos habilitados (ignora espera e janela). */
    public static function runNow(bool $dry, ?string $actorId, string $ip): array
    {
        $cfg = self::config();
        if ($dry) return self::execute($cfg, true, true, 'manual', $actorId, $ip);
        if (!$cfg['on']) throw new HttpError(400, 'A redistribuição está desligada. Ligue e salve antes de executar.', 'VALIDATION');
        if (!Db::lock(self::LOCK, 0)) throw new HttpError(409, 'Já existe uma redistribuição em andamento. Tente de novo em instantes.', 'CONFLICT');
        try {
            try { Vacation::sweep(); } catch (\Throwable $e) { Util::log('vacation', $e->getMessage()); }
            $r = self::execute($cfg, false, true, 'manual', $actorId, $ip);
            self::logRun('manual', $r, $cfg);
        } finally {
            Db::unlock(self::LOCK);
        }
        Util::audit($actorId, 'REDIST_RUN', 'system', null, ['movidas' => $r['moved'], 'restam' => $r['left']], $ip ?: null);
        return $r;
    }

    /**
     * Técnicos elegíveis: aprovados, não bloqueados, FORA DE FÉRIAS, dos turnos que recebem (técnico "GERAL" vale para qualquer turno habilitado).
     * Presença (online/offline) NÃO entra na conta: o que decide é o turno habilitado, o teto de fila e a carga de trabalho.
     * Carga = verificações abertas e não pendentes. Devolve [id => ['id','name','email','shift','load','got']].
     */
    private static function eligible(array $shifts): array
    {
        $in = array_merge($shifts, ['GERAL']);
        $rows = Db::all('SELECT u.id, u.name, u.email, u.shift,
                                (SELECT COUNT(*) FROM verifications v WHERE v.tech_id = u.id AND v.finalizado = 0 AND v.pending_contact = 0) AS load_n,
                                (SELECT COUNT(*) FROM verifications v WHERE v.tech_id = u.id AND v.redist_at >= CURDATE()) AS got_n
                           FROM users u
                          WHERE u.role = \'TEC\' AND u.status = \'APROVADO\' AND (u.locked_until IS NULL OR u.locked_until < NOW())
                            AND NOT ' . Vacation::activeSql('u') . '
                            AND u.shift IN (' . Db::marks($in) . ')
                          ORDER BY u.id', $in);
        $cands = [];
        foreach ($rows as $r) $cands[$r['id']] = ['id' => $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'shift' => $r['shift'], 'load' => (int) $r['load_n'], 'got' => (int) $r['got_n']];
        return $cands;
    }

    /**
     * NOVAS DEMANDAS (atribuição por planilha ou única em modo automático): aplica a mesma regra e devolve, na ordem,
     * quem recebe cada uma das $n verificações. Considera a carga ATUAL de cada técnico e equilibra entre os turnos.
     * Não grava verificação nenhuma (quem cria é o fluxo normal de atribuição). O teto de fila não bloqueia: quem cria
     * uma demanda decidiu criá-la; a divisão equilibrada é que evita sobrecarregar sempre o mesmo.
     * $commit = true (só na confirmação) guarda onde o rodízio parou; a prévia não mexe em nada.
     */
    public static function suggest(int $n, bool $commit = false): array
    {
        $cfg = self::config();
        if (!$cfg['en']) throw new HttpError(400, 'Habilite ao menos um turno na página Redistribuição.', 'VALIDATION');
        $cands = self::eligible($cfg['en']);
        if (!$cands) throw new HttpError(400, 'Nenhum técnico aprovado e liberado nos turnos habilitados. Veja a página Redistribuição.', 'VALIDATION');
        $rr = self::state('redist_rr');
        $plan = []; $sum = [];
        for ($i = 0; $i < $n; $i++) {
            $t = self::pick($cands, PHP_INT_MAX, $cfg['how'], null, $rr);
            if (!$t) break;
            $plan[] = ['email' => $t['email'], 'name' => $t['name'], 'shift' => $t['shift']];
            $sum[$t['id']] = ($sum[$t['id']] ?? ['name' => $t['name'], 'shift' => $t['shift'], 'before' => $t['load'], 'count' => 0]);
            $sum[$t['id']]['count']++;
            $cands[$t['id']]['load']++; $cands[$t['id']]['got']++;
            $rr = $t['id'];
        }
        if ($commit && $plan) self::setState('redist_rr', (string) $rr);
        $byShift = [];
        foreach ($sum as $r) $byShift[$r['shift']] = ($byShift[$r['shift']] ?? 0) + $r['count'];
        return ['plan' => $plan, 'techs' => array_values($sum), 'shifts' => $byShift];
    }

    private static function poolSize(): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM verifications WHERE pending_contact = 1 AND finalizado = 0');
    }

    private static function execute(array $cfg, bool $dry, bool $force, string $by, ?string $actorId, string $ip): array
    {
        $res = ['dry' => $dry, 'state' => 'ok', 'moved' => 0, 'left' => 0, 'plan' => [], 'message' => ''];
        $done = static function (string $state, string $msg) use (&$res): array {
            $res['state'] = $state; $res['message'] = $msg; $res['left'] = self::poolSize(); return $res;
        };

        // Todos os turnos habilitados podem receber a qualquer hora; o que segura é só a espera mínima (abaixo).
        $shifts = $cfg['en'];
        if (!$shifts) return $done('no_shift', 'Nenhum turno habilitado.');

        // A espera mínima não vale quando quem deixou a pendência está de férias (não vai voltar para pegá-la).
        $wait = !$force ? ' AND (v.pending_since <= NOW() - INTERVAL ' . (int) $cfg['wait'] . ' MINUTE OR ' . Vacation::activeSql('d') . ')' : '';
        $pool = Db::all("SELECT v.id, v.pppoe, v.tech_id, v.priority, v.version, v.obs, d.email AS donor_email
                           FROM verifications v JOIN users d ON d.id = v.tech_id
                          WHERE v.pending_contact = 1 AND v.finalizado = 0 AND v.redist_count < ?$wait
                          ORDER BY FIELD(v.priority, 'URGENTE', 'ALTA', 'NORMAL'), v.pending_since, v.id LIMIT " . self::PER_RUN, [$cfg['max']]);
        if (!$pool) {
            // Por que não há o que distribuir? (vazia, ainda na espera ou esgotadas) — o ADM precisa saber que o motor rodou e o motivo.
            $b = self::breakdown($cfg, $force);
            if ($b['total'] === 0) return $done('empty', 'Fila central vazia.');
            if ($b['waiting'] > 0) return $done('waiting', "{$b['waiting']} pendência(s) aguardando a espera mínima de {$cfg['wait']} min" . ($b['next_s'] !== null ? ' (a próxima libera em ' . self::dur((int) $b['next_s']) . ')' : '') . '.');
            return $done('stuck', "{$b['stuck']} pendência(s) esgotaram o limite de trocas: cabe a você designar.");
        }

        $cands = self::eligible($shifts);
        if (!$cands) return $done('no_eligible', 'Nenhum técnico aprovado, liberado e fora de férias nos turnos habilitados. As pendências continuam na fila central.');
        $res['eligible'] = count($cands);

        $rr = self::state('redist_rr');
        foreach ($pool as $p) {
            $t = self::pick($cands, $cfg['cap'], $cfg['how'], $p['tech_id'], $rr);
            if (!$t) { $res['full'] = true; break; }         // todos no teto: o resto segue na fila central
            if (!$dry && !self::assign($p, $t, $cfg, $by, $actorId, $ip)) continue;   // alguém já mexeu nela: ignora
            $cands[$t['id']]['load']++; $cands[$t['id']]['got']++;
            $rr = $t['id'];
            $res['moved']++;
            if (count($res['plan']) < self::PLAN_PREVIEW) $res['plan'][] = ['pppoe' => $p['pppoe'], 'priority' => $p['priority'], 'to' => $t['name']];
        }

        if (!$dry && $res['moved'] > 0) {
            self::setState('redist_rr', (string) $rr);
            self::setState('redist_last', json_encode(['at' => date('Y-m-d H:i:s'), 'n' => $res['moved'], 'by' => $by], JSON_UNESCAPED_UNICODE));
        }
        $left = self::poolSize();
        $res['left'] = $left;
        if (!empty($res['full']) && $res['moved'] === 0) $res['state'] = 'all_full';
        elseif (!empty($res['full'])) $res['state'] = 'partial_full';
        $res['message'] = ($dry ? "Simulação: {$res['moved']} verificação(ões) seriam distribuídas. Nada foi alterado." : "{$res['moved']} verificação(ões) distribuída(s).")
            . ($left > 0 ? " $left continuam na fila central" . (!empty($res['full']) ? ' (todos os técnicos no limite de fila).' : '.') : '');
        return $res;
    }

    /** Troca o dono DA MESMA verificação, de forma atômica. false = não era mais elegível (alguém assumiu/concluiu antes). */
    private static function assign(array $p, array $t, array $cfg, string $by, ?string $actorId, string $ip, bool $manual = false): bool
    {
        return (bool) Db::tx(static function () use ($p, $t, $cfg, $by, $actorId, $ip, $manual): bool {
            // A nota de quem deixou vai junto (handoff_*), e vem ANTES de trocar o dono: o MySQL aplica as atribuições da esquerda para a direita.
            // Igual ao "Assumir para minha fila": novo dono, sai da fila central, atendimento zerado (a espera nunca vira tempo de atendimento)
            // Quem recebe começa com as observações em branco (o texto anterior vai para a auditoria); só não limpa se a pendência voltar para o mesmo técnico.
            $fresh = $p['tech_id'] !== $t['id'] && (string) ($p['obs'] ?? '') !== '';
            $n = Db::q('UPDATE verifications
                           SET handoff_note = pending_note, handoff_from = tech_id, handoff_at = NOW(), handoff_seen_at = NULL, pending_note = NULL, reassigned_by = NULL, reassigned_at = NULL,' . ($fresh ? ' obs = NULL,' : '') . '
                               tech_id = ?, pending_contact = 0, pending_since = NULL, em_execucao = 0, started_at = NULL,
                               redist_count = ' . ($manual ? '0' : 'redist_count + 1') . ', redist_at = NOW(), version = version + 1
                         WHERE id = ? AND pending_contact = 1 AND finalizado = 0 AND version = ?', [$t['id'], $p['id'], $p['version']])->rowCount();
            if ($n !== 1) return false;
            $crit = $manual ? 'MANUAL' : ($cfg['how'] === 'R' ? 'RODIZIO' : 'MENOR_CARGA');
            $when = 'CONTINUO';
            // Automática: ação do SISTEMA (sem autor, texto neutro). Designação manual: o ADM aparece como autor.
            // Quem deixou a pendência aparece só na auditoria do ADM.
            Db::q('INSERT INTO verification_events (verification_id, pppoe, user_id, event, details) VALUES (?, ?, ?, ?, ?)',
                [$p['id'], $p['pppoe'], $manual ? $actorId : null, $manual ? 'DESIGNADA' : 'REDISTRIBUIDA', Util::json(array_filter(['para' => $t['email'], 'criterio' => $crit, 'quando' => $when, 'origem' => $by,
                    'obs_anterior' => $fresh ? Util::cut((string) $p['obs'], 2000) : null], static fn($v) => $v !== null))]);
            Util::audit($actorId, $manual ? 'TASK_ASSIGN_PENDING' : 'TASK_REDISTRIBUTE', 'verification', $p['id'],
                ['de' => $p['donor_email'], 'para' => $t['email'], 'criterio' => $crit, 'quando' => $when, 'origem' => $by], $ip ?: null);
            return true;
        });
    }

    /**
     * Painel Pendências (ADM): passa UMA pendência da fila central para um técnico.
     *  - $techId informado: designação manual (qualquer técnico aprovado e liberado, mesmo fora dos turnos da rotina; zera as trocas);
     *  - $techId nulo: usa a regra da Redistribuição automática (turnos habilitados, menos trabalho, limite por técnico) só para esta pendência.
     * Mesma troca atômica da rotina (nota, observações em branco e histórico), então não duplica nem atropela quem assumiu antes.
     */
    public static function designate(string $verifId, ?string $techId, string $actorId, string $ip): array
    {
        $p = Db::one('SELECT v.id, v.pppoe, v.tech_id, v.priority, v.version, v.obs, d.email AS donor_email
                        FROM verifications v JOIN users d ON d.id = v.tech_id
                       WHERE v.id = ? AND v.pending_contact = 1 AND v.finalizado = 0', [$verifId]);
        if (!$p) throw new HttpError(409, 'Esta pendência não está mais na fila central (alguém assumiu ou concluiu). Atualize a tela.', 'CONFLICT');
        $cfg = self::config();
        $manual = $techId !== null;
        if ($manual) {
            $u = Db::one("SELECT id, name, email, shift FROM users
                           WHERE id = ? AND role = 'TEC' AND status = 'APROVADO' AND (locked_until IS NULL OR locked_until < NOW())", [$techId]);
            if (!$u) throw new HttpError(400, 'Esse técnico não está disponível (precisa estar aprovado e liberado).', 'VALIDATION');
            if ($until = Vacation::activeUntil($u['id'])) throw Vacation::refuse($u['name'], $until);
            $t = ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'shift' => $u['shift']];
        } else {
            if (!$cfg['en']) throw new HttpError(400, 'Habilite ao menos um turno na página Redistribuição para usar o automático.', 'VALIDATION');
            $t = self::pick(self::eligible($cfg['en']), $cfg['cap'], $cfg['how'], $p['tech_id'], self::state('redist_rr'));
            if (!$t) throw new HttpError(409, 'Nenhum técnico disponível agora: os turnos habilitados estão sem técnico ou todos no limite de verificações.', 'CONFLICT');
        }
        if (!self::assign($p, $t, $cfg, $manual ? 'adm' : 'manual', $actorId, $ip, $manual)) {
            throw new HttpError(409, 'Alguém assumiu esta pendência antes de você. Atualize a tela.', 'CONFLICT');
        }
        if (!$manual) self::setState('redist_rr', (string) $t['id']);
        return ['ok' => true, 'to' => $t['name'], 'manual' => $manual];
    }

    // ------------------------------------------------------------------ diagnóstico (o que o motor está fazendo e por quê)

    private static function dur(int $sec): string
    {
        $m = (int) ceil(max(0, $sec) / 60);
        return $m < 1 ? 'menos de 1 min' : ($m < 60 ? "$m min" : floor($m / 60) . 'h' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT));
    }

    /** Fila central por situação, numa consulta: total, prontas, aguardando a espera, esgotadas e quanto falta para a próxima liberar. */
    private static function breakdown(array $cfg, bool $force = false): array
    {
        $wait = (int) $cfg['wait']; $max = (int) $cfg['max'];
        $vac = Vacation::activeSql('d');
        $free = $force ? '1 = 1' : "(v.pending_since <= NOW() - INTERVAL $wait MINUTE OR $vac)";
        $r = Db::one("SELECT COUNT(*) AS total,
                             COALESCE(SUM(v.redist_count >= $max), 0) AS stuck,
                             COALESCE(SUM(v.redist_count < $max AND $free), 0) AS ready,
                             COALESCE(SUM(v.redist_count < $max AND NOT ($free)), 0) AS waiting,
                             MIN(CASE WHEN v.redist_count < $max AND NOT ($free) THEN TIMESTAMPDIFF(SECOND, NOW(), v.pending_since + INTERVAL $wait MINUTE) END) AS next_s,
                             COALESCE(SUM($vac), 0) AS from_vac
                        FROM verifications v JOIN users d ON d.id = v.tech_id
                       WHERE v.pending_contact = 1 AND v.finalizado = 0");
        return ['total' => (int) $r['total'], 'stuck' => (int) $r['stuck'], 'ready' => (int) $r['ready'], 'waiting' => (int) $r['waiting'],
                'next_s' => $r['next_s'] === null ? null : max(0, (int) $r['next_s']), 'fromVacation' => (int) $r['from_vac']];
    }

    /** Técnicos por situação: quem pode receber agora e por que os demais não podem. */
    private static function techBreakdown(array $cfg): array
    {
        $o = ['total' => 0, 'can' => 0, 'full' => 0, 'vacation' => 0, 'outShift' => 0, 'locked' => 0];
        $rows = Db::all("SELECT u.shift, (u.locked_until IS NOT NULL AND u.locked_until >= NOW()) AS locked, " . Vacation::activeSql('u') . " AS vac,
                                (SELECT COUNT(*) FROM verifications v WHERE v.tech_id = u.id AND v.finalizado = 0 AND v.pending_contact = 0) AS load_n
                           FROM users u WHERE u.role = 'TEC' AND u.status = 'APROVADO'");
        foreach ($rows as $r) {
            $o['total']++;
            if ((int) $r['locked'] === 1) $o['locked']++;
            elseif ((int) $r['vac'] === 1) $o['vacation']++;
            elseif ($r['shift'] !== 'GERAL' && !in_array($r['shift'], $cfg['en'], true)) $o['outShift']++;
            elseif ((int) $r['load_n'] >= $cfg['cap']) $o['full']++;
            else $o['can']++;
        }
        return $o;
    }

    /** Guarda o resultado de um ciclo: sempre o "último ciclo" (sinal de vida) e, no histórico, só quando algo mudou. */
    private static function logRun(string $source, array $r, array $cfg): void
    {
        try {
            $b = self::breakdown($cfg, $source === 'manual');
            $eligible = (int) ($r['eligible'] ?? 0);
            $st = (string) ($r['state'] ?? 'ok');
            self::setState('redist_run', (string) json_encode(['at' => time(), 'by' => $source, 'st' => $st, 'mv' => (int) $r['moved'], 'pool' => $b['total'], 'msg' => Util::cut((string) ($r['message'] ?? ''), 120)], JSON_UNESCAPED_UNICODE));
            if ($st === 'empty' && (int) $r['moved'] === 0) { self::setState('redist_sig', 'empty'); return; }   // fila vazia: nada a registrar
            $sig = implode('|', [$st, $b['total'], $b['waiting'], $b['stuck'], $eligible]);
            if ((int) $r['moved'] === 0 && $sig === self::state('redist_sig')) return;                           // nada mudou desde o último registro
            self::setState('redist_sig', $sig);
            Db::q('INSERT INTO redistribution_runs (source, state, moved, pool, waiting, stuck, eligible, message) VALUES (?,?,?,?,?,?,?,?)',
                [$source, $st, (int) $r['moved'], $b['total'], $b['waiting'], $b['stuck'], $eligible, Util::cut((string) ($r['message'] ?? ''), 250)]);
            if (mt_rand(1, 40) === 1) Db::q('DELETE FROM redistribution_runs WHERE ran_at < NOW() - INTERVAL 30 DAY');
        } catch (\Throwable $e) {
            Util::log('redist_log', $e->getMessage());   // ex.: migration 019 ainda não aplicada: o motor segue funcionando
        }
    }

    /** Lista de verificações de saúde para o ADM: cada item diz o que está certo, o que merece atenção e o porquê. */
    private static function checks(array $cfg, array $pool, array $tech, array $shifts, ?array $engine, array $sched): array
    {
        $c = [];
        $add = static function (string $level, string $title, string $detail = '') use (&$c): void { $c[] = ['level' => $level, 'title' => $title, 'detail' => $detail]; };
        if (!$cfg['on']) {
            $add('warn', 'Redistribuição desligada', 'As pendências ficam na fila central até alguém assumir ou você designar. Ligue para o sistema distribuir sozinho.');
        } else {
            $add('ok', 'Redistribuição ligada', "Depois de {$cfg['wait']} min sem ninguém assumir, o sistema distribui.");
            if (!$engine) $add('warn', 'O motor ainda não rodou', 'Aguarde 1 minuto. Se continuar assim, o agendador do servidor não está disparando a rotina.');
            elseif ($engine['ageSec'] > 180) $add('bad', 'O motor parou de rodar', 'O último ciclo foi há ' . self::dur($engine['ageSec']) . '. Confira o agendador (sudo ./rotina cron-install) ou abra o sistema para ele rodar de carona.');
            else $add('ok', 'Motor em funcionamento', 'Último ciclo há ' . self::dur($engine['ageSec']) . ($engine['message'] !== '' ? ': ' . $engine['message'] : '.'));
            if (!$sched['active']) $add('warn', 'Agendador do servidor inativo', 'A rotina só roda enquanto alguém usa o sistema. Para rodar sozinha: sudo ./rotina cron-install.');
        }
        foreach ($shifts as $s) {
            if ($s['enabled'] && $s['apt'] === 0 && $s['geral'] === 0) $add('warn', "Turno {$s['name']} sem técnico disponível", 'Está habilitado para receber, mas ninguém dele pode receber agora (férias, bloqueio ou fila cheia). Ninguém será escolhido por esse turno.');
        }
        if ($tech['can'] === 0) $add('bad', 'Nenhum técnico pode receber agora', self::whyNone($tech));
        else $add('ok', "{$tech['can']} técnico(s) podem receber agora", self::techDetail($tech));
        if ($pool['total'] === 0) {
            $add('ok', 'Fila central vazia', 'Nada para distribuir.');
        } else {
            $parts = [];
            if ($pool['ready'] > 0) $parts[] = "{$pool['ready']} pronta(s) para distribuir";
            if ($pool['waiting'] > 0) $parts[] = "{$pool['waiting']} aguardando a espera" . ($pool['next_s'] !== null ? ' (próxima em ' . self::dur($pool['next_s']) . ')' : '');
            if ($pool['stuck'] > 0) $parts[] = "{$pool['stuck']} esgotaram o limite de trocas";
            $add($pool['stuck'] > 0 ? 'warn' : 'info', "{$pool['total']} na fila central", implode(' · ', $parts));
            if ($cfg['on'] && $pool['ready'] > 0 && $tech['can'] === 0) $add('bad', "{$pool['ready']} pronta(s) sem ninguém para receber", 'O sistema não tem para quem passar. Libere um técnico, aumente o limite de fila ou designe manualmente.');
            elseif ($cfg['on'] && $pool['ready'] > 0 && $engine && $engine['ageSec'] <= 180 && $engine['moved'] === 0 && !in_array($engine['state'], ['ok', 'partial_full'], true))
                $add('warn', 'Há pendência pronta e o último ciclo não moveu', $engine['message']);
        }
        if ($pool['fromVacation'] > 0) $add('info', "{$pool['fromVacation']} pendência(s) de técnico de férias", 'Não esperam o tempo mínimo: o sistema as distribui assim que houver técnico disponível.');
        return $c;
    }

    private static function techDetail(array $t): string
    {
        $o = [];
        if ($t['full']) $o[] = "{$t['full']} no limite de fila";
        if ($t['vacation']) $o[] = "{$t['vacation']} de férias";
        if ($t['outShift']) $o[] = "{$t['outShift']} em turno não habilitado";
        if ($t['locked']) $o[] = "{$t['locked']} com acesso travado";
        return $o ? ('Não recebem: ' . implode(', ', $o) . '.') : 'Ninguém fora do jogo.';
    }
    private static function whyNone(array $t): string
    {
        return $t['total'] === 0 ? 'Não há técnicos aprovados.' : self::techDetail($t);
    }

    // ------------------------------------------------------------------ painel do ADM

    public static function overview(): array
    {
        $cfg = self::config();
        $pool = Db::one('SELECT COUNT(*) AS n, MIN(pending_since) AS oldest, COALESCE(SUM(redist_count >= ?), 0) AS stuck
                           FROM verifications WHERE pending_contact = 1 AND finalizado = 0', [$cfg['max']]);
        $people = [];
        foreach (Db::all('SELECT shift, COUNT(*) AS total, COALESCE(SUM((locked_until IS NULL OR locked_until < NOW()) AND NOT ' . Vacation::activeSql('users') . '), 0) AS apt,
                                 COALESCE(SUM(' . Vacation::activeSql('users') . '), 0) AS vac
                            FROM users WHERE role = \'TEC\' AND status = \'APROVADO\' GROUP BY shift') as $r) $people[$r['shift']] = $r;
        $geral = (int) ($people['GERAL']['apt'] ?? 0);
        $shifts = [];
        foreach (self::SHIFTS as $s) {
            $shifts[] = ['key' => $s, 'name' => self::SHIFT_NAMES[$s], 'enabled' => in_array($s, $cfg['en'], true),
                         'apt' => (int) ($people[$s]['apt'] ?? 0), 'total' => (int) ($people[$s]['total'] ?? 0), 'vac' => (int) ($people[$s]['vac'] ?? 0), 'geral' => $geral];
        }
        $last = self::state('redist_last');
        // O agendador do servidor (cron/worker) deixa um sinal de vida a cada execução. Sem ele, a rotina só roda com alguém usando o sistema.
        $beat = (int) self::state('sched_ts');
        $sched = ['active' => $beat > 0 && time() - $beat < 180, 'last' => $beat > 0 ? date('Y-m-d H:i:s', $beat) : null];

        $rr = self::state('redist_run');
        $ra = $rr ? json_decode($rr, true) : null;
        $engine = is_array($ra) && isset($ra['at']) ? ['at' => date('Y-m-d H:i:s', (int) $ra['at']), 'ageSec' => max(0, time() - (int) $ra['at']), 'by' => (string) ($ra['by'] ?? ''),
                    'state' => (string) ($ra['st'] ?? ''), 'moved' => (int) ($ra['mv'] ?? 0), 'message' => (string) ($ra['msg'] ?? '')] : null;
        $bd = self::breakdown($cfg);
        $tb = self::techBreakdown($cfg);

        // últimas movimentações (para o ADM ver as redistribuições acontecendo)
        $recent = [];
        $ev = Db::all("SELECT e.id, e.created_at, e.event, e.pppoe, e.details FROM verification_events e
                        WHERE e.event IN ('REDISTRIBUIDA', 'DESIGNADA', 'FERIAS_DEVOLVIDA') ORDER BY e.id DESC LIMIT 12");
        $emails = [];
        foreach ($ev as $e) { $d = json_decode((string) $e['details'], true); if (is_array($d) && !empty($d['para'])) $emails[$d['para']] = true; }
        $names = [];
        if ($emails) foreach (Db::all('SELECT email, name FROM users WHERE email IN (' . Db::marks(array_keys($emails)) . ')', array_keys($emails)) as $u) $names[$u['email']] = $u['name'];
        foreach ($ev as $e) {
            $d = json_decode((string) $e['details'], true) ?: [];
            $recent[] = ['at' => $e['created_at'], 'event' => $e['event'], 'pppoe' => $e['pppoe'], 'to' => $names[$d['para'] ?? ''] ?? '', 'origin' => (string) ($d['origem'] ?? ''), 'criterion' => (string) ($d['criterio'] ?? '')];
        }
        $runs = [];
        try {
            $runs = Db::all('SELECT ran_at AS at, source, state, moved, pool, waiting, stuck, eligible, message FROM redistribution_runs ORDER BY id DESC LIMIT 15');
        } catch (\Throwable $e) { /* migration 019 ainda não aplicada */ }

        return [
            'config' => $cfg,
            'status' => [
                'pool' => (int) $pool['n'], 'oldest' => $pool['oldest'], 'stuck' => (int) $pool['stuck'],
                'today' => (int) Db::val("SELECT COUNT(*) FROM verification_events WHERE event = 'REDISTRIBUIDA' AND created_at >= CURDATE()"),
                'geral' => $geral,
                'shifts' => $shifts, 'last' => $last ? json_decode($last, true) : null, 'now' => date('H:i'),
                'sched' => $sched,
                'engine' => $engine, 'breakdown' => $bd, 'techs' => $tb, 'recent' => $recent, 'runs' => $runs,
                'checks' => self::checks($cfg, $bd, $tb, $shifts, $engine, $sched),
            ],
        ];
    }
}
