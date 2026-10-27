<?php
declare(strict_types=1);
namespace Rotina;

/** Lê config/config.php (criado pelo instalador). Nada de .env, nada de variáveis de ambiente. */
final class Config
{
    private static ?array $data = null;

    public const DEFAULTS = [
        'tz'                   => 'America/Campo_Grande',
        'session_hours'        => 12,
        'cookie_secure'        => 'auto',   // auto = Secure só quando a requisição chegou por HTTPS
        'trust_proxy'          => false,    // true somente se houver proxy reverso confiável à frente
        'bcrypt_cost'          => 12,
        'bootstrap_days'       => 120,
        'audit_retention_days' => 365,
        'auto_jobs'            => true,     // rotinas rodam sozinhas (sem cron). Use cron e false se preferir.
        'backup' => [
            'auto'         => true,         // backup diário automático
            'time'         => '02:30',
            'keep_daily'   => 14,
            'keep_monthly' => 6,
            'keep_labeled' => 10,           // pre-update, pre-restore, manual
        ],
        'db' => ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'password' => ''],
        'secret' => '',
    ];

    public static function path(): string
    {
        $env = getenv('ROTINA_CONFIG');
        return $env ? $env : ROOT . '/config/config.php';
    }

    public static function exists(): bool { return is_file(self::path()); }

    public static function all(): array
    {
        if (self::$data !== null) return self::$data;
        $user = [];
        if (self::exists()) {
            $loaded = require self::path();
            if (is_array($loaded)) $user = $loaded;
        }
        self::$data = array_replace_recursive(self::DEFAULTS, $user);
        return self::$data;
    }

    /** Acesso por caminho: Config::get('db.host') */
    public static function get(string $key, $default = null)
    {
        $v = self::all();
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) return $default;
            $v = $v[$part];
        }
        return $v;
    }

    public static function timezone(): string
    {
        $tz = (string) self::get('tz', self::DEFAULTS['tz']);
        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : self::DEFAULTS['tz'];
    }

    public static function reset(): void { self::$data = null; }

    public static function installed(): bool
    {
        return self::exists() && is_file(dirname(self::path()) . '/installed.lock');
    }

    public static function version(): string
    {
        $f = ROOT . '/VERSION';
        return is_file($f) ? trim((string) file_get_contents($f)) : 'dev';
    }

    /** Valida o que é indispensável para rodar. Devolve lista de problemas. */
    public static function problems(): array
    {
        $p = [];
        if (strlen((string) self::get('secret')) < 32) $p[] = 'secret ausente ou curto (config/config.php)';
        if (self::get('db.name') === '' || self::get('db.user') === '') $p[] = 'dados do banco não configurados';
        return $p;
    }
}
