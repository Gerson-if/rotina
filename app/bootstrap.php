<?php
declare(strict_types=1);
// Carregado por public/api.php, public/install.php e bin/rotina.php.
// Define a raiz do projeto, o autoload (Rotina\Classe => app/Classe.php) e o fuso horário.

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit("Este sistema precisa de PHP 8.0 ou superior (versão atual: " . PHP_VERSION . ").\n");
}

define('ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'Rotina\\') !== 0) return;
    $file = __DIR__ . '/' . substr($class, 7) . '.php';
    if (is_file($file)) require $file;
});

error_reporting(E_ALL);
ini_set('display_errors', '0');          // nunca exibe erros PHP ao visitante
ini_set('log_errors', '1');

// O fuso vem da configuração (se já instalado); antes disso usa o padrão do negócio.
date_default_timezone_set(Rotina\Config::timezone());
