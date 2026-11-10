<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Dns;

interface DnsProvider
{
    public function zones():array;
    public function capabilities():array;
    public function records(string $zone, string $name): array;
    public function create(string $zone, array $record): array;
    public function update(string $zone, string $id, array $record): array;
    public function delete(string $zone, string $id): void;
    public function get(string $zone, string $id): ?array;
}
