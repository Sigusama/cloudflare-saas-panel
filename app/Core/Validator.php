<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Core;

final class Validator
{
    public static function hostname(mixed $input): string
    {
        if (!is_string($input)) { throw new AppException('域名必须是字符串'); }
        $name = strtolower(rtrim(trim($input), '.'));
        if($name===''){throw new AppException('请输入完整域名，不包含协议、端口或路径');}
        if (function_exists('idn_to_ascii')) {
            $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }
        if (strlen($name) > 253 || !str_contains($name, '.') || filter_var($name, FILTER_VALIDATE_IP)) {
            throw new AppException('请输入完整域名，不包含协议、端口或路径');
        }
        foreach (explode('.', $name) as $label) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) { throw new AppException('域名格式不正确，首版不支持泛域名'); }
        }
        return $name;
    }
    public static function publicIp(mixed $value): string
    {
        if (!is_string($value)) { throw new AppException('IP 必须是字符串'); }
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new AppException('入口必须是可用的公网 IPv4 或 IPv6');
        }
        $binary = inet_pton($value);
        if ($binary === false || (strlen($binary) === 16 && (substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || (ord($binary[0]) & 0xe0) !== 0x20))) {
            throw new AppException('不支持该类 IPv6 地址');
        }
        return inet_ntop($binary);
    }
    public static function label(mixed $value): string
    {
        if (!is_string($value)) { throw new AppException('名称必须是字符串'); }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 100) { throw new AppException('名称必填且不超过 100 字'); }
        return $value;
    }
    public static function zoneMatches(string $hostname, string $zone): bool
    {
        return $hostname === $zone || str_ends_with($hostname, '.' . $zone);
    }
    public static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    public static function boolean(mixed $value):bool
    {
        if (!in_array($value,[true,false,0,1,'0','1'],true)) { throw new AppException('布尔字段必须为 true 或 false'); }
        return $value===true||$value===1||$value==='1';
    }
}
