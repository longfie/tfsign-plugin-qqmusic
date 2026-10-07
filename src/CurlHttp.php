<?php

namespace plugin\qqmusic;

use app\exception\ApiException;

/** Runner 进程不在协程中，插件自带最小 curl 客户端。 */
class CurlHttp
{
    /**
     * @param list<string> $headers
     * @return array{status:int, body:string, headers:list<string>}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException('QQMUSIC_HTTP_FAILED', 'QQ 音乐请求初始化失败', 502);
        }
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }
                return strlen($line);
            },
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
        return ['status' => $status, 'body' => $raw, 'headers' => $responseHeaders];
    }

    /**
     * @param list<string> $headers
     * @return array<string, string>
     */
    public static function cookies(array $headers): array
    {
        $cookies = [];
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $header, $match) && $match[2] !== '') {
                $cookies[$match[1]] = $match[2];
            }
        }
        return $cookies;
    }

    /** @param list<string> $headers */
    public static function header(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return '';
    }
}
