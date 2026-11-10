<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\AppException;

final class CloudflareCredential
{
    public static function input(array $input, ?array $previous = null): array
    {
        $type = (string)($input['auth_type'] ?? $previous['auth_type'] ?? 'api_token');
        if (!in_array($type, ['api_token', 'global_key'], true)) { throw new AppException('Cloudflare 认证方式不正确'); }
        $global = $type === 'global_key';
        if (($global && !empty($input['token'])) || (!$global && (!empty($input['global_key']) || !empty($input['email'])))) { throw new AppException('请只填写所选认证方式的凭据'); }
        $secret = trim((string)($input[$global ? 'global_key' : 'token'] ?? ''));
        if ($secret === '' && ($previous['auth_type'] ?? null) === $type) { $secret = $previous['secret']; }
        if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/D', $secret)) { throw new AppException($global ? '请填写有效的 Global API Key；切换认证方式时需要重新填写密钥' : '请填写有效的 API Token；切换认证方式时需要重新填写 Token'); }
        $email = $global ? trim((string)($input['email'] ?? (($previous['auth_type'] ?? null) === $type ? $previous['email'] : ''))) : null;
        if ($global && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email))) { throw new AppException('请填写该 Global API Key 所属的 Cloudflare 账号邮箱'); }
        return ['auth_type' => $type, 'email' => $email, 'secret' => $secret];
    }

    public static function pack(array $credential): string
    {
        return $credential['auth_type'] === 'api_token' ? $credential['secret'] : json_encode(['email' => $credential['email'], 'key' => $credential['secret']], JSON_THROW_ON_ERROR);
    }

    public static function stored(string $type, string $decrypted): array
    {
        if ($type === 'api_token') { return self::input(['token' => $decrypted]); }
        if ($type !== 'global_key') { throw new AppException('Cloudflare 认证配置不正确', 500); }
        $decoded = json_decode($decrypted, true);
        if (!is_array($decoded)) { throw new AppException('Cloudflare 凭据格式不正确', 500); }
        return self::input(['auth_type' => 'global_key', 'email' => $decoded['email'] ?? '', 'global_key' => $decoded['key'] ?? '']);
    }
}
