<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Cloudflare;

use Panel\Core\AppException;

class CloudflareClient
{
    public function __construct(private string $token, private ?string $email = null)
    {
        if ($token === '' || preg_match('/[\r\n]/', $token) || ($email !== null && (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)))) { throw new AppException('Cloudflare 认证信息格式不正确'); }
    }

    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        if (!preg_match('#^/(zones|user)(/|$)#', $path) || str_contains($path, '..')) { throw new AppException('不支持的 Cloudflare API 路径', 500); }
        $url = 'https://api.cloudflare.com/client/v4' . $path . ($query ? '?' . http_build_query($query) : '');
        $headers=$this->email===null?['Authorization: Bearer '.$this->token]:['X-Auth-Email: '.$this->email,'X-Auth-Key: '.$this->token];
        [$response,$http,$errno,$retryAfter]=$this->send($method,$url,[...$headers,'Content-Type: application/json','User-Agent: Cloudflare-SaaS-Panel/0.1'],$body);
        if ($response === false || $errno) { throw new AppException('Cloudflare 请求失败或超时，稍后会对账重试', 503, 'cloudflare_network'); }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            $messages = array_map(static fn(array $e): string => (string)($e['code'] ?? '') . ' ' . (string)($e['message'] ?? ''), (array)($decoded['errors'] ?? []));
            $message = implode('; ', $messages) ?: 'Cloudflare 返回非预期响应';
            $message = str_replace(array_filter([$this->token,$this->email]), '[redacted]', $message);
            $notFound=$http===404||in_array(1551,array_map(static fn($e)=>(int)($e['code']??0),(array)($decoded['errors']??[])),true);
            throw new AppException('Cloudflare：' . mb_substr($message, 0, 1000), $http === 429 || $http >= 500 ? 503 : ($notFound ? 404 : 422), $http === 429 ? 'cloudflare_rate_limit' : ($notFound?'cloudflare_not_found':'cloudflare_error'), $retryAfter);
        }
        return $decoded;
    }

    protected function send(string $method,string $url,array $headers,?array $body):array
    {
        $curl=curl_init($url);$retryAfter=null;
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$retryAfter):int { if (stripos($line,'Retry-After:')===0) { $value=trim(substr($line,12));$retryAfter=ctype_digit($value)?min(86400,(int)$value):max(1,(strtotime($value)?:time())-time()); }return strlen($line); },CURLOPT_HTTPHEADER=>$headers]);
        if ($body!==null) { curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR)); }
        $response=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
        return [$response,$http,$errno,$retryAfter];
    }

    public function pages(string $path, array $query = []): array
    {
        $result = [];
        for ($page = 1; $page <= 1000; $page++) {
            $response = $this->request('GET', $path, null, array_merge($query, ['page' => $page, 'per_page' => 50]));
            $result = array_merge($result, $response['result'] ?? []);
            $total = (int)($response['result_info']['total_pages'] ?? 1);
            if ($page >= $total) { return $result; }
        }
        throw new AppException('Cloudflare 分页超出上限，请缩小查询范围');
    }
}
