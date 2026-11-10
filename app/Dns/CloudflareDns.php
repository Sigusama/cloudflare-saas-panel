<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Dns;

use Panel\Cloudflare\CloudflareClient;
use Panel\Core\AppException;

final class CloudflareDns implements DnsProvider
{
    public function __construct(private CloudflareClient $client) {}
    public function zones():array { return $this->client->pages('/zones'); }
    public function capabilities():array { return ['proxy_switch'=>true,'apex_cname_flattening'=>true,'multi_value'=>true,'minimum_ttl'=>60]; }
    public function records(string $zone, string $name): array { return $this->client->pages('/zones/' . $zone . '/dns_records', ['name' => $name]); }
    public function create(string $zone, array $record): array { return $this->client->request('POST', '/zones/' . $zone . '/dns_records', $record)['result']; }
    public function update(string $zone, string $id, array $record): array { return $this->client->request('PUT', '/zones/' . $zone . '/dns_records/' . $id, $record)['result']; }
    public function delete(string $zone, string $id): void { $this->client->request('DELETE', '/zones/' . $zone . '/dns_records/' . $id); }
    public function get(string $zone, string $id): ?array
    {
        try { return $this->client->request('GET', '/zones/' . $zone . '/dns_records/' . $id)['result']; }
        catch (AppException $e) { if ($e->status === 404) { return null; } throw $e; }
    }
}
