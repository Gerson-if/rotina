<?php
// Exemplo de TODAS as opções. O instalador gera config/config.php só com o essencial (db, secret, tz).
// Para mudar algo, copie a chave desejada para config/config.php (as demais usam o padrão).
return [
    'db' => [
        'host' => 'localhost', 'port' => 3306, 'name' => 'rotina', 'user' => 'rotina', 'password' => '********',
        // 'socket' => '/var/run/mysqld/mysqld.sock',   // se o servidor só aceitar conexão por socket
    ],
    'secret' => 'GERADO-PELO-INSTALADOR-64-HEX',   // assina os cookies de sessão. Trocar = desloga todos.
    'tz' => 'America/Campo_Grande',

    'session_hours'        => 12,
    'cookie_secure'        => 'auto',   // 'auto' | 'true' | 'false'  (auto: Secure só em HTTPS; acesso por IP/HTTP funciona)
    'trust_proxy'          => false,    // true só atrás de proxy reverso confiável (usa X-Forwarded-For/Proto)
    'bcrypt_cost'          => 12,
    'bootstrap_days'       => 120,      // histórico de finalizadas que o painel carrega
    'audit_retention_days' => 365,

    // Rotinas "de carona" nas requisições (só rodam com alguém acessando). Para a Redistribuição e os backups rodarem SEM ninguém
    // logado, instale o agendador do servidor:  sudo ./rotina cron-install   (com ele, true ou false funcionam).
    'auto_cron' => true,                // tenta instalar sozinho o agendador (crontab do usuário) ao atualizar. false = só manual.
    'auto_jobs' => true,
    'backup' => [
        'auto' => true, 'time' => '02:30',
        'keep_daily' => 14, 'keep_monthly' => 6, 'keep_labeled' => 10,
    ],
];
