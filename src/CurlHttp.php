<?php

namespace plugin\qqmusic;

use app\exception\ApiException;

/** Runner 进程不在协程中，插件自带最小 curl 客户端。 */
class CurlHttp
{
    /**
     * @param list<string> $headers
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException('QQMUSIC_HTTP_FAILED', 'QQ 音乐请求初始化失败', 502);
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw)) {
            throw new ApiException('QQMUSIC_HTTP_FAILED', 'QQ 音乐请求失败：' . ($error !== '' ? $error : '无响应'), 502);
        }
        return ['status' => $status, 'body' => $raw];
    }
}
