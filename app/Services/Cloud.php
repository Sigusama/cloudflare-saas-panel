<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database, Crypto, AppException};
use Panel\Cloudflare\CloudflareClient;
use Panel\Dns\{DnsProvider, CloudflareDns};

final class Cloud
{
    public function __construct(private Database $db, private Crypto $crypto, private mixed $factory = null) {}
    public function client(int $accountId): CloudflareClient
    {
        $account = $this->db->one('SELECT * FROM accounts WHERE id=? AND active=1 AND deleted_at IS NULL', [$accountId]);
        if (!$account) { throw new AppException('Cloudflare 账号不存在或已停用'); }
        if ($this->factory) { return ($this->factory)($accountId); }
        $credential=CloudflareCredential::stored($account['auth_type'],$this->crypto->decrypt($account['token_encrypted']));
        return new CloudflareClient($credential['secret'],$credential['email']);
    }
    public function dns(int $accountId): DnsProvider { return new CloudflareDns($this->client($accountId)); }
}
