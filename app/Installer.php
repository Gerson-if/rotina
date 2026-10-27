<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Instalação em um passo, usada pelo assistente web (public/install.php) e pelo CLI (bin/rotina.php install).
 * Pré-requisito: Apache/Nginx com PHP e um banco MySQL/MariaDB já criado (ex.: pelo phpMyAdmin).
 */
final class Installer
{
    public static function lockFile(): string { return dirname(Config::path()) . '/installed.lock'; }

    /** @return array<int,array{label:string,ok:bool,required:bool,hint:string}> */
    public static function requirements(): array
    {
        $cfgDir = dirname(Config::path());
        $rows = [];
        $add = static function (string $label, bool $ok, bool $required, string $hint = '') use (&$rows): void {
            $rows[] = ['label' => $label, 'ok' => $ok, 'required' => $required, 'hint' => $hint];
        };
        $add('PHP 8.0 ou superior (atual: ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80000, true, 'Atualize o PHP do servidor.');
        $add('Extensão pdo_mysql', extension_loaded('pdo_mysql'), true, 'Debian/Ubuntu: apt install php-mysql');
        $add('Extensão json', extension_loaded('json'), true, '');
        $add('Extensão mbstring', extension_loaded('mbstring'), false, 'Recomendada. Debian/Ubuntu: apt install php-mbstring');
        $add('Extensão zlib (backups compactados)', extension_loaded('zlib'), false, 'Sem ela os backups saem sem compactação.');
        $add('Extensão zip (atualização por arquivo .zip)', class_exists('ZipArchive'), false, 'Sem ela, atualize apontando para uma pasta descompactada.');
        $add("Pasta config/ gravável", is_writable($cfgDir), true, "Execute: chown -R <usuario-do-apache> $cfgDir storage   (ou chmod 775)");
        $add('Pasta storage/ gravável', is_writable(ROOT . '/storage'), true, 'Execute: chown -R <usuario-do-apache> ' . ROOT . '/storage');
        return $rows;
    }

    public static function requirementsOk(): bool
    {
        foreach (self::requirements() as $r) if ($r['required'] && !$r['ok']) return false;
        return true;
    }

    /**
     * Se o banco ainda não existe, tenta criá-lo (funciona quando o usuário tem permissão).
     * Caso contrário explica, em português simples, o que fazer.
     */
    private static function tryCreateDatabase(array $db, \PDOException $first, callable $log): \PDO
    {
        $code = Db::errno($first);
        if ($code === 1045) throw new \RuntimeException('Usuário ou senha do banco incorretos.');
        if ($code === 1049 || $code === 1044) {
            try {
                $srv = Db::connect(['name' => ''] + $db);
                $log("Banco '{$db['name']}' não existe; criando ...");
                $srv->exec('CREATE DATABASE `' . str_replace('`', '``', $db['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                return Db::connect($db);
            } catch (\Throwable $e) {
                throw new \RuntimeException("O banco '{$db['name']}' ainda não existe (ou o usuário '{$db['user']}' não tem acesso a ele) e não consegui criá-lo sozinho. No phpMyAdmin: Bases de dados > Nova (utf8mb4_unicode_ci) e depois dê ao usuário todos os privilégios nesse banco.");
            }
        }
        throw new \RuntimeException('Não foi possível conectar ao banco: ' . Db::errmsg($first));
    }

    /** Teste de conexão para o botão "Testar" do assistente. @return array{ok:bool,message:string} */
    public static function testConnection(array $in): array
    {
        $db = ['host' => trim((string) ($in['db_host'] ?? 'localhost')) ?: 'localhost', 'port' => (int) (($in['db_port'] ?? 0) ?: 3306),
               'name' => (string) ($in['db_name'] ?? ''), 'user' => (string) ($in['db_user'] ?? ''), 'password' => (string) ($in['db_pass'] ?? '')];
        if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $db['name'])) return ['ok' => false, 'message' => 'Nome do banco: use apenas letras, números e _.'];
        if ($db['user'] === '') return ['ok' => false, 'message' => 'Informe o usuário do banco.'];
        try {
            Db::connect($db);
            return ['ok' => true, 'message' => 'Conexão OK. O banco existe e está acessível.'];
        } catch (\PDOException $e) {
            $code = Db::errno($e);
            if ($code === 1045) return ['ok' => false, 'message' => 'Usuário ou senha do banco incorretos.'];
            if ($code === 1049 || $code === 1044) {
                try {
                    Db::connect(['name' => ''] + $db);
                    return ['ok' => true, 'message' => "O servidor respondeu. O banco '{$db['name']}' será criado na instalação (se o usuário tiver permissão)."];
                } catch (\Throwable $e2) {
                    return ['ok' => false, 'message' => "Não consegui acessar o banco '{$db['name']}'. Crie-o no phpMyAdmin e dê ao usuário acesso a ele."];
                }
            }
            return ['ok' => false, 'message' => 'Não foi possível conectar: ' . Db::errmsg($e)];
        }
    }

    public static function installed(): bool { return is_file(self::lockFile()); }

    public static function timezones(): array { return \DateTimeZone::listIdentifiers(); }

    /** Valida os dados do formulário. @return string[] erros */
    public static function validate(array $in): array
    {
        $e = [];
        if (!preg_match('/^[A-Za-z0-9_.:\-]{1,255}$/', (string) ($in['db_host'] ?? ''))) $e[] = 'Servidor do banco inválido.';
        $port = (int) ($in['db_port'] ?? 3306);
        if ($port < 1 || $port > 65535) $e[] = 'Porta do banco inválida.';
        if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', (string) ($in['db_name'] ?? ''))) $e[] = 'Nome do banco: use apenas letras, números e _ (máx. 64).';
        if (trim((string) ($in['db_user'] ?? '')) === '') $e[] = 'Informe o usuário do banco.';
        if (Util::len(trim((string) ($in['admin_name'] ?? ''))) < 2) $e[] = 'Informe o nome do administrador.';
        if (preg_match('/[<>"\'`\\\\]/', (string) ($in['admin_name'] ?? ''))) $e[] = 'Nome do administrador contém caracteres não permitidos.';
        if (!filter_var((string) ($in['admin_email'] ?? ''), FILTER_VALIDATE_EMAIL)) $e[] = 'E-mail do administrador inválido.';
        if (strlen((string) ($in['admin_pass'] ?? '')) < 4) $e[] = 'A senha do administrador precisa ter ao menos 4 caracteres.';
        if (!in_array((string) ($in['tz'] ?? ''), self::timezones(), true)) $e[] = 'Fuso horário inválido.';
        return $e;
    }

    /**
     * Executa a instalação. Lança \RuntimeException com mensagem amigável em qualquer falha.
     * @return array{admin_created:bool,applied:string[],reused:bool}
     */
    public static function run(array $in, ?callable $log = null): array
    {
        $log = $log ?: static function (string $m): void {};
        if (self::installed()) throw new \RuntimeException('O sistema já está instalado. Para reinstalar, apague config/installed.lock (os dados do banco não são apagados).');
        if (!self::requirementsOk()) throw new \RuntimeException('Os requisitos do servidor não foram atendidos (veja a lista de verificação).');
        if ($err = self::validate($in)) throw new \RuntimeException(implode(' ', $err));

        $db = ['host' => trim($in['db_host']), 'port' => (int) ($in['db_port'] ?: 3306), 'name' => $in['db_name'],
               'user' => $in['db_user'], 'password' => (string) ($in['db_pass'] ?? '')];

        // 1) conexão com o banco (sem gravar nada ainda)
        $log('Conectando ao banco de dados...');
        try {
            Config::reset();
            $pdo = Db::connect($db);
        } catch (\PDOException $e) {
            $pdo = self::tryCreateDatabase($db, $e, $log);
        }
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $log("Conectado (servidor $version).");

        // 2) grava a configuração
        $log('Gravando config/config.php ...');
        $cfg = ['db' => $db, 'secret' => bin2hex(random_bytes(32)), 'tz' => $in['tz']];
        $php = "<?php\n// Gerado pelo instalador em " . date('c') . ". Mantenha este arquivo privado (contém a senha do banco).\n"
             . "// Opções avançadas: veja config/config.sample.php\nreturn " . var_export($cfg, true) . ";\n";
        $path = Config::path();
        if (file_put_contents($path, $php, LOCK_EX) === false) throw new \RuntimeException("Não foi possível gravar $path (verifique permissões da pasta config/).");
        @chmod($path, 0640);
        Config::reset();
        Db::reset();
        date_default_timezone_set(Config::timezone());

        // 3) tabelas
        $log('Criando/atualizando tabelas (migrations)...');
        $applied = Migrator::run(null, $log);
        $reused = !$applied;

        // 4) primeiro administrador
        $adminCreated = false;
        if ((int) Db::val('SELECT COUNT(*) FROM users') === 0) {
            Db::q("INSERT INTO users (id, name, email, password_hash, role, shift, status) VALUES (?,?,?,?, 'ADM', 'GERAL', 'APROVADO')",
                [Util::newId('usr'), trim($in['admin_name']), strtolower(trim($in['admin_email'])), Util::hashPassword($in['admin_pass'])]);
            $adminCreated = true;
            $log('Administrador criado: ' . strtolower(trim($in['admin_email'])));
        } else {
            $log('O banco já tem usuários: o administrador informado NÃO foi criado (use "create-admin" se precisar).');
        }

        // 5) trava o instalador
        file_put_contents(self::lockFile(), date('c') . "\n");
        @chmod(self::lockFile(), 0640);
        // o assistente web não deve permanecer no servidor depois de usado
        $wiz = ROOT . '/public/install.php';
        if (is_file($wiz)) {
            if (@unlink($wiz)) $log('Assistente de instalação removido (public/install.php).');
            else $log('AVISO: apague manualmente public/install.php (está bloqueado, mas não precisa mais existir).');
        }
        $log('Instalação concluída.');
        return ['admin_created' => $adminCreated, 'applied' => $applied, 'reused' => $reused];
    }

    /** Cria/redefine um administrador (usado pelo CLI create-admin) */
    public static function upsertAdmin(string $name, string $email, string $pass): void
    {
        if (strlen($pass) < 4) throw new \RuntimeException('Senha curta demais (mínimo 4 caracteres).');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('E-mail inválido.');
        if (preg_match('/[<>"\'`\\\\]/', $name) || Util::len($name) < 2) throw new \RuntimeException('Nome inválido.');
        Db::q("INSERT INTO users (id, name, email, password_hash, role, shift, status) VALUES (?,?,?,?, 'ADM', 'GERAL', 'APROVADO')
               ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'ADM', status = 'APROVADO',
                 token_version = token_version + 1, failed_logins = 0, locked_until = NULL",
            [Util::newId('usr'), $name, strtolower($email), Util::hashPassword($pass)]);
    }
}
