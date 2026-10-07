<?php

namespace plugin\qqmusic;

use app\exception\ApiException;
use app\sign\contract\CredentialRefreshAwareInterface;
use app\sign\contract\SignPluginInterface;
use app\sign\dto\AccountProfile;
use app\sign\dto\HealthResult;
use app\sign\dto\PluginMetadata;
use app\sign\dto\SignContext;
use app\sign\dto\SignRecord;
use app\sign\dto\SignResult;

final class QqMusicPlugin implements SignPluginInterface, CredentialRefreshAwareInterface
{
    private const TASK_NAMES = ['8' => '头像挂件', '9' => '主题装扮', '30' => '逛听书频道', '20' => '收听节目'];
    private const AWARD_OK = 0;
    private const AWARD_COOLDOWN = 20020;
    private const AWARD_NOT_COUNTED = 30005;
    private const AWARD_ALREADY = 30009;

    private ?array $refreshedCredentials = null;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    public function __construct(?callable $sleep = null, private CurlHttp $http = new CurlHttp())
    {
        $this->sleep = $sleep !== null ? \Closure::fromCallable($sleep) : static fn(int $seconds) => sleep($seconds);
    }

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            'qqmusic',
            'QQ音乐',
            '1.0.0',
            'QQ音乐会员成长值每日任务：签到、头像挂件、主题装扮、逛听书与收听节目',
            ['cookie'],
            'ready',
        );
    }

    public function credentialRules(): array
    {
        return [
            'uin' => ['required', 'string'],
            'qm_keyst' => ['required', 'string'],
            'openid' => ['string'],
            'access_token' => ['string'],
        ];
    }

    public function validateAccount(array $credentials): AccountProfile
    {
        $this->refreshedCredentials = null;
        [$uin, $key, $openid, $accessToken] = self::credentials($credentials);
        $client = new QqMusicClient($uin, $key, $this->http);
        $refreshed = $client->refresh($openid, $accessToken);
        // GetState 对未登录请求也返回默认状态，只能用需要登录的只读接口校验。
        $client->call('music.lvz.MuFest13TaskSvr', 'EveryDaySignLvzScore', ['Cmd' => 'qry']);

        $normalized = [
            'uin' => $uin,
            'qm_keyst' => $client->musicKey(),
            'openid' => $refreshed['openid'] ?? $openid,
            'access_token' => $refreshed['access_token'] ?? $accessToken,
        ];
        $original = [];
        foreach ($normalized as $field => $_) {
            $original[$field] = is_scalar($credentials[$field] ?? null) ? (string)$credentials[$field] : '';
        }
        if ($refreshed !== null || $original !== $normalized) {
            $this->refreshedCredentials = $normalized;
        }
        return new AccountProfile($uin, 'QQ音乐 ' . $uin);
    }

    public function refreshedCredentials(): ?array
    {
        return $this->refreshedCredentials;
    }

    public function supportedActions(): array
    {
        return ['daily_tasks', 'listen_program'];
    }

    public function execute(SignContext $context): SignResult
    {
        [$uin, $key, $openid, $accessToken] = self::credentials($context->credentials);
        $client = new QqMusicClient($uin, $key, $this->http);
        $records = match ($context->action) {
            'daily_tasks' => $this->dailyTasks($client, $context),
            'listen_program' => $this->listenProgram($client, $context, $openid, $accessToken),
            default => throw new ApiException('PLUGIN_ACTION_UNSUPPORTED', "QQ 音乐不支持动作 {$context->action}", 422),
        };
        return self::result($records);
    }

    public function healthCheck(): HealthResult
    {
        return new HealthResult(true, 'QQ 音乐插件可用');
    }

    /** @return list<SignRecord> */
    private function dailyTasks(QqMusicClient $client, SignContext $context): array
    {
        $records = [];
        $state = $this->state($client);
        $records[] = $this->emit($context, $this->dailySign($client, $context->action));

        $pendingAward = [];
        foreach (['8', '9', '30'] as $taskId) {
            $taskState = $state[$taskId] ?? null;
            if ($taskState === 'Y') {
                $records[] = $this->emit($context, $this->taskRecord($context->action, $taskId, 'already_done', 'TASK_DONE', '今日已完成'));
                continue;
            }
            if ($taskState !== 'N' && $taskState !== 'W') {
                $records[] = $this->emit($context, $this->taskRecord($context->action, $taskId, 'skipped', 'TASK_UNAVAILABLE', '当前账号没有该任务'));
                continue;
            }
            if ($taskState === 'N') {
                $skipReason = $this->performTask($client, $taskId);
                if ($skipReason !== null) {
                    $records[] = $this->emit($context, $this->taskRecord($context->action, $taskId, 'skipped', 'TASK_NO_TARGET', $skipReason));
                    continue;
                }
            }
            $pendingAward[] = $taskId;
        }

        foreach ($pendingAward as $taskId) {
            $records[] = $this->emit($context, $this->issueAward($client, $context->action, $taskId, false));
        }
        return $records;
    }

    /** @return list<SignRecord> */
    private function listenProgram(QqMusicClient $client, SignContext $context, string $openid, string $accessToken): array
    {
        if (($context->settings['allow_fake_play'] ?? false) !== true) {
            return [$this->emit($context, $this->taskRecord(
                $context->action,
                '20',
                'skipped',
                'FAKE_PLAY_DISABLED',
                '未开启「允许收听节目任务」，已跳过',
            ))];
        }
        $state = $this->state($client);
        if (($state['20'] ?? null) === 'Y') {
            return [$this->emit($context, $this->taskRecord($context->action, '20', 'already_done', 'TASK_DONE', '今日已完成'))];
        }

        $client->call('music.lvz.LevelConfigSvr', 'StartTask', ['ID' => '20']);
        $tmeUid = trim((string)($context->settings['tme_uid'] ?? ''));
        $reporter = new QqMusicPlayReporter(
            $client->uin(),
            $client->musicKey(),
            ctype_digit($tmeUid) ? $tmeUid : '',
            $openid,
            $accessToken,
            $this->http,
        );
        $report = $reporter->report();

        if (($this->state($client)['20'] ?? null) === 'W') {
            $client->call('music.lvz.LevelConfigSvr', 'StartTask', ['ID' => '20']);
        }
        $record = $this->issueAward($client, $context->action, '20', true);
        $record = new SignRecord(
            key: $record->key,
            action: $record->action,
            status: $record->status,
            targetType: $record->targetType,
            targetId: $record->targetId,
            targetName: $record->targetName,
            code: $record->code,
            message: $record->message,
            rewards: $record->rewards,
            metrics: ['songid' => $report['songid'], 'listened_seconds' => 160],
        );
        return [$this->emit($context, $record)];
    }

    private function dailySign(QqMusicClient $client, string $action): SignRecord
    {
        $query = $client->call('music.lvz.MuFest13TaskSvr', 'EveryDaySignLvzScore', ['Cmd' => 'qry']);
        if ((int)($query['data']['Ret'] ?? -1) === 20019) {
            return $this->signRecord($action, 'already_done', 'SIGN_DONE', '今日已签到');
        }
        $data = $client->call('music.lvz.MuFest13TaskSvr', 'EveryDaySignLvzScore', ['Cmd' => 'get'])['data'] ?? [];
        $ret = (int)($data['Ret'] ?? -1);
        if ($ret === 0) {
            $growth = max(0, (int)($data['Total'] ?? 0));
            return $this->signRecord($action, 'succeeded', 'SIGN_OK', $growth > 0 ? "签到成功，成长值 +{$growth}" : '签到成功', $growth);
        }
        if ($ret === 20019) {
            return $this->signRecord($action, 'already_done', 'SIGN_DONE', '今日已签到');
        }
        $message = trim((string)($data['Msg'] ?? ''));
        return $this->signRecord($action, 'failed', 'SIGN_FAILED', "签到失败（Ret={$ret}）" . ($message !== '' ? "：{$message}" : ''));
    }

    /** 执行任务动作；返回非 null 表示无可操作对象需跳过。 */
    private function performTask(QqMusicClient $client, string $taskId): ?string
    {
        if ($taskId === '8') {
            $pendantId = (int)($client->call('music.vip.PendantUserSvr', 'QueryUserPendant')['data']['pendant']['id'] ?? 0);
            if ($pendantId <= 0) {
                return '没有可佩戴的头像挂件';
            }
            $result = $client->call('music.vip.PendantUserSvr', 'UsePendant', ['id' => $pendantId]);
            if ((int)($result['data']['retCode'] ?? 0) === 42502) {
                $client->call('music.vip.PendantUserSvr', 'CancelPendant', ['id' => $pendantId]);
                $client->call('music.vip.PendantUserSvr', 'UsePendant', ['id' => $pendantId]);
            }
            return null;
        }
        if ($taskId === '9') {
            $comm = $client->cosmeticComm();
            $items = $client->call('music.cosmeticcgi.UserCosmeticCgi', 'GetUserCosmetic', ['category' => 'subject'], $comm)['data']['cosmetics'] ?? [];
            $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
            $current = null;
            foreach ($items as $item) {
                if ((int)($item['state'] ?? 0) === 1) {
                    $current = $item;
                    break;
                }
            }
            $current ??= $items[0] ?? null;
            if ($current === null || !isset($current['id'])) {
                return '没有可使用的主题装扮';
            }
            $client->call('music.cosmeticcgi.UserCosmeticCgi', 'UseCosmetic', [
                'category' => 'subject',
                'itemID' => $current['id'],
                'manufacture' => 'vivo',
            ], $comm);
            return null;
        }
        $client->call('music.lvz.LevelConfigSvr', 'RecordBehavior', ['ID' => $taskId]);
        return null;
    }

    /**
     * 20020 为发放冷却，等 3 秒重领一次；30005 为行为尚未计次，只对收听节目等 60 秒重领一次。
     * 不连续重试，避免触发写接口 10006 锁。
     */
    private function issueAward(QqMusicClient $client, string $action, string $taskId, bool $waitForCount): SignRecord
    {
        $result = $client->call('music.lvz.LevelConfigSvr', 'IssueAward', ['ID' => $taskId]);
        $code = (int)($result['code'] ?? -1);
        if ($code === self::AWARD_COOLDOWN || ($waitForCount && $code === self::AWARD_NOT_COUNTED)) {
            ($this->sleep)($code === self::AWARD_COOLDOWN ? 3 : 60);
            $result = $client->call('music.lvz.LevelConfigSvr', 'IssueAward', ['ID' => $taskId]);
            $code = (int)($result['code'] ?? -1);
        }
        if ($code === self::AWARD_OK) {
            $growth = max(0, (int)($result['data']['DrawScores'] ?? 0));
            ($this->sleep)(3);
            return $this->taskRecord($action, $taskId, 'succeeded', 'AWARD_OK', $growth > 0 ? "领取成功，成长值 +{$growth}" : '领取成功', $growth);
        }
        if ($code === self::AWARD_ALREADY) {
            return $this->taskRecord($action, $taskId, 'already_done', 'AWARD_CLOSED', '今日已领取或任务单已结束');
        }
        $message = match ($code) {
            self::AWARD_NOT_COUNTED => '行为尚未计次，稍后再执行一次',
            self::AWARD_COOLDOWN => '领取冷却中，稍后再执行一次',
            10006 => '领取接口被临时锁定，请明天再试',
            30012 => '账号不在活动白名单内',
            default => "领取失败（code={$code}）",
        };
        return $this->taskRecord($action, $taskId, 'failed', 'AWARD_' . $code, $message);
    }

    /** @return array<string, string> */
    private function state(QqMusicClient $client): array
    {
        $node = $client->call('music.lvz.LevelConfigSvr', 'GetState');
        $code = (int)($node['code'] ?? -1);
        if ($code !== 0) {
            throw new ApiException('QQMUSIC_STATE_FAILED', "读取 QQ 音乐任务状态失败（code={$code}）", 502);
        }
        $map = $node['data']['StateMap'] ?? [];
        return is_array($map) ? array_map('strval', array_filter($map, 'is_scalar')) : [];
    }

    private function signRecord(string $action, string $status, string $code, string $message, int $growth = 0): SignRecord
    {
        return new SignRecord(
            key: 'task:sign',
            action: $action,
            status: $status,
            targetType: 'task',
            targetId: 'sign',
            targetName: '每日签到',
            code: $code,
            message: $message,
            rewards: ['growth' => $growth],
        );
    }

    private function taskRecord(string $action, string $taskId, string $status, string $code, string $message, int $growth = 0): SignRecord
    {
        return new SignRecord(
            key: 'task:' . $taskId,
            action: $action,
            status: $status,
            targetType: 'task',
            targetId: $taskId,
            targetName: self::TASK_NAMES[$taskId] ?? "任务 {$taskId}",
            code: $code,
            message: $message,
            rewards: ['growth' => $growth],
        );
    }

    private function emit(SignContext $context, SignRecord $record): SignRecord
    {
        $context->report($record);
        return $record;
    }

    /** @param list<SignRecord> $records */
    private static function result(array $records): SignResult
    {
        $failed = 0;
        $growth = 0;
        foreach ($records as $record) {
            if (!in_array($record->status, ['succeeded', 'already_done', 'skipped'], true)) {
                $failed++;
            }
            $growth += (int)($record->rewards['growth'] ?? 0);
        }
        $status = match (true) {
            $failed === 0 => 'succeeded',
            $failed === count($records) => 'failed',
            default => 'partial',
        };
        $message = $growth > 0 ? "本次新增成长值 {$growth}" : '本次没有新增成长值';
        if ($failed > 0) {
            $message .= "，{$failed} 项未完成";
        }
        return new SignResult($status, $message, $records, ['growth' => $growth]);
    }

    /** @return array{string, string, string, string} */
    private static function credentials(array $credentials): array
    {
        $uin = ltrim(ltrim(trim((string)($credentials['uin'] ?? '')), 'oO'), '0');
        if (!preg_match('/^[1-9][0-9]{4,11}$/D', $uin)) {
            throw new ApiException('QQMUSIC_UIN_INVALID', '请填写正确的 QQ 号', 422);
        }
        $key = trim((string)($credentials['qm_keyst'] ?? ''));
        if ($key === '' || strlen($key) > 4096 || preg_match('/[\s;]/', $key)) {
            throw new ApiException('QQMUSIC_KEY_INVALID', '请填写正确的 qm_keyst', 422);
        }
        return [
            $uin,
            $key,
            trim((string)($credentials['openid'] ?? '')),
            trim((string)($credentials['access_token'] ?? '')),
        ];
    }
}
