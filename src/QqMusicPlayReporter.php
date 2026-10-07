<?php

namespace plugin\qqmusic;

/**
 * 收听节目（任务 20）的播放上报：cmd=2000049 统计埋点 + cmd=1 播放完成信号。
 * 设备标识按 QQ 号确定性派生，同一账号每次上报保持同一台“设备”。
 */
final class QqMusicPlayReporter
{
    private const REPORT_API = 'https://stat.y.qq.com/android/fcgi-bin/imusic_tj';
    private const TIMEKEY_SALT = 'gk2$Lh-&l4#!4iow';
    private const PLAY_SECONDS = 160;
    /** @var list<array{int, int}> [songid, albumid]，按年内日序轮换避免同曲去重 */
    private const SONG_POOL = [
        [368182354, 29145437],
        [508222545, 29145437],
        [367799432, 29145437],
        [493099611, 51326893],
        [368182357, 29145437],
        [270384187, 13210184],
    ];

    public function __construct(
        private string $uin,
        private string $musicKey,
        private string $tmeUid,
        private string $openid = '',
        private string $accessToken = '',
        private CurlHttp $http = new CurlHttp(),
    ) {
        if ($this->tmeUid === '') {
            $this->tmeUid = $this->uin;
        }
    }

    /** @return array{songid:int, album:int, stat_status:int, play_status:int} */
    public function report(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        [$songId, $albumId] = self::SONG_POOL[((int)$now->format('z') + 1) % count(self::SONG_POOL)];
        $statStatus = $this->send($this->statBody($now, $songId, $albumId));
        $playStatus = $this->send($this->playBody($now, $songId, $albumId));
        return ['songid' => $songId, 'album' => $albumId, 'stat_status' => $statStatus, 'play_status' => $playStatus];
    }

    public static function timekey(int $optime, int $playSeconds, string $uin): string
    {
        return strtoupper(md5($optime . $playSeconds . $uin . self::TIMEKEY_SALT));
    }

    private function send(string $xml): int
    {
        $body = gzencode($xml);
        if ($body === false) {
            return 0;
        }
        try {
            return $this->http->request('POST', self::REPORT_API, [
                'User-Agent: QQMusic 20090008(android 15)',
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Encoding: gzip',
            ], $body, 10)['status'];
        } catch (\Throwable) {
            return 0;
        }
    }

    private function statBody(\DateTimeImmutable $now, int $songId, int $albumId): string
    {
        $ts = $now->getTimestamp();
        return '<?xml version="1.0" encoding="UTF-8"?><root><fPersonality>0</fPersonality><tmeLoginType>2</tmeLoginType><tmeLoginMethod>2</tmeLoginMethod>'
            . $this->deviceXml(true)
            . '<modeSwitch>6</modeSwitch><teenMode>0</teenMode>'
            . '<M-Value>' . $this->device('mvalue') . '</M-Value><ui_mode>1</ui_mode><nettype>1020</nettype>'
            . $this->accountXml($now, $ts)
            . '<trigger_type>ipc</trigger_type><hotfix>200000000</hotfix><traceid>11_' . $this->tmeUid . '_' . $ts . '</traceid><cid>228</cid>' . "\n"
            . '<item cmd="2000049" optime="' . $ts . '" nettype="1020" QQ="' . $this->uin . '" uid="' . $this->tmeUid . '" os="15" model="V2338A" version="20.9.0.8"'
            . ' songid="' . $songId . '" int1="3" int2="1" int3="48" int4="1" int6="65540" str1="8,157,42800367," str2="album:' . $albumId . '"'
            . ' str3="s' . $songId . '.ct11.u' . $this->uin . '.t' . $ts . '001" int5="0" str4="" str5="" str6="0" abt="2553_2553004"/></root>';
    }

    private function playBody(\DateTimeImmutable $now, int $songId, int $albumId): string
    {
        $ts = $now->getTimestamp();
        $optime = $ts - self::PLAY_SECONDS - 5;
        $timekey = self::timekey($optime, self::PLAY_SECONDS, $this->uin);
        $playStats = base64_encode(json_encode([
            'screen_on' => 1, 'app_in' => 1, 'app_time' => 292, 'playpage_time' => 21,
            'start_playtype' => 0, 'start_playtime' => 0, 'start_app_in' => 1, 'start_screen_on' => 1,
            'no_pause_app_time' => self::PLAY_SECONDS, 'no_pause_playpage_time' => 19,
        ], JSON_UNESCAPED_SLASHES));
        $modeString = base64_encode(json_encode([
            'decoder_type' => '1', 'sound_balance' => '1', 'play_time_rev' => '0', 'usb_output_type' => '0',
            'play_list_type_id' => (string)$albumId, 'p2p_in_files_dir' => '1', 'volume' => '0.6', 'play_list_type' => '25',
            'super_resolution' => '0', 'server_shuffle_list' => '0', 'vturbo_status' => '2', 'alc' => '1',
            'output_sdk_type' => '0', 'retry_type' => '0',
        ], JSON_UNESCAPED_SLASHES));
        return '<?xml version="1.0" encoding="UTF-8"?><root>'
            . $this->deviceXml(false)
            . '<tmeLoginMethod>2</tmeLoginMethod><modeSwitch>6</modeSwitch><fPersonality>0</fPersonality><teenMode>0</teenMode><tmeLoginType>2</tmeLoginType>'
            . '<M-Value>' . $this->device('mvalue') . '</M-Value><ui_mode>1</ui_mode><nettype>1020</nettype>'
            . $this->accountXml($now, $ts)
            . '<trigger_type>hz_cs_vivoyzsst</trigger_type><hotfix>20010378</hotfix><traceid>11_' . $this->tmeUid . '_' . $ts . '</traceid><cid>228</cid>' . "\n"
            . '<item cmd="1" optime="' . $optime . '" nettype="1020" QQ="' . $this->uin . '" uid="' . $this->tmeUid . '" os="15" model="V2338A" version="20.9.0.8"'
            . ' songtype="1" playtype="5" from="9,30,330,320,157,42800367,157,42800367," openstore="0" crytype="2" paytype="3" hijackflag="0" abt="46276_46276002"'
            . ' searchid="" search_ext="' . base64_encode('31:' . $albumId) . '" int12="0" tjreport="" desktoplyric="0" playdevice="0" playlist_mode="0" toptype="10025"'
            . ' parentid="' . $albumId . '" string23="album:' . $albumId . '" outdev="0" url="16" playmode="1" repeat_times="-1" string25="normal" string26="n"'
            . ' string28="%7B%22musicQualitySource%22%3A1%2C%22longQualitySource%22%3A1%7D" string29="0" supersound="1" cdn="" cdnip="" hasFirstBuffer="3"'
            . ' filetype="4" err="0" time2="101" issoftdecode="1" string30="{&quot;firstBufferActions&quot;:{&quot;7&quot;:[106],&quot;0&quot;:[0]},&quot;secondBufferTimes&quot;:[]}"'
            . ' component_type="-1" wait_time="1973" player_retry="0" audiotime="210168" timekey="' . $timekey . '" vkey=""'
            . ' time="' . self::PLAY_SECONDS . '" play_duration_mi="' . (self::PLAY_SECONDS * 1000 + 47) . '" errcode="" play_speed="1.0" vip_level="65540"'
            . ' audio_effect="19:50001_50:1" mode_string="' . $modeString . '" string27="' . $playStats . '"'
            . ' songid="' . $songId . '" fversion="7" int28="102367" int29="7" buildver="1"/></root>';
    }

    private function deviceXml(bool $withSecondUdid): string
    {
        $udid = $this->device('udid');
        $xml = '<OpenUDID>' . $udid . '</OpenUDID><udid>' . $udid . '</udid><ct>11</ct><cv>20090008</cv><v>20090008</v><chid>10003505</chid><os_ver>15</os_ver>'
            . '<aid>' . $this->device('aid') . '</aid><phonetype>V2338A</phonetype>';
        if ($withSecondUdid) {
            $xml .= '<OpenUDID2>' . $this->device('udid2') . '</OpenUDID2>';
        }
        return $xml . '<devicelevel>50</devicelevel><newdevicelevel>40</newdevicelevel><deviceScore>804.16</deviceScore>'
            . '<QIMEI36>' . $this->device('qimei') . '</QIMEI36><oaid>' . $this->device('oaid') . '</oaid><taid>' . $this->device('taid') . '</taid>'
            . '<tmeAppID>qqmusic</tmeAppID><tid>' . time() . '0000abcd</tid>';
    }

    private function accountXml(\DateTimeImmutable $now, int $ts): string
    {
        $xml = '<wid>' . $this->tmeUid . '</wid><rom>vivo/FUNTOUCH/OriginOS 5</rom><uid>' . $this->tmeUid . '</uid>'
            . '<sid>' . $now->format('YmdHis') . $this->tmeUid . '</sid><qq>' . $this->uin . '</qq>'
            . '<authst>' . htmlspecialchars($this->musicKey, ENT_XML1 | ENT_QUOTES) . '</authst>';
        if ($this->openid !== '' && $this->accessToken !== '') {
            $xml .= '<psrf_qqopenid>' . htmlspecialchars($this->openid, ENT_XML1 | ENT_QUOTES) . '</psrf_qqopenid>'
                . '<psrf_access_token_expiresAt>' . ($ts + 7776000) . '</psrf_access_token_expiresAt>'
                . '<psrf_qqaccess_token>' . htmlspecialchars($this->accessToken, ENT_XML1 | ENT_QUOTES) . '</psrf_qqaccess_token>';
        }
        return $xml . '<tyt_exp_env>0</tyt_exp_env>';
    }

    private function device(string $field): string
    {
        return match ($field) {
            'udid' => '00000000' . $this->seed('udid', 24),
            'udid2' => '00000000' . $this->seed('udid2', 24),
            'aid' => $this->seed('aid', 16),
            'qimei' => $this->seed('qimei', 36),
            'oaid' => strtoupper($this->seed('oaid', 128) . $this->seed('oaid2', 18)),
            'taid' => strtoupper($this->seed('taid', 88)),
            'mvalue' => base64_encode((string)hex2bin($this->seed('mvalue', 32))),
            default => '',
        };
    }

    private function seed(string $salt, int $length): string
    {
        return substr(hash('sha512', 'tfsign-qqmusic:' . $salt . ':' . $this->uin), 0, $length);
    }
}
