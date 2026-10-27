<?php
declare(strict_types=1);
// Ponto único de entrada da API:  api.php?r=auth/login | bootstrap | sync | healthz ...

// Modo manutenção (ligado pelo atualizador/restaurador). Checado ANTES de carregar o resto do sistema.
$flag = __DIR__ . '/../storage/maintenance.flag';
if (is_file($flag) && ($_GET['r'] ?? '') !== 'healthz') {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 30');
    echo json_encode(['error' => 'Sistema em atualização. Volte em instantes.', 'code' => 'MAINTENANCE'], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

if (!Rotina\Config::installed()) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Sistema ainda não instalado. Abra install.php no navegador.', 'code' => 'NOT_INSTALLED'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($problems = Rotina\Config::problems()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Configuração inválida: ' . implode('; ', $problems)], JSON_UNESCAPED_UNICODE);
    exit;
}

$route = trim((string) ($_GET['r'] ?? ''), '/');
Rotina\Api::handle($route, strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'));
