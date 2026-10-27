<?php
declare(strict_types=1);
// Assistente de instalação (use UMA vez). Depois de instalado ele se bloqueia sozinho e tenta se apagar.
require __DIR__ . '/../app/bootstrap.php';

use Rotina\{Config, Installer};

header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'");

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$done = Installer::installed();

// Botão "Testar conexão" (AJAX)
if (!$done && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'test') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(Installer::testConnection($_POST), JSON_UNESCAPED_UNICODE);
    exit;
}

$errors = [];
$log = [];
$result = null;
$v = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'rotina', 'db_user' => 'rotina', 'db_pass' => '',
    'admin_name' => 'Administrador', 'admin_email' => '', 'admin_pass' => '', 'tz' => 'America/Campo_Grande',
];

if (!$done && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($v as $k => $_) $v[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : $v[$k];
    try {
        $result = Installer::run($v, function (string $m) use (&$log) { $log[] = $m; });
        $done = true;
        @unlink(__FILE__);                       // o instalador se apaga (se tiver permissão)
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$reqs = $done ? [] : Installer::requirements();
$reqOk = $done || Installer::requirementsOk();
$reqBad = array_filter($reqs, static fn($r) => !$r['ok']);
clearstatcache();
$selfGone = !is_file(__FILE__);
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
$advOpen = $v['db_host'] !== 'localhost' || $v['db_port'] !== '3306' || $v['tz'] !== 'America/Campo_Grande';
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalação — Sistema de Rotina</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%20viewBox=%220%200%2064%2064%22%20role=%22img%22%20aria-label=%22Sistema%20de%20Verifica%C3%A7%C3%A3o%20de%20Rotina%22%3E%3Cdefs%3E%3ClinearGradient%20id=%22g%22%20x1=%220%22%20y1=%220%22%20x2=%221%22%20y2=%221%22%3E%3Cstop%20offset=%220%22%20stop-color=%22%230369a1%22/%3E%3Cstop%20offset=%221%22%20stop-color=%22%2322d3ee%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect%20width=%2264%22%20height=%2264%22%20rx=%2215%22%20fill=%22url(%23g)%22/%3E%3Cg%20fill=%22none%22%20stroke=%22%23fff%22%20stroke-width=%224.6%22%20stroke-linecap=%22round%22%20stroke-linejoin=%22round%22%3E%3Cpath%20d=%22M13%2020.5l3.8%203.8L24%2016.5%22/%3E%3Cpath%20d=%22M31%2020.5h20%22/%3E%3Cpath%20d=%22M13%2033l3.8%203.8L24%2029%22/%3E%3Cpath%20d=%22M31%2033h20%22/%3E%3Ccircle%20cx=%2218.5%22%20cy=%2246.5%22%20r=%223.6%22%20stroke-width=%223.6%22%20opacity=%22.85%22/%3E%3Cpath%20d=%22M31%2046.5h13%22%20opacity=%22.85%22/%3E%3C/g%3E%3C/svg%3E">
<style>
 :root{--bg:#020617;--card:#0f172a;--line:#1e293b;--line2:#334155;--txt:#e2e8f0;--mut:#94a3b8;--brand:#0ea5e9;--brand2:#0284c7;--ok:#34d399;--bad:#fb7185;--warn:#fbbf24}
 *{box-sizing:border-box}
 body{margin:0;min-height:100vh;font-family:Inter,system-ui,"Segoe UI",Roboto,sans-serif;color:var(--txt);
      background:radial-gradient(1200px 600px at 50% -10%,#0c2a43 0%,var(--bg) 60%);padding:32px 16px}
 main{max-width:560px;margin:0 auto}
 .logo{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,var(--brand),#6366f1);display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;box-shadow:0 10px 30px #0ea5e955}
 h1{font-size:24px;margin:0;text-align:center;font-weight:800} p.sub{color:var(--mut);margin:6px 0 24px;text-align:center;font-size:14px}
 .card{background:rgba(15,23,42,.9);border:1px solid var(--line);border-radius:20px;padding:22px;margin-bottom:16px;box-shadow:0 20px 40px #0006}
 .step{display:flex;align-items:center;gap:10px;margin:0 0 14px;font-size:15px;font-weight:700}
 .num{width:26px;height:26px;border-radius:50%;background:var(--brand2);color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center;flex:none}
 label{display:block;font-size:12px;font-weight:600;color:var(--mut);margin:14px 0 6px;text-transform:uppercase;letter-spacing:.04em}
 input,select{width:100%;background:#020617;border:1px solid var(--line2);color:#f8fafc;border-radius:12px;padding:12px 14px;font-size:15px;font-family:inherit;outline:none;transition:border .15s}
 input:focus,select:focus{border-color:var(--brand)}
 .row{display:grid;grid-template-columns:1fr 1fr;gap:12px} .pw{position:relative} .pw input{padding-right:46px}
 .eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;color:var(--mut);cursor:pointer;font-size:16px;padding:8px;margin:0;width:auto}
 .hint{color:var(--mut);font-size:12px;margin-top:6px}
 button.main,a.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;border:0;border-radius:14px;padding:15px 20px;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit;box-shadow:0 8px 24px #0ea5e944}
 button.main:hover,a.btn:hover{filter:brightness(1.1)} button[disabled]{background:var(--line2)!important;box-shadow:none!important;cursor:not-allowed}
 button.ghost{background:transparent;color:var(--brand);border:1px solid var(--line2);border-radius:10px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;margin-top:12px}
 button.ghost:hover{border-color:var(--brand)}
 details{margin-top:14px} summary{cursor:pointer;color:var(--mut);font-size:13px;font-weight:600}
 .banner{display:flex;gap:10px;align-items:flex-start;border-radius:14px;padding:12px 14px;font-size:14px;margin-bottom:16px;border:1px solid}
 .banner.ok{background:#052e1f;border-color:#065f46;color:#a7f3d0} .banner.bad{background:#3f0d18;border-color:#7f1d1d;color:#fecdd3}
 .banner.warn{background:#3b2a05;border-color:#78350f;color:#fde68a}
 .banner ul{margin:6px 0 0;padding-left:18px;font-size:13px} .banner small{display:block;opacity:.8}
 #test-msg{font-size:13px;margin-top:10px;min-height:18px}.t-ok{color:var(--ok)}.t-bad{color:var(--bad)}
 ul.log{list-style:none;padding:0;margin:0 0 14px;font-size:13px;color:var(--mut)} ul.log li{padding:3px 0}
 code{background:var(--line);padding:2px 6px;border-radius:6px;font-size:13px}
 .big{font-size:42px;text-align:center;margin-bottom:6px}
 .cred{background:#020617;border:1px dashed var(--line2);border-radius:12px;padding:12px 14px;font-size:14px;margin:14px 0}
 @media(max-width:480px){.row{grid-template-columns:1fr}}
</style></head><body><main>
<div class="logo">📋</div>
<h1>Sistema de Verificação de Rotina</h1>
<p class="sub">Instalação rápida · versão <?= $h(Config::version()) ?></p>

<?php if ($done): ?>
  <div class="card">
    <div class="big">🎉</div>
    <h2 style="text-align:center;margin:0 0 6px">Tudo pronto!</h2>
    <p class="sub" style="margin-bottom:10px">O sistema foi instalado e já pode ser usado.</p>
    <?php if ($result): ?>
      <?php if ($result['admin_created']): ?>
        <div class="cred">Entre com o e-mail <b><?= $h($v['admin_email']) ?></b> e a senha que você acabou de escolher.</div>
      <?php endif; ?>
      <details><summary>Ver o que foi feito</summary><ul class="log" style="margin-top:8px"><?php foreach ($log as $l): ?><li>✔ <?= $h($l) ?></li><?php endforeach; ?></ul></details>
    <?php else: ?>
      <p style="text-align:center;color:var(--mut)">O sistema já está instalado e este assistente está bloqueado.</p>
    <?php endif; ?>
    <?php if (!$selfGone): ?>
      <div class="banner warn" style="margin-top:14px">⚠ <div>Não consegui apagar <code>public/install.php</code> sozinho. Apague-o manualmente (ele está inativo, mas não precisa mais existir).</div></div>
    <?php endif; ?>
    <a class="btn" style="margin-top:16px" href="<?= $h($base) ?>">Abrir o sistema →</a>
    <p class="hint" style="text-align:center">Dica: depois do primeiro acesso, vá em <b>Sistema</b> e gere um backup.</p>
  </div>
<?php else: ?>

  <?php foreach ($errors as $e): ?><div class="banner bad">✘ <div><?= $h($e) ?></div></div><?php endforeach; ?>

  <?php if ($reqBad): ?>
    <div class="banner <?= $reqOk ? 'warn' : 'bad' ?>"><span><?= $reqOk ? '⚠' : '✘' ?></span><div>
      <b><?= $reqOk ? 'Está quase tudo certo' : 'O servidor precisa de um ajuste antes de continuar' ?></b>
      <ul><?php foreach ($reqBad as $r): ?><li><?= $h($r['label']) ?><?php if ($r['hint']): ?><small><?= $h($r['hint']) ?></small><?php endif; ?></li><?php endforeach; ?></ul>
    </div></div>
  <?php else: ?>
    <div class="banner ok"><span>✔</span><div><b>Servidor pronto.</b> Todos os requisitos foram atendidos.</div></div>
  <?php endif; ?>

  <form method="post" autocomplete="off" id="f">
    <div class="card">
      <div class="step"><span class="num">1</span> Banco de dados</div>
      <p class="hint" style="margin:0">Informe o banco MySQL/MariaDB. Se ele ainda não existir, tentamos criá-lo para você.</p>
      <label>Nome do banco</label><input name="db_name" value="<?= $h($v['db_name']) ?>" required>
      <div class="row">
        <div><label>Usuário</label><input name="db_user" value="<?= $h($v['db_user']) ?>" required></div>
        <div><label>Senha</label><div class="pw"><input name="db_pass" type="password" value=""><button type="button" class="eye" data-eye>👁</button></div></div>
      </div>
      <details<?= $advOpen ? ' open' : '' ?>><summary>Opções avançadas</summary>
        <div class="row"><div><label>Servidor</label><input name="db_host" value="<?= $h($v['db_host']) ?>" required></div>
          <div><label>Porta</label><input name="db_port" value="<?= $h($v['db_port']) ?>" required></div></div>
        <label>Fuso horário</label>
        <select name="tz"><?php foreach (Installer::timezones() as $tz): ?><option<?= $tz === $v['tz'] ? ' selected' : '' ?>><?= $h($tz) ?></option><?php endforeach; ?></select>
      </details>
      <button type="button" class="ghost" id="btn-test">Testar conexão</button>
      <div id="test-msg"></div>
    </div>

    <div class="card">
      <div class="step"><span class="num">2</span> Seu acesso de administrador</div>
      <label>Seu nome</label><input name="admin_name" value="<?= $h($v['admin_name']) ?>" required>
      <label>E-mail (será o seu login)</label><input name="admin_email" type="email" value="<?= $h($v['admin_email']) ?>" placeholder="voce@empresa.com" required>
      <label>Senha</label>
      <div class="pw"><input name="admin_pass" type="password" minlength="4" required><button type="button" class="eye" data-eye>👁</button></div>
      <div class="hint">Pode ser simples, a seu gosto (mínimo de 4 caracteres).</div>
    </div>

    <button type="submit" class="main" id="btn-go"<?= $reqOk ? '' : ' disabled' ?>>Instalar agora</button>
  </form>
  <script>
  (function () {
    document.querySelectorAll('[data-eye]').forEach(function (b) {
      b.addEventListener('click', function () { var i = b.parentNode.querySelector('input'); i.type = i.type === 'password' ? 'text' : 'password'; });
    });
    var f = document.getElementById('f'), msg = document.getElementById('test-msg');
    document.getElementById('btn-test').addEventListener('click', function () {
      var fd = new FormData(f); fd.set('action', 'test'); msg.className = ''; msg.textContent = 'Testando...';
      fetch(location.pathname, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
        msg.className = d.ok ? 't-ok' : 't-bad'; msg.textContent = (d.ok ? '✔ ' : '✘ ') + d.message;
      }).catch(function () { msg.className = 't-bad'; msg.textContent = '✘ Não consegui falar com o servidor.'; });
    });
    f.addEventListener('submit', function () { var b = document.getElementById('btn-go'); b.disabled = true; b.textContent = 'Instalando...'; });
  })();
  </script>
<?php endif; ?>
</main></body></html>
