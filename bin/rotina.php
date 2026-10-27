<?php
declare(strict_types=1);
/**
 * Ferramenta de linha de comando do Sistema de Rotina.
 *   php bin/rotina.php ajuda
 * Rode com o MESMO usuário do servidor web quando possível:  sudo -u www-data php bin/rotina.php ...
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';

use Rotina\{Backup, Config, Db, Installer, Jobs, Migrator, SqlSplitter, Updater, Util};

// ----------------------------------------------------------------------------- utilitários CLI
$G = "\033[32m"; $Y = "\033[33m"; $R = "\033[31m"; $Z = "\033[0m";
if (!function_exists('posix_isatty') || !@posix_isatty(STDOUT)) { $G = $Y = $R = $Z = ''; }
function out(string $m): void { echo $m . "\n"; }
function ok(string $m): void { global $G, $Z; echo "{$G}==>{$Z} $m\n"; }
function warn(string $m): void { global $Y, $Z; fwrite(STDERR, "{$Y}AVISO:{$Z} $m\n"); }
function fail(string $m, int $code = 1): void { global $R, $Z; fwrite(STDERR, "{$R}ERRO:{$Z} $m\n"); exit($code); }

function parseArgs(array $argv): array
{
    $pos = []; $opt = [];
    foreach (array_slice($argv, 1) as $a) {
        if (substr($a, 0, 2) === '--') {
            $kv = explode('=', substr($a, 2), 2);
            $opt[$kv[0]] = $kv[1] ?? true;
        } else $pos[] = $a;
    }
    return [$pos, $opt];
}

function prompt(string $label, string $default = '', bool $secret = false, bool $required = false): string
{
    if (!function_exists('posix_isatty') || !@posix_isatty(STDIN)) {
        // sem terminal (script/automação): usa o padrão; só falha se o dado for obrigatório e não tiver padrão
        if ($required && $default === '') fail("Faltou informar: $label (modo não interativo; passe a opção correspondente).");
        return $default;
    }
    echo $label . ($default !== '' ? " [$default]" : '') . ': ';
    if ($secret) @system('stty -echo 2>/dev/null');
    $v = trim((string) fgets(STDIN));
    if ($secret) { @system('stty echo 2>/dev/null'); echo "\n"; }
    return $v !== '' ? $v : $default;
}

function need_installed(): void
{
    if (!Config::installed()) fail('Sistema ainda não instalado. Rode:  php bin/rotina.php install   (ou abra install.php no navegador)');
    if ($p = Config::problems()) fail('Configuração inválida: ' . implode('; ', $p));
    try { Db::pdo(); } catch (Throwable $e) { fail('Sem conexão com o banco: ' . $e->getMessage()); }
}

function confirm(string $q, array $opt): void
{
    if (isset($opt['yes'])) return;
    if (strtolower(prompt("$q Digite SIM para continuar", '', false, true)) !== 'sim') fail('Cancelado. (em scripts, use --yes)', 2);
}

// ----------------------------------------------------------------------------- comandos
[$pos, $opt] = parseArgs($argv);
$cmd = $pos[0] ?? 'ajuda';
$args = array_slice($pos, 1);

try {
    switch ($cmd) {
        case 'ajuda': case 'help': case '--help':
            out(<<<TXT
Sistema de Rotina — comandos (php bin/rotina.php <comando>)

  install                 Instala (pede os dados do banco e do administrador). Opções:
                          --db-host= --db-port= --db-name= --db-user= --db-pass=
                          --admin-name= --admin-email= --admin-pass= --tz=
  status                  Mostra versão, banco e migrations pendentes
  migrate                 Aplica migrations pendentes
  create-admin [nome email senha]   Cria ou redefine um administrador
  import-legacy <legado.json> [--dry-run]   Importa dados do sistema antigo (localStorage)
  backup [rotulo]         Faz backup agora (storage/backups/)
  backups                 Lista os backups
  verify-backup [arquivo] Testa um backup (restaura em banco descartável, se permitido)
  restore <arquivo> [--yes]   Restaura um backup gerado por este sistema
  update <pacote.zip|pasta> [--force]   Atualização segura (backup, migrations, troca, rollback automático)
  rollback [versão]       Volta o código para a versão anterior guardada
  cron                    Executa as rotinas agendadas (use no cron se quiser horário exato)
TXT);
            break;

        // ------------------------------------------------------------------------------------------
        case 'install':
            if (Installer::installed()) fail('Já instalado. Para reinstalar apague config/installed.lock (o banco não é apagado).');
            ok('Verificando requisitos do servidor...');
            $bad = false;
            foreach (Installer::requirements() as $r) {
                out(sprintf('  [%s] %s', $r['ok'] ? ' OK ' : ($r['required'] ? 'FALTA' : 'aviso'), $r['label']) . (!$r['ok'] && $r['hint'] ? "  → {$r['hint']}" : ''));
                if (!$r['ok'] && $r['required']) $bad = true;
            }
            if ($bad) fail('Corrija os itens "FALTA" e rode de novo.');
            $o = static fn(string $k, string $label, string $def = '', bool $secret = false, bool $req = false) => is_string($opt[$k] ?? null) ? $opt[$k] : prompt($label, $def, $secret, $req);
            $in = [
                'db_host' => $o('db-host', 'Servidor do banco', 'localhost'),
                'db_port' => $o('db-port', 'Porta do banco', '3306'),
                'db_name' => $o('db-name', 'Nome do banco (já criado)', 'rotina'),
                'db_user' => $o('db-user', 'Usuário do banco', 'rotina'),
                'db_pass' => $o('db-pass', 'Senha do banco', '', true),
                'admin_name' => $o('admin-name', 'Nome do administrador', 'Administrador'),
                'admin_email' => $o('admin-email', 'E-mail do administrador (será o login)', '', false, true),
                'admin_pass' => $o('admin-pass', 'Senha do administrador (mín. 4 caracteres)', '', true, true),
                'tz' => $o('tz', 'Fuso horário', 'America/Campo_Grande'),
            ];
            $res = Installer::run($in, fn($m) => ok($m));
            out('');
            ok('PRONTO. Abra no navegador:  http://IP-DO-SERVIDOR/  (ou o caminho onde publicou a pasta public/)');
            if ($res['admin_created']) out("    Login: {$in['admin_email']}   (senha que você acabou de informar)");
            out('    Dica: faça um backup agora com  php bin/rotina.php backup  e guarde uma cópia fora do servidor.');
            break;

        // ------------------------------------------------------------------------------------------
        case 'status':
            $quiet = isset($opt['quiet']);
            if (!Config::installed()) { if ($quiet) fail('não instalado'); fail('Sistema ainda não instalado.'); }
            if ($p = Config::problems()) fail('Configuração inválida: ' . implode('; ', $p));
            if (is_string($opt['expect'] ?? null) && Config::version() !== $opt['expect']) fail('versão em disco (' . Config::version() . ') difere da esperada (' . $opt['expect'] . ')');
            $m = Migrator::status();
            if ($m['pending'] && $quiet) fail('migrations pendentes: ' . implode(', ', $m['pending']));
            if (!(int) Db::val('SELECT COUNT(*) FROM users WHERE role = ? AND status = ?', ['ADM', 'APROVADO']) && $quiet) fail('nenhum administrador ativo');
            if ($quiet) break;
            out('Versão do sistema : ' . Config::version());
            out('Banco             : ' . Config::get('db.name') . '@' . Config::get('db.host') . ' (MySQL ' . Db::val('SELECT VERSION()') . ')');
            out('Migrations        : ' . $m['applied'] . ' aplicada(s), ' . count($m['pending']) . ' pendente(s)' . ($m['pending'] ? ' → ' . implode(', ', $m['pending']) : ''));
            out('Usuários          : ' . Db::val('SELECT COUNT(*) FROM users') . ' | Verificações: ' . Db::val('SELECT COUNT(*) FROM verifications'));
            $last = @file_get_contents(Backup::dir() . '/.last_success');
            out('Último backup     : ' . ($last ? trim($last) : 'nenhum ainda'));
            out('Manutenção        : ' . (is_file(Updater::maintenanceFlag()) ? 'LIGADA' : 'desligada'));
            break;

        case 'migrate':
            need_installed();
            $a = Migrator::run(null, fn($m) => ok($m));
            ok($a ? count($a) . ' migration(s) aplicada(s).' : 'Banco já está atualizado.');
            break;

        // ------------------------------------------------------------------------------------------
        case 'create-admin':
            need_installed();
            $name = $args[0] ?? prompt('Nome', '', false, true);
            $email = $args[1] ?? prompt('E-mail', '', false, true);
            $pass = $args[2] ?? prompt('Senha (mín. 4 caracteres)', '', true, true);
            Installer::upsertAdmin($name, $email, $pass);
            ok("Administrador pronto: " . strtolower($email));
            break;

        // ------------------------------------------------------------------------------------------
        case 'import-legacy':
            need_installed();
            $file = $args[0] ?? fail('Informe o arquivo: php bin/rotina.php import-legacy legado.json [--dry-run]');
            $raw = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
            if ($raw === false || $raw === '') fail("Não consegui ler $file");
            $data = json_decode($raw, true);
            if (!is_array($data)) fail('JSON inválido.');
            importLegacy($data, isset($opt['dry-run']));
            break;

        // ------------------------------------------------------------------------------------------
        case 'backup':
            need_installed();
            $r = Backup::create($args[0] ?? '');
            ok('Backup OK: ' . $r['file'] . ' (' . Util::human($r['size']) . ')');
            ok('Linhas: ' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($r['rows']), $r['rows'])));
            break;

        case 'backups':
            foreach (Backup::list() as $f) out(sprintf('%s  %9s  %s', date('Y-m-d H:i', filemtime($f)), Util::human((int) filesize($f)), $f));
            break;

        case 'verify-backup':
            need_installed();
            $f = $args[0] ?? (Backup::list()[0] ?? fail('Nenhum backup encontrado.'));
            $v = Backup::verifyRestore($f);
            ok(basename($f) . ': ' . $v['level'] . ' — ' . $v['tables'] . ' tabelas' . (isset($v['skipped']) ? "\n    (restauração completa pulada: {$v['skipped']})" : ' restauradas e conferidas.'));
            break;

        case 'restore':
            need_installed();
            $f = $args[0] ?? fail('Informe o arquivo. Veja:  php bin/rotina.php backups');
            if (!is_file($f)) fail("Arquivo não encontrado: $f");
            try { Backup::validate($f); } catch (Throwable $e) {
                fail($e->getMessage() . "\n    Este comando só restaura backups gerados por este sistema. Para um .sql do phpMyAdmin use: phpMyAdmin > Importar.");
            }
            confirm("ISSO SUBSTITUI TODOS OS DADOS ATUAIS pelo conteúdo de " . basename($f) . ".", $opt);
            ok('Salvando o estado atual (pre-restore) ...');
            $pre = Backup::create('pre-restore');
            Updater::maintenance(true);
            try {
                ok('Restaurando ...');
                $n = Backup::load($f);
                ok("$n comandos executados.");
            } catch (Throwable $e) {
                warn('Falha na restauração: ' . $e->getMessage() . ' — voltando ao estado anterior ...');
                try { Backup::load($pre['file']); ok('Estado anterior restaurado.'); } catch (Throwable $e2) { warn('NÃO consegui voltar sozinho: ' . $e2->getMessage() . ' Restaure manualmente ' . $pre['file']); }
                Updater::maintenance(false);
                fail($e->getMessage());
            }
            Updater::maintenance(false);
            ok('Restauração concluída. Backup do estado anterior: ' . basename($pre['file']));
            break;

        // ------------------------------------------------------------------------------------------
        case 'update':
            need_installed();
            $src = $args[0] ?? fail('Informe o pacote: php bin/rotina.php update novo-sistema.zip');
            Updater::run($src, isset($opt['force']), fn($m) => ok($m));
            break;

        case 'rollback':
            need_installed();
            if (!isset($opt['yes'])) { out('Releases guardadas: ' . (implode(', ', array_map('basename', Updater::releases())) ?: 'nenhuma')); }
            confirm('Voltar o CÓDIGO para a versão anterior (dados do banco não mudam).', $opt);
            Updater::rollback($args[0] ?? null, fn($m) => ok($m));
            break;

        case 'cron':
            need_installed();
            Jobs::runDue(fn($m) => ok($m));
            break;

        default:
            fail("Comando desconhecido: $cmd  (use: php bin/rotina.php ajuda)");
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}

// ----------------------------------------------------------------------------- importação do sistema antigo
function importLegacy(array $data, bool $dry): void
{
    $clean = static fn($v) => trim(preg_replace('/[<>"\'`\\\\]/', '', (string) ($v ?? '')));
    $cleanBatch = static fn($v) => trim((string) preg_replace('/[^\p{L}\p{N} _.,:;()\/#+@%-]/u', '-', (string) ($v ?: '')));
    $SHIFTS = ['GERAL', 'MANHA', 'TARDE', 'NOITE'];
    $PRIOS = ['NORMAL', 'ALTA', 'URGENTE'];
    // Senhas do sistema antigo estavam em texto puro (e as de demonstração apareciam no código da página).
    // Como o sistema é interno, qualquer senha com 4+ caracteres é mantida. Mais curtas ficam sem senha utilizável até serem redefinidas.
    $isWeak = static fn(string $p) => strlen($p) < 4;

    $users = $data['users'] ?? []; $tasks = $data['tasks'] ?? []; $actions = $data['actions'] ?? [];
    $rep = ['users' => 0, 'usersSkipped' => 0, 'weak' => [], 'actions' => 0, 'tasks' => 0, 'tasksSkipped' => 0, 'problems' => []];

    $body = function () use (&$rep, $users, $tasks, $actions, $clean, $cleanBatch, $SHIFTS, $PRIOS, $isWeak, $dry) {
        $emailToId = [];
        foreach (Db::all('SELECT id, email FROM users') as $u) $emailToId[$u['email']] = $u['id'];

        foreach ($users as $u) {
            $email = strtolower(trim((string) ($u['email'] ?? '')));
            if ($email === '') continue;
            if (isset($emailToId[$email])) { $rep['usersSkipped']++; continue; }
            $id = (isset($u['id']) && is_string($u['id']) && preg_match(Util::ID_RE, $u['id'])) ? $u['id'] : Util::newId('usr');
            $pass = (string) ($u['password'] ?? '');
            $weak = $isWeak($pass);
            if ($weak) $rep['weak'][] = $email . ' (' . (($u['role'] ?? '') === 'ADM' ? 'ADM' : 'TEC') . ')';
            Db::q('INSERT INTO users (id, name, email, password_hash, role, shift, status) VALUES (?,?,?,?,?,?,?)', [
                $id, Util::cut($clean($u['name'] ?? $email), 120), $email, Util::hashPassword($weak ? bin2hex(random_bytes(18)) : $pass),
                ($u['role'] ?? '') === 'ADM' ? 'ADM' : 'TEC', in_array($u['shift'] ?? '', $SHIFTS, true) ? $u['shift'] : 'MANHA',
                in_array($u['status'] ?? '', ['PENDENTE', 'APROVADO', 'BLOQUEADO'], true) ? $u['status'] : 'PENDENTE']);
            $emailToId[$email] = $id;
            $rep['users']++;
        }

        foreach ($actions as $a) {
            if (empty($a['id']) || !is_string($a['id']) || !preg_match(Util::ID_RE, $a['id']) || empty($a['label'])) continue;
            Db::q('INSERT INTO actions (id, label) VALUES (?,?) ON DUPLICATE KEY UPDATE label = VALUES(label), deleted_at = NULL',
                [$a['id'], Util::cut($clean($a['label']), 160)]);
            $rep['actions']++;
        }
        $actionIds = array_column(Db::all('SELECT id FROM actions'), 'id');

        $batchIds = [];
        $seenActive = [];
        foreach (Db::all('SELECT pppoe FROM verifications WHERE finalizado = 0') as $r) $seenActive[strtolower($r['pppoe'])] = true;
        $have = array_flip(array_column(Db::all('SELECT id FROM verifications'), 'id'));

        foreach ($tasks as $t) {
            $pppoe = $clean($t['pppoe'] ?? '');
            $techId = $emailToId[strtolower(trim((string) ($t['techEmail'] ?? '')))] ?? null;
            $tid = (isset($t['id']) && is_string($t['id']) && preg_match(Util::ID_RE, $t['id'])) ? $t['id'] : Util::newId('tsk');
            $label = $t['id'] ?? '?';
            if ($pppoe === '' || !$techId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($t['date'] ?? ''))) {
                $rep['tasksSkipped']++; $rep['problems'][] = "ignorada $label: pppoe/técnico/data inválidos"; continue;
            }
            if (isset($have[$tid])) { $rep['tasksSkipped']++; continue; }
            $fin = !empty($t['finalizado']);
            if (!$fin) {
                if (isset($seenActive[strtolower($pppoe)])) { $rep['tasksSkipped']++; $rep['problems'][] = "ignorada $label: PPPoE $pppoe duplicado em aberto"; continue; }
                $seenActive[strtolower($pppoe)] = true;
            }
            $lote = Util::cut($cleanBatch($t['lote'] ?? 'Sem Lote Definido'), 160) ?: 'Sem Lote Definido';
            if (!isset($batchIds[$lote])) {
                Db::q('INSERT INTO batches (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', [$lote]);
                $batchIds[$lote] = (int) Db::pdo()->lastInsertId();
            }
            $ht = $t['handleTimeMin'] ?? 8;
            Db::q('INSERT INTO verifications (id, pppoe, batch_id, tech_id, shift, due_date, priority, obs, ligou, nao_atende, resolvido,
                     os_criada, action_id, handle_time_min, em_execucao, finalizado, finished_at, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)', [
                $tid, Util::cut($pppoe, 120), $batchIds[$lote], $techId, in_array($t['shift'] ?? '', $SHIFTS, true) ? $t['shift'] : 'MANHA',
                $t['date'], in_array($t['priority'] ?? '', $PRIOS, true) ? $t['priority'] : 'NORMAL',
                !empty($t['obs']) ? Util::cut((string) $t['obs'], 5000) : null,
                (int) !empty($t['ligou']), (int) !empty($t['naoAtende']), (int) !empty($t['resolvido']), (int) !empty($t['osCriada']),
                in_array($t['actionId'] ?? '', $actionIds, true) ? $t['actionId'] : 'NONE',
                is_int($ht) ? min(max($ht, 0), 32000) : 8, (int) (!empty($t['emExecucao']) && !$fin), (int) $fin,
                $fin ? $t['date'] . ' 12:00:00' : null]);
            $have[$tid] = true;
            $rep['tasks']++;
        }
        if ($dry) throw new RuntimeException('DRY_RUN');
    };

    try { Db::tx($body); } catch (RuntimeException $e) { if ($e->getMessage() !== 'DRY_RUN') throw $e; }

    out($dry ? '=== SIMULAÇÃO (nada foi gravado) ===' : '=== IMPORTAÇÃO CONCLUÍDA ===');
    out("Usuários importados: {$rep['users']} (já existentes: {$rep['usersSkipped']})");
    out("Procedimentos: {$rep['actions']}");
    out("Verificações importadas: {$rep['tasks']} (ignoradas: {$rep['tasksSkipped']})");
    if ($rep['problems']) out("Avisos:\n - " . implode("\n - ", array_slice($rep['problems'], 0, 30)));
    if ($rep['weak']) {
        out("\n⚠ " . count($rep['weak']) . " usuário(s) tinham senha com menos de 4 caracteres e NÃO foram migrados com ela (conta sem senha utilizável):");
        out(' - ' . implode("\n - ", $rep['weak']));
        out("  Administrador: php bin/rotina.php create-admin \"Nome\" email senha-forte");
        out('  Técnicos: um administrador redefine em Usuários > Editar perfil.');
    }
}
