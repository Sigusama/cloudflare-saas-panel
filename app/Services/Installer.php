<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Config, Database, AppException, Validator};

final class Installer
{
    public function checks(): array
    {
        $checks = ['PHP 8.3+' => version_compare(PHP_VERSION, '8.3.0', '>=')];
        foreach (['pdo', 'pdo_mysql', 'curl', 'openssl', 'mbstring', 'intl'] as $extension) {
            $checks[$extension] = extension_loaded($extension);
        }
        $probe=Config::storage('.write-check-'.bin2hex(random_bytes(6)));
        $checks['storage 可写'] = is_dir(Config::storage()) && @file_put_contents($probe,'check',LOCK_EX)!==false;
        if (is_file($probe)) { @unlink($probe); }
        return $checks;
    }

    public function token(): string
    {
        if (Config::installed()) { throw new AppException('站点已安装，不能重新生成安装口令', 409); }
        if (!is_dir(Config::storage())) { mkdir(Config::storage(), 0700, true); }
        $token = bin2hex(random_bytes(24));
        Config::atomicWrite(Config::storage('install-token'), hash('sha256', $token));
        return $token;
    }

    public function install(array $input): void
    {
        if (Config::installed()) { throw new AppException('站点已经安装', 409, 'already_installed'); }
        $file = Config::storage('install-token');
        if (!is_file($file) || !hash_equals(trim(file_get_contents($file)), hash('sha256', (string)($input['install_token'] ?? '')))) {
            throw new AppException('安装口令不正确，请在终端生成口令', 403);
        }
        if (in_array(false, $this->checks(), true)) { throw new AppException('环境检查未通过'); }
        $installationLock=fopen(Config::storage('installing.lock'),'c');
        if ($installationLock===false||!flock($installationLock,LOCK_EX|LOCK_NB)) { throw new AppException('另一个安装请求正在执行',409); }
        try {
        if (Config::installed()) { throw new AppException('站点已经安装',409); }
        $username = Validator::label($input['username'] ?? '');
        $password = (string)($input['password'] ?? '');
        if (strlen($password) < 12 || strlen($password) > 72) { throw new AppException('管理员密码长度为 12 至 72 字节'); }
        $url = rtrim((string)($input['app_url'] ?? ''), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) { throw new AppException('站点地址必须是完整 HTTP 或 HTTPS 地址'); }
        $database = [
            'host' => trim((string)($input['db_host'] ?? '')),
            'port' => (int)($input['db_port'] ?? 3306),
            'name' => trim((string)($input['db_name'] ?? '')),
            'user' => (string)($input['db_user'] ?? ''),
            'password' => (string)($input['db_password'] ?? ''),
        ];
        try { $db = new Database($database); }
        catch (\PDOException) { throw new AppException('数据库连接失败，请检查地址、库名、用户名与密码'); }
        if (!(int)$db->value("SELECT GET_LOCK('cf_panel_install',0)")) { throw new AppException('另一个安装请求正在执行', 409); }
        try {
            if (Config::installed()) { throw new AppException('站点已经安装', 409); }
            $db->migrate();
            if ((int)$db->value('SELECT COUNT(*) FROM users') > 0) {
                if (is_file(Config::storage('config.php'))&&Config::read()['db']===$database&&$db->value("SELECT value FROM settings WHERE name='installed_at'")) {
                    new \Panel\Core\Crypto(Config::read()['key']);
                    Config::atomicWrite(Config::storage('installed.lock'),gmdate('c'));
                    @unlink($file);
                    return;
                }
                throw new AppException('数据库中已有管理员，请恢复原配置文件，不能覆盖安装', 409);
            }
            $config = ['db' => $database, 'key' => base64_encode(random_bytes(32)), 'app_url' => $url];
            Config::atomicWrite(Config::storage('config.php'), "<?php\nreturn " . var_export($config, true) . ";\n");
            $db->transaction(function () use ($db, $username, $password): void {
                $db->run('INSERT INTO users(username,password_hash) VALUES(?,?)', [$username, password_hash($password, PASSWORD_DEFAULT)]);
                $db->run("INSERT INTO settings(name,value) VALUES('installed_at',?)", [gmdate('c')]);
            });
            Config::atomicWrite(Config::storage('installed.lock'), gmdate('c'));
            @unlink($file);
        } finally { $db->value("SELECT RELEASE_LOCK('cf_panel_install')"); }
        } finally { flock($installationLock,LOCK_UN);fclose($installationLock); }
    }
}
