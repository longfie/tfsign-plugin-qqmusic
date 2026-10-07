<?php

namespace plugin\qqmusic;

use app\exception\ApiException;

/** QQ 音乐 musics.fcg 网关：zzc 签名、g_tk 计算、musickey 续期与救活。 */
final class QqMusicClient
{
    private const API = 'https://u6.y.qq.com/cgi-bin/musics.fcg';
    private const USER_AGENT = 'QQMusic 20090008(android 15)';
    private const ZZC_PART1 = [23, 14, 6, 36, 16, 7, 19];
    private const ZZC_PART2 = [16, 1, 32, 12, 19, 27, 8, 5];
    private const ZZC_SCRAMBLE = [89, 39, 179, 150, 218, 82, 58, 252, 177, 52, 186, 123, 120, 64, 242, 133, 143, 161, 121, 179];
    /** 1000 = 未登录或 key 已过期，2000 = 登录态失效 */
    private const LOGIN_INVALID_CODES = [1000, 2000];

    public function __construct(
        private string $uin,
        private string $musicKey,
        private CurlHttp $http = new CurlHttp(),
    ) {
    }

    public function uin(): string
    {
        return $this->uin;
    }

    public function musicKey(): string
    {
        return $this->musicKey;
    }

    public static function time33(string $value): int
    {
        $hash = 5381;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $hash = ($hash + ($hash << 5) + ord($value[$i])) & 0xFFFFFFFF;
        }
        return $hash & 0x7FFFFFFF;
    }

    public static function zzcSign(string $payload): string
    {
        $hash = strtoupper(sha1($payload));
        $part1 = '';
        foreach (self::ZZC_PART1 as $index) {
            $part1 .= $hash[$index];
        }
        $part2 = '';
        foreach (self::ZZC_PART2 as $index) {
            $part2 .= $hash[$index];
        }
        $bytes = '';
        foreach (self::ZZC_SCRAMBLE as $i => $value) {
            $bytes .= chr($value ^ hexdec(substr($hash, $i * 2, 2)));
        }
        $middle = str_replace(['\\', '/', '+', '='], '', base64_encode($bytes));
        return strtolower('zzc' . $part1 . $middle . $part2);
    }

    /** @return array<string, mixed> */
    public function defaultComm(): array
    {
        return [
            'g_tk' => self::time33($this->musicKey),
            'uin' => (int)$this->uin,
            'format' => 'json',
            'inCharset' => 'utf-8',
            'outCharset' => 'utf-8',
            'notice' => 0,
            'platform' => 'h5',
            'needNewCode' => 1,
            'ct' => 23,
            'cv' => 0,
        ];
    }

    /** 主题装扮接口要求客户端形态的 comm。 */
    public function cosmeticComm(): array
    {
        $comm = $this->defaultComm();
        unset($comm['platform'], $comm['needNewCode']);
        $comm['ct'] = '11';
        $comm['cv'] = '20090008';
        return $comm;
    }

    /**
     * 调用单个 module.method，返回 req_0 节点（含 code 与 data）。
     *
     * @return array<string, mixed>
     */
    public function call(string $module, string $method, array $param = [], ?array $comm = null): array
    {
        $body = self::encode([
            'comm' => $comm ?? $this->defaultComm(),
            'req_0' => ['module' => $module, 'method' => $method, 'param' => (object)$param],
        ]);
        $url = self::API . '?_=' . (int)(microtime(true) * 1000) . '&sign=' . self::zzcSign($body);
        $response = $this->http->request('POST', $url, $this->headers(), $body);
        $node = self::decode($response['body'])['req_0'] ?? null;
        if (!is_array($node)) {
            throw new ApiException('QQMUSIC_BAD_RESPONSE', "QQ 音乐接口 {$method} 返回格式异常", 502);
        }
        if (in_array((int)($node['code'] ?? 0), self::LOGIN_INVALID_CODES, true)) {
            throw new ApiException('PLUGIN_CREDENTIAL_EXPIRED', 'QQ 音乐登录已过期，请重新填写 qm_keyst', 422);
        }
        return $node;
    }

    /**
     * 续期 musickey，旧 key 不会被吊销；一级续期失败时用 QQ 互联 openid/access_token 救活。
     * 拿不到新 key 时返回 null，调用方沿用当前 key。
     *
     * @return array{musickey:string, openid:string, access_token:string}|null
     */
    public function refresh(string $openid = '', string $accessToken = ''): ?array
    {
        $param = [
            'expired_in' => 7776000,
            'musicid' => (int)$this->uin,
            'musickey' => $this->musicKey,
        ];
        $result = $this->qqLogin($param);
        $musicKey = (string)($result['musickey'] ?? '');
        if (($musicKey === '' || $musicKey === $this->musicKey) && $openid !== '' && $accessToken !== '') {
            $result = $this->qqLogin($param + ['openid' => $openid, 'access_token' => $accessToken]);
            $musicKey = (string)($result['musickey'] ?? '');
        }
        if ($musicKey === '' || $musicKey === $this->musicKey) {
            return null;
        }
        $this->musicKey = $musicKey;
        return [
            'musickey' => $musicKey,
            'openid' => (string)($result['openid'] ?? '') ?: $openid,
            'access_token' => (string)($result['access_token'] ?? '') ?: $accessToken,
        ];
    }

    /** @return array<string, mixed> */
    private function qqLogin(array $param): array
    {
        $data = self::encode(['req1' => [
            'module' => 'QQConnectLogin.LoginServer',
            'method' => 'QQLogin',
            'param' => $param,
        ]]);
        $url = self::API . '?' . http_build_query([
            'sign' => self::zzcSign($data),
            'format' => 'json',
            'inCharset' => 'utf8',
            'outCharset' => 'utf-8',
            'data' => $data,
        ]);
        try {
            $response = $this->http->request('GET', $url, $this->headers());
            $node = self::decode($response['body'])['req1'] ?? null;
        } catch (ApiException) {
            return [];
        }
        return is_array($node) && is_array($node['data'] ?? null) ? $node['data'] : [];
    }

    /** @return list<string> */
    private function headers(): array
    {
        $key = $this->musicKey;
        return [
            'Content-Type: application/x-www-form-urlencoded',
            "Cookie: qm_keyst={$key}; qqmusic_key={$key}; p_lskey={$key}; uin=o{$this->uin}",
            'Referer: https://y.qq.com/',
            'Origin: https://y.qq.com',
            'User-Agent: ' . self::USER_AGENT,
        ];
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ApiException('QQMUSIC_BAD_RESPONSE', 'QQ 音乐接口返回非 JSON 内容', 502);
        }
        return $decoded;
    }
}
