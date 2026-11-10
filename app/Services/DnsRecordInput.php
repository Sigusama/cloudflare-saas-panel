<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;
use Panel\Core\{Validator,AppException};

final class DnsRecordInput
{
    public static function name(string $name,?string $zone=null):string
    {
        $name=strtolower(rtrim(trim($name),'.'));
        if(preg_match('/[^\x00-\x7f]/',$name)){$name=idn_to_ascii($name,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46)?:'';}
        if($zone!==null){$name=$name==='@'?$zone:(Validator::zoneMatches($name,$zone)?$name:$name.'.'.$zone);}
        if(strlen($name)>253||!str_contains($name,'.')){throw new AppException('请填写有效 DNS 名称');}
        foreach(explode('.',$name) as $i=>$label){if($i===0&&$label==='*'){continue;}if(!preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/D',$label)){throw new AppException('DNS 名称格式不正确');}}
        return $name;
    }
    public static function changes(array $before,array $input,string $zone):array
    {
        $allowed=['name','type','content','ttl','proxied','priority','comment','tags','settings','data'];
        if(array_diff(array_keys($input),$allowed)){throw new AppException('DNS 修改包含不支持的字段');}
        $patch=$input;
        if(array_key_exists('name',$patch)){if(!is_string($patch['name'])){throw new AppException('名称必须为字符串');}$patch['name']=self::name($patch['name'],$zone);}
        if(isset($patch['type'])){if(!is_string($patch['type'])||!preg_match('/^[A-Z][A-Z0-9]{0,15}$/D',$patch['type'])){throw new AppException('DNS 类型不正确');}}
        if(array_key_exists('content',$patch)&&(!is_string($patch['content'])||strlen($patch['content'])>65536)){throw new AppException('DNS 内容必须为不超过 64 KB 的字符串');}
        if(isset($patch['ttl'])&&(!is_int($patch['ttl'])||!($patch['ttl']===1||$patch['ttl']>=30&&$patch['ttl']<=86400))){throw new AppException('TTL 请填 1（自动）或 30 至 86400 秒，30 秒仅企业站点可用');}
        if(array_key_exists('proxied',$patch)){$patch['proxied']=Validator::boolean($patch['proxied']);}
        if(array_key_exists('priority',$patch)&&(!is_int($patch['priority'])||$patch['priority']<0||$patch['priority']>65535)){throw new AppException('优先级需为 0 至 65535 的整数');}
        if(array_key_exists('comment',$patch)&&$patch['comment']!==null&&(!is_string($patch['comment'])||mb_strlen($patch['comment'])>500)){throw new AppException('注释不超过 500 字');}
        foreach(['settings','data'] as $field){if(isset($patch[$field])&&(!is_array($patch[$field])||$patch[$field]!==[]&&array_is_list($patch[$field])||strlen(Validator::json($patch[$field]))>16000)){throw new AppException($field.' 必须是 JSON 对象且不超过 16 KB');}}
        if(isset($patch['tags'])&&(!is_array($patch['tags'])||!array_is_list($patch['tags'])||count($patch['tags'])>20||array_filter($patch['tags'],fn($t)=>!is_string($t)||strlen($t)>256))){throw new AppException('tags 应为最多 20 个字符串');}
        $after=array_replace($before,$patch);$type=$after['type'];$content=(string)$after['content'];
        if(in_array($type,['A','AAAA'],true)&&!filter_var($content,FILTER_VALIDATE_IP,$type==='A'?FILTER_FLAG_IPV4:FILTER_FLAG_IPV6)){throw new AppException('记录类型与 IP 地址不匹配');}
        if(in_array($type,['CNAME','NS','MX'],true)){if(filter_var($content,FILTER_VALIDATE_IP)){throw new AppException('该记录类型需要域名，不能填 IP');}$normalized=self::name($content);if(array_key_exists('content',$patch)){$patch['content']=$normalized;}}
        if($type==='CNAME'&&strtolower(rtrim($content,'.'))===$after['name']){throw new AppException('CNAME 不能指向自身');}
        if(!empty($after['proxied'])){
            if(!in_array($type,['A','AAAA','CNAME'],true)){throw new AppException('只有 A / AAAA / CNAME 可开启小黄云');}
            $patch['ttl']=1;
        }
        return $patch;
    }
}
