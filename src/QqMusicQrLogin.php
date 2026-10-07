<?php

namespace plugin\qqmusic;

use app\exception\ApiException;

/** QQ 扫码登录：ptlogin 二维码 → check_sig 取 p_skey → QQ 互联授权码 → QQConnectLogin 换取 musickey。 */
final class QqMusicQrLogin
{
    private const APP_ID = '716027609';
    private const DAID = '383';
    private const CONNECT_APP_ID = '100497308';
    private const LOGIN_JUMP = 'https://graph.qq.com/oauth2.0/login_jump';
    private const REDIRECT_URI = 'https://y.qq.com/portal/wx_redirect.html?login_type=1&surl=https://y.qq.com/';
    private const REFERER = 'Referer: https://xui.ptlogin2.qq.com/';
    private const MUSICU = 'https://u.y.qq.com/cgi-bin/musicu.fcg';

    public function __construct(private CurlHttp $http = new CurlHttp())
    {
    }

    /** @return array{qr_image:string, context:array{qrsig:string}} */
    public function start(): array
    {
        $response = $this->http->request('GET', 'https://ssl.ptlogin2.qq.com/ptqrshow?' . http_build_query([
            'appid' => self::APP_ID,
            'e' => '2',
            'l' => 'M',
            's' => '3',
            'd' => '72',
            'v' => '4',
            't' => (string)(mt_rand() / mt_getrandmax()),
            'daid' => self::DAID,
            'pt_3rd_aid' => self::CONNECT_APP_ID,
        ]), [self::REFERER]);
        $qrsig = CurlHttp::cookies($response['headers'])['qrsig'] ?? '';
        if ($qrsig === '' || $response['status'] !== 200 || $response['body'] === '') {
            throw new ApiException('QQMUSIC_QR_START_FAILED', 'QQ 登录二维码获取失败', 502);
        }
        return [
            'qr_image' => 'data:image/png;base64,' . base64_encode($response['body']),
            'context' => ['qrsig' => $qrsig],
        ];
    }

    /** @return array{status:string, message:string, credentials?:array<string, string>} */
    public function poll(array $context): array
    {
        $qrsig = (string)($context['qrsig'] ?? '');
        if ($qrsig === '') {
            throw new ApiException('QQMUSIC_QR_CONTEXT_INVALID', '扫码会话无效，请重新获取二维码', 422);
        }
        $response = $this->http->request('GET', 'https://ssl.ptlogin2.qq.com/ptqrlogin?' . http_build_query([
            'u1' => self::LOGIN_JUMP,
            'ptqrtoken' => (string)QqMusicClient::time33($qrsig, 0),
            'ptredirect' => '0',
            'h' => '1',
            't' => '1',
            'g' => '1',
            'from_ui' => '1',
            'ptlang' => '2052',
            'action' => '0-0-' . (int)(microtime(true) * 1000),
            'js_ver' => '20102616',
            'js_type' => '1',
            'pt_uistyle' => '40',
            'aid' => self::APP_ID,
            'daid' => self::DAID,
            'pt_3rd_aid' => self::CONNECT_APP_ID,
            'has_onekey' => '1',
        ]), [self::REFERER, 'Cookie: qrsig=' . $qrsig]);
        if (!preg_match('/ptuiCB\((.*)\)/s', $response['body'], $match)) {
            throw new ApiException('QQMUSIC_QR_RESPONSE_INVALID', 'QQ 登录状态返回异常', 502);
        }
        preg_match_all("/'((?:\\\\.|[^'])*)'/", $match[1], $args);
        $args = $args[1];
        $code = (int)($args[0] ?? -1);
        return match ($code) {
            66 => ['status' => 'waiting', 'message' => '等待使用 QQ 扫码'],
            67 => ['status' => 'scanned', 'message' => '已扫码，请在手机 QQ 中确认登录'],
            65 => ['status' => 'expired', 'message' => '二维码已过期，请重新获取'],
            68 => ['status' => 'expired', 'message' => '已在手机上拒绝登录，请重新获取二维码'],
            0 => ['status' => 'succeeded', 'message' => 'QQ 扫码登录成功', 'credentials' => $this->authorize((string)($args[2] ?? ''))],
            default => throw new ApiException('QQMUSIC_QR_LOGIN_FAILED', trim((string)($args[4] ?? '')) ?: "QQ 扫码登录失败（{$code}）", 502),
        };
    }

    /** @return array<string, string> */
    private function authorize(string $checkSigUrl): array
    {
        $host = strtolower((string)parse_url($checkSigUrl, PHP_URL_HOST));
        if (parse_url($checkSigUrl, PHP_URL_SCHEME) !== 'https' || !str_ends_with($host, '.qq.com')) {
            throw new ApiException('QQMUSIC_QR_LOGIN_FAILED', 'QQ 登录回调地址无效', 502);
        }
        $checkSig = $this->http->request('GET', $checkSigUrl, [self::REFERER]);
        $cookies = CurlHttp::cookies($checkSig['headers']);
        $pSkey = $cookies['p_skey'] ?? $cookies['skey'] ?? '';
        if ($pSkey === '') {
            throw new ApiException('QQMUSIC_QR_LOGIN_FAILED', 'QQ 授权成功，但未取得登录状态', 502);
        }

        $cookieHeader = implode('; ', array_map(
            static fn(string $name, string $value): string => $name . '=' . $value,
            array_keys($cookies),
            $cookies,
        ));
        $authorize = $this->http->request('POST', 'https://graph.qq.com/oauth2.0/authorize', [
            self::REFERER,
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: ' . $cookieHeader,
        ], http_build_query([
            'response_type' => 'code',
            'client_id' => self::CONNECT_APP_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'get_user_info,get_app_friends',
            'state' => 'state',
            'switch' => '',
            'from_ptlogin' => '1',
            'src' => '1',
            'update_auth' => '1',
            'openapi' => '1010_1030',
            'g_tk' => (string)QqMusicClient::time33($pSkey),
            'auth_time' => (string)(int)(microtime(true) * 1000),
            'ui' => self::uuid(),
        ]));
        $location = CurlHttp::header($authorize['headers'], 'Location');
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $authCode = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($authCode === '') {
            throw new ApiException('QQMUSIC_QR_LOGIN_FAILED', 'QQ 互联授权失败，未取得授权码', 502);
        }
        return $this->exchange($authCode);
    }

    /** @return array<string, string> */
    private function exchange(string $authCode): array
    {
        $body = json_encode([
            'comm' => [
                'ct' => 24,
                'cv' => 4747474,
                'platform' => 'yqq.json',
                'chid' => '0',
                'format' => 'json',
                'inCharset' => 'utf-8',
                'outCharset' => 'utf-8',
                'notice' => 0,
                'need_new_code' => 1,
                'tmeLoginType' => 2,
            ],
            'req_0' => [
                'module' => 'QQConnectLogin.LoginServer',
                'method' => 'QQLogin',
                'param' => ['code' => $authCode],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = $this->http->request('POST', self::MUSICU, [
            'Content-Type: application/json',
            'Referer: https://y.qq.com/',
            'Origin: https://y.qq.com',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ], $body);
        $node = json_decode($response['body'], true)['req_0'] ?? null;
        $data = is_array($node) && is_array($node['data'] ?? null) ? $node['data'] : [];
        $musicId = (string)($data['musicid'] ?? $data['str_musicid'] ?? '');
        $musicKey = (string)($data['musickey'] ?? '');
        if (!ctype_digit($musicId) || $musicId === '0' || $musicKey === '') {
            $code = is_array($node) ? (int)($node['code'] ?? -1) : -1;
            throw new ApiException('QQMUSIC_QR_LOGIN_FAILED', "QQ 音乐登录失败（code={$code}）", 502);
        }
        return [
            'uin' => $musicId,
            'qm_keyst' => $musicKey,
            'openid' => (string)($data['openid'] ?? ''),
            'access_token' => (string)($data['access_token'] ?? ''),
        ];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
