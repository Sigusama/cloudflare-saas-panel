<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Validator,AppException};

/** Read-only baselines for pre-existing resources. Never implies ownership. */
final class ObservedDns
{
    public static function sameTargets(array $a,array $b):bool{sort($a);sort($b);return $a===$b;}
    public static function records(array $records):array
    {
        return array_map(static function(array $row):array {
            $out=array_intersect_key($row,array_flip(['id','name','type','content','ttl','proxied','comment','priority','tags','settings','data']));
            $out['name']=strtolower(rtrim((string)($row['name']??''),'.'));$out['type']=strtoupper((string)($row['type']??''));
            $out['content']=(string)($row['content']??'');$out['ttl']=(int)($row['ttl']??1);$out['proxied']=(bool)($row['proxied']??false);
            if(in_array($out['type'],['A','AAAA'],true)&&($binary=@inet_pton($out['content']))!==false){$out['content']=inet_ntop($binary);}
            return $out;
        },$records);
    }
    public static function fingerprint(array $records):string
    {
        $canonical=function(mixed $value)use(&$canonical):mixed{if(!is_array($value)){return $value;}if(!array_is_list($value)){ksort($value);}return array_map($canonical,$value);};
        $rows=array_map(static fn($r)=>json_encode($canonical($r),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),self::records($records));sort($rows);
        return hash('sha256',implode("\n",$rows));
    }
    public static function assertSame(array $before,array $now,string $name):void
    {
        if(!hash_equals(self::fingerprint($before),self::fingerprint($now))){throw new AppException('云端 DNS 已变化，请重新读取后确认：'.$name,409,'inventory_stale');}
    }
    public static function verifySource(array $origin,Cloud $cloud):void
    {
        if(empty($origin['external_dns'])){return;}$baseline=$origin['external_dns'];
        self::assertSame($baseline['records'],$cloud->dns((int)$baseline['account_id'])->records($baseline['zone_id'],$origin['hostname']),$origin['hostname']);
    }
}
