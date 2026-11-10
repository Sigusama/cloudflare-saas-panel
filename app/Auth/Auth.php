<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Auth;

use Panel\Core\{Database, AppException, Validator};
use Panel\Services\Audit;

final class Auth
{
    public const SCOPES = ['config:read', 'domains:read', 'domains:write', 'logs:read'];
    public function __construct(private Database $db) {}

    public static function session(bool $secure,string $installation): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        ini_set('session.use_strict_mode', '1');
        $installationHash=hash('sha256',$installation);
        session_name('cf_panel_'.substr($installationHash,0,24));
        session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Strict', 'path' => '/']);
        session_start();
        $scope=$_SESSION['panel_installation']??null;
        if(!is_string($scope)||!hash_equals($installationHash,$scope)){
            $_SESSION=[];
            session_regenerate_id(false);
            $_SESSION['panel_installation']=$installationHash;
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    }

    public static function csrf(): void
    {
        $supplied = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
        if (!is_string($supplied) || !hash_equals($_SESSION['csrf'] ?? '', $supplied) || empty($_SESSION['csrf'])) {
            throw new AppException('请求校验失败，请刷新页面', 403, 'csrf_failed');
        }
    }

    public function limit(string $bucket, int $max): void
    {
        $bucket .= ':' . (string)intdiv(time(), 60);
        $this->db->run('INSERT INTO api_rate_limits(bucket,hits,expires_at) VALUES(?,1,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)) ON DUPLICATE KEY UPDATE hits=hits+1', [$bucket]);
        $hits=(int)$this->db->value('SELECT hits FROM api_rate_limits WHERE bucket=?', [$bucket]);
        if (PHP_SAPI!=='cli'&&str_starts_with($bucket,'api:')) { header('X-RateLimit-Limit: '.$max);header('X-RateLimit-Remaining: '.max(0,$max-$hits));header('X-RateLimit-Reset: '.((intdiv(time(),60)+1)*60)); }
        if ($hits > $max) {
            if (PHP_SAPI !== 'cli') { header('Retry-After: ' . (60 - time() % 60)); }
            throw new AppException('请求过于频繁，请稍后重试', 429, 'rate_limited');
        }
    }

    public function login(string $username, string $password): void
    {
        $this->limit('login:' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ':' . $username), 10);
        $user = $this->db->one('SELECT * FROM users WHERE username=?', [$username]);
        if (!$user || !password_verify($password, $user['password_hash'])) { throw new AppException('用户名或密码不正确', 401); }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        (new Audit($this->db))->write('user:' . $user['id'], 'auth.login', '管理员登录');
    }

    public function identity(string $scope, bool $write = false, bool $adminOnly = false): string
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authorization !== '') {
            if ($adminOnly) { throw new AppException('此操作仅限管理员会话', 403); }
            if (!preg_match('/^Bearer ([A-Za-z0-9_\-]{32,150})$/D', $authorization, $matches)) { throw new AppException('API Key 格式不正确', 401); }
            $token = $this->db->one('SELECT * FROM api_tokens WHERE token_hash=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())', [hash('sha256', $matches[1])]);
            if (!$token) { throw new AppException('API Key 无效、已过期或已撤销', 401); }
            $this->limit('api:' . $token['id'], max(10,min(1000,(int)(getenv('API_RATE_LIMIT')?:60))));
            if (!in_array($scope, json_decode($token['scopes'], true), true)) { throw new AppException('API Key 缺少权限：' . $scope, 403); }
            $this->db->run('UPDATE api_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?', [$token['id']]);
            return 'api:' . $token['id'];
        }
        if (empty($_SESSION['user_id'])) { throw new AppException('请登录或提供 API Key', 401); }
        if ($write) { self::csrf(); }
        return 'user:' . $_SESSION['user_id'];
    }

    public function createToken(array $input): array
    {
        $scopes = array_values(array_intersect(self::SCOPES, (array)($input['scopes'] ?? [])));
        if ($scopes === []) { throw new AppException('至少选择一项 API 权限'); }
        $expires = null;
        if (!empty($input['expires_at'])) {
            $time = strtotime((string)$input['expires_at']);
            if ($time === false || $time <= time()) { throw new AppException('有效期必须晚于当前时间'); }
            $expires = gmdate('Y-m-d H:i:s', $time);
        }
        $raw = 'cfs_' . bin2hex(random_bytes(32));
        $id = $this->db->insert('INSERT INTO api_tokens(name,token_hash,scopes,expires_at) VALUES(?,?,?,?)', [Validator::label($input['name'] ?? ''), hash('sha256', $raw), Validator::json($scopes), $expires]);
        return ['id' => $id, 'key' => $raw, 'scopes' => $scopes];
    }

    public function idempotent(string $owner, ?string $key, array $payload, callable $operation,string $route=''): array
    {
        if ($key === null || $key === '') { return $this->db->transaction($operation); }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) { throw new AppException('Idempotency-Key 格式不正确'); }
        $canonicalize = function (array $value) use (&$canonicalize): array {
            if (!array_is_list($value)) { ksort($value); }
            foreach ($value as &$item) { if (is_array($item)) { $item = $canonicalize($item); } }
            return $value;
        };
        $fingerprint = hash('sha256', Validator::json(['route'=>$route,'body'=>$canonicalize($payload)]));
        return $this->db->transaction(function () use ($owner, $key, $fingerprint, $operation): array {
            $this->db->run('INSERT IGNORE INTO idempotency_requests(owner,request_key,fingerprint) VALUES(?,?,?)', [$owner, $key, $fingerprint]);
            $record = $this->db->one('SELECT * FROM idempotency_requests WHERE owner=? AND request_key=? FOR UPDATE', [$owner, $key]);
            if (!hash_equals($record['fingerprint'], $fingerprint)) { throw new AppException('该幂等键已经用于不同请求', 409, 'idempotency_conflict'); }
            if ($record['response_json'] !== null) { return json_decode($record['response_json'], true, 512, JSON_THROW_ON_ERROR); }
            $result = $operation();
            $this->db->run('UPDATE idempotency_requests SET response_json=? WHERE owner=? AND request_key=?', [Validator::json($result), $owner, $key]);
            return $result;
        });
    }
}
