<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\Database;

final class Audit
{
    public function __construct(private Database $db) {}
    public function write(string $actor, string $event, string $message, ?int $domain = null, ?int $job = null): void
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message);
        $this->db->run('INSERT INTO audit_logs(actor,event,message,domain_id,job_id) VALUES(?,?,?,?,?)', [$actor, $event, mb_substr($message, 0, 3000), $domain, $job]);
    }
}
