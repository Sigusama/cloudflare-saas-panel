<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Core;

final class Crypto
{
    private string $key;
    public function __construct(string $encoded)
    {
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) { throw new AppException('应用密钥无效', 500); }
        $this->key = $key;
    }
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'cf-saas-panel:v1');
        if ($cipher === false) { throw new AppException('凭据加密失败', 500); }
        return base64_encode($iv . $tag . $cipher);
    }
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) { throw new AppException('凭据密文无效', 500); }
        $value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'cf-saas-panel:v1');
        if ($value === false) { throw new AppException('凭据无法解密，请检查应用密钥', 500); }
        return $value;
    }
}
