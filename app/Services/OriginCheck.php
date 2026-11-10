<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

final class OriginCheck
{
    public static function validate(Database $db,Cloud $cloud,array $profile,array $origin):void
    {
        $fallback=$cloud->client((int)$profile['account_id'])->request('GET','/zones/'.$profile['zone_id'].'/custom_hostnames/fallback_origin')['result'];
        if (($fallback['status']??'')!=='active'||($fallback['origin']??'')!==$profile['fallback_origin']) { throw new AppException('默认回源未就绪或与配置不一致'); }
        if ($origin['mode']!=='custom') { return; }
        $zone=null;
        foreach ($db->all('SELECT * FROM zones WHERE account_id=? ORDER BY CHAR_LENGTH(name) DESC',[$profile['account_id']]) as $candidate) {
            if (Validator::zoneMatches($origin['hostname'],$candidate['name'])) { $zone=$candidate;break; }
        }
        if (!$zone) { throw new AppException('独立回源需为 SaaS 账号可管理的主机名，请创建代理记录并同步 Zone'); }
        $records=$cloud->dns((int)$profile['account_id'])->records($zone['cf_id'],$origin['hostname']);
        if (!array_filter($records,static fn($r):bool=>in_array($r['type'],['A','AAAA','CNAME'],true)&&!empty($r['proxied']))) { throw new AppException('独立回源缺少有效橙云记录：'.$origin['hostname']); }
    }
}
