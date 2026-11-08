<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Ops;

use FluxFiles\LicenseServer\Client\ApiClient;
use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;

require_once __DIR__ . '/../../client/ApiClient.php';
require_once __DIR__ . '/Telegram.php';
require_once __DIR__ . '/../../client/OpsText.php';
require_once __DIR__ . '/Health.php';
require_once __DIR__ . '/Counters.php';
require_once __DIR__ . '/Inbox.php';

final class Worker
{
    private Telegram $telegram;
    private array $state = [];
    private float $deadline;
    private float $lastSend = 0;
    private int $sent = 0;
    private string $dir;

    public function __construct()
    {
        $this->telegram = new Telegram();
        $this->dir = Config::get('FLUXFILES_OPS_STATE_DIR', '/var/lib/fluxfiles-license' . (Config::sandbox()?'-sbx': '') . '-ops');
    }

    public function run(): array
    {
        $this->deadline = microtime(true) + 53;
        $old = umask(0077);
        try {
            if (!is_dir($this->dir)) {
                mkdir($this->dir, 0700, true);
            }
            $lock = fopen($this->dir . '/worker.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX|LOCK_NB)) {
                if ($lock) { fclose($lock); }
                return [];
            }
            try {
                $decoded = json_decode((string) @file_get_contents($this->dir . '/state.json'), true);
                $this->state = is_array($decoded)?$decoded: [];
                // A state directory cannot silently switch instances.
                $instance = Config::get('FLUXFILES_LICENSE_INSTANCE', 'production');
                if (isset($this->state['instance']) && $this->state['instance'] !== $instance) {
                    throw new \RuntimeException('instance state mismatch');
                }
                $this->state['instance'] = $instance;
                $this->state['sends'] = array_values(array_filter($this->state['sends'] ?? [], static fn($t): bool => is_int($t) && $t > time() - 3600));
                $this->state['stage_errors'] = [];
                $this->stage('heartbeat', fn() => $this->heartbeat());
                // Delivery gets the first budget after liveness, independently of probes/jobs.
                if ($this->telegram->enabled()) { $this->stage('outbox', fn() => $this->outbox()); }
                foreach (['health', 'mail_jobs', 'inbox', 'weekly', 'counters', 'commands'] as $stage) {
                    if ($this->remaining() <= ($stage === 'counters' ? 27 : 22)) { break; }
                    $this->stage($stage, function () use ($stage): void {
                        if ($stage === 'mail_jobs') { $this->request('POST','/admin/ops/mail-jobs',['limit'=>10]); }
                        elseif ($stage === 'commands') {
                            if ($this->telegram->enabled() && !Config::sandbox() && Config::get('FLUXFILES_TELEGRAM_COMMANDS') === '1' && $this->budget()) { $this->commands(); }
                        } else { $this->{$stage}(); }
                    });
                }
                $this->stage('heartbeat', fn() => $this->heartbeat());
                return array_keys($this->state['stage_errors']);
            } finally {
                $this->save();
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } finally {
            umask($old);
        }
    }

    private function stage(string $name, \Closure $run): void
    {
        try { $run(); }
        catch (\Throwable $e) { $this->state['stage_errors'][$name] = time(); }
    }

    private function heartbeat(array $results = []): void
    {
        $this->request('POST','/admin/ops/health',[
            'results'=>$results,'telegram'=>$this->telegram->enabled(),
            'fingerprint'=>$this->telegram->fingerprint(),
            'stage_errors'=>array_keys($this->state['stage_errors'] ?? []),
        ]);
    }

    private function telegramRequest(string $method, array $body): array
    {
        if ((int)($this->state['telegram_blocked_until'] ?? 0) > time()) { return ['ok'=>false]; }
        $result=$this->telegram->request($method,$body,min(10,$this->remaining()));
        $this->telegramBackoff($result);
        return $result;
    }

    private function telegramBackoff(array $result): void
    {
        if (isset($result['retry_after'])) {
            $this->state['telegram_blocked_until']=time()+(int)$result['retry_after'];
            $this->save();
        }
    }

    private function request(string $method, string $path, array $data = []): array
    {
        return (new ApiClient(Config::secret('FLUXFILES_LICENSE_OPS_TOKEN'), 'ops', null, max(1, min(20, $this->remaining()))))->request($method, $path, $data);
    }

    private function inbox(): void
    {
        Inbox::consume($this->dir.'/inbox',function(array $request):void { $this->request('POST','/admin/ops/ingest',$request); },fn():bool=>$this->remaining()>22);
    }

    private function counters(): void
    {
        $today=gmdate('Y-m-d');$service=new Counters();$targets=$service->targets();
        if (($this->state['counters']['day']??'')!==$today) { $this->state['counters']=['day'=>$today,'done'=>[]]; }
        $results=[];
        foreach ($targets as $target) {
            $key=$target['source'].':'.$target['subject'];if (isset($this->state['counters']['done'][$key])) { continue; }
            if ($this->remaining()<28) { break; }$result=$service->fetch($target,min(5,$this->remaining()-22));
            if ($result['ok']) { $this->request('POST','/admin/ops/ingest',['type'=>'public_counters','day'=>$today,'rows'=>$result['rows']]); }
            $name='counter:'.$target['source'];if (!isset($results[$name]) || !$result['ok']) { $results[$name]=['target'=>$name,'ok'=>$result['ok'],'ms'=>$result['ms'],'detail'=>$result['detail']]; }
            $this->state['counters']['done'][$key]=true;$this->save();
        }
        if ($results!==[] && $this->remaining()>21) { $this->request('POST','/admin/ops/health',['results'=>array_values($results),'telegram'=>$this->telegram->enabled(),'fingerprint'=>$this->telegram->fingerprint()]); }
    }

    private function weekly(): void
    {
        $now=new \DateTimeImmutable('now',new \DateTimeZone(Config::get('FLUXFILES_ADMIN_TZ','Asia/Ho_Chi_Minh')));
        $monday=$now->modify('monday this week')->setTime(8,0);if ($now<$monday) { return; }
        $week=$monday->modify('-1 week')->format('o-\WW');if (($this->state['weekly_report']??'')===$week) { return; }
        $result=$this->request('POST','/admin/ops/report/weekly',['week'=>$week]);if ($result['ok']??false) { $this->state['weekly_report']=$week;$this->save(); }
    }

    private function remaining(): int
    {
        return max(0, (int) floor($this->deadline - microtime(true)));
    }
    private function save(): void
    {
        $tmp = $this->dir . '/state.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($this->state, JSON_UNESCAPED_SLASHES), LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, $this->dir . '/state.json');
    }
    private function budget(): bool
    {
        return (int)($this->state['telegram_blocked_until'] ?? 0) <= time() && $this->sent < 20 && count($this->state['sends'] ?? []) < 60 && $this->remaining() > 22;
    }

    private function send(string $text): array
    {
        Mask::safe($text, true);
        $wait = 1.1 - (microtime(true) - $this->lastSend);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $this->lastSend = microtime(true);
        $this->sent++;
        $this->state['sends'][] = time();
        $this->save();
        $result=$this->telegram->send($text, min(10, $this->remaining()));
        $this->telegramBackoff($result);
        return $result;
    }

    private function outbox(): void
    {
        if (!$this->budget()) {
            return;
        }
        $limit = min(20, 60 - count($this->state['sends']));
        $data = $this->request('POST', '/admin/ops/outbox/claim', ['channel' => 'telegram', 'limit' => $limit]);
        foreach ($data['items'] as $item) {
            if (!$this->budget()) {
                break;
            }
            $ok = false;
            $error = '';
            $retry = null;
            if (Config::sandbox() && $item['severity'] !== 'crit') {
                $error = 'sandbox_filtered';
            } else {
                try {
                    $text = $item['text'] . ($item['suppressed'] > 0?' (+' . $item['suppressed'] . \FluxFiles\LicenseServer\Client\OpsText::text(' similar)'): '');
                    Mask::safe($text, true);
                    $res = $this->send($text);
                    $ok = $res['ok'];
                    $error = $res['error'] ?? '';
                    $retry = $res['retry_after'] ?? null;
                } catch (\InvalidArgumentException $e) {
                    $error = 'masking';
                }
            }
            $this->request('POST', '/admin/ops/outbox/ack', ['id' => $item['id'], 'ok' => $ok, 'error' => $error] + ($retry !== null?['retry_after' => $retry]: []));
            if ($retry !== null) {
                break;
            }
        }
    }

    private function commands(): void
    {
        if (!$this->budget()) { return; }
        $name = $this->state['bot_name'] ?? '';
        if ($name === '') {
            $me = $this->telegramRequest('getMe', []);
            if (!$me['ok']) {
                return;
            }
            $name = (string) ($me['result']['username'] ?? '');
            $this->state['bot_name'] = $name;
        }
        $updates = $this->telegramRequest('getUpdates', ['offset' => (int) ($this->state['offset'] ?? 0) + 1, 'timeout' => 0, 'allowed_updates' => ['message']]);
        if (!$updates['ok'] || !is_array($updates['result'])) {
            return;
        }
        foreach ($updates['result'] as $update) {
            if (!$this->budget()) {
                break;
            }
            $this->state['offset'] = max((int) ($this->state['offset'] ?? 0), (int) ($update['update_id'] ?? 0));
            $this->save();
            $command = $this->telegram->command($update['message'] ?? [], $name);
            if ($command === null) {
                continue;
            }
            $text = $command === 'help'?'/stats · /today · /help': $this->request('GET', '/admin/ops/summary', ['scope' => $command])['text'];
            try {
                $this->send($text);
            } catch (\InvalidArgumentException $e) {
                /* Fail closed on unmasked summary. */
            }
        }
    }

    private function alert(string $kind, string $severity, string $dedup, string $text, int $window = 3600): void
    {
        if ($this->remaining() < 21) {
            return;
        }
        $this->request('POST', '/admin/ops/alert', ['kind' => $kind, 'severity' => $severity, 'dedup_key' => $dedup, 'text' => $text, 'window' => $window]);
    }

    private function health(): void
    {
        $results = [];
        if ((int) ($this->state['health_at'] ?? 0) <= time() - 300) {
            $targets = json_decode(Config::get('FLUXFILES_OPS_HEALTH_TARGETS', '[]'), true);
            if (!is_array($targets) || !array_is_list($targets) || count($targets) > 20) {
                throw new \RuntimeException('invalid health config');
            }
            $done=true;$offset=(int)($this->state['health_offset'] ?? 0);$count=count($targets);
            for ($i=0;$i<$count;$i++) {
                $index=($offset+$i)%$count;$target=$targets[$index];
                if ($this->remaining() < 32) { $done=false;$this->state['health_offset']=$index;break; }
                try {
                    if (!is_array($target)) { throw new \InvalidArgumentException(); }
                    $r = (new Health())->probe($target, min(5, $this->remaining() - 22));
                } catch (\InvalidArgumentException $e) { $this->state['stage_errors']['health']=time();continue; }
                $results[] = $r;
                $name = $r['target'];
                $previous = $this->state['health'][$name] ?? ['failures' => 0, 'down' => false];
                if (!$r['ok']) {
                    $previous['failures']++;
                    if ($previous['failures'] >= 2 && !$previous['down']) {
                        $this->alert('health.down', 'crit', 'down:' . $name, 'Health down · ' . $name);
                        $previous['down'] = true;
                    }
                } else {
                    if ($previous['down']) {
                        $this->alert('health.up', 'info', 'up:' . $name, 'Health recovered · ' . $name);
                    }
                    $previous = ['failures' => 0, 'down' => false];
                }
                $this->state['health'][$name] = $previous;
                if (isset($r['tls_days']) && $r['tls_days'] < 14) {
                    $this->alert('tls.expiring', $r['tls_days'] < 3?'crit': 'warn', 'tls:' . $name, 'TLS expires · ' . $name . ' · ' . $r['tls_days'] . ' days', 86400);
                }
            }
            foreach (explode(',', Config::get('FLUXFILES_OPS_DISK_PATHS', '/')) as $path) {
                if ($this->remaining() < 22) {
                    break;
                }
                $path = trim($path);
                $total = @disk_total_space($path);
                $free = @disk_free_space($path);
                if ($total && $free !== false && $free / $total < .1) {
                    $level = $free / $total < .05?'crit': 'warn';
                    $this->alert('disk.low', $level, 'disk:' . hash('sha256', $path) . ':' . $level, 'Disk space low · ' . mb_substr(Mask::clean($path),0,160) . ' · ' . (int) round($free / $total * 100) . '%', 21600);
                }
            }
            if ($this->remaining() > 22) {
                $backup = $this->request('GET', '/admin/ops/backup-status');
                if (!($backup['ok'] ?? false) || (int) ($backup['at'] ?? 0) < time() - 26 * 3600) {
                    $this->alert('backup.stale', 'crit', 'backup', 'Backup missing or older than 26 hours', 86400);
                }
            }
            if ($done) { $this->state['health_at']=time();$this->state['health_offset']=0; }
        }
        if ($this->remaining() > 21) {
            $this->heartbeat($results);
        }
        $this->save();
    }
}
