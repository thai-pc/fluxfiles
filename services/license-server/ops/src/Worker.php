<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Ops;

use FluxFiles\LicenseServer\Client\ApiClient;
use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;

require_once __DIR__ . '/../../client/ApiClient.php';
require_once __DIR__ . '/Telegram.php';
require_once __DIR__ . '/Health.php';

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

    public function run(): void
    {
        $this->deadline = microtime(true) + 53;
        $old = umask(0077);
        try {
            if (!is_dir($this->dir)) {
                mkdir($this->dir, 0700, true);
            }
            $lock = fopen($this->dir . '/worker.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX|LOCK_NB)) {
                return;
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
                $this->health();
                if ($this->telegram->enabled() && $this->remaining() > 22) {
                    $this->outbox();
                }
                if ($this->telegram->enabled() && Config::get('FLUXFILES_TELEGRAM_COMMANDS') === '1' && $this->remaining() > 22) {
                    $this->commands();
                }
            } finally {
                $this->save();
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } finally {
            umask($old);
        }
    }

    private function request(string $method, string $path, array $data = []): array
    {
        return (new ApiClient(Config::secret('FLUXFILES_LICENSE_OPS_TOKEN'), 'ops', null, max(1, min(20, $this->remaining()))))->request($method, $path, $data);
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
        return $this->sent < 20 && count($this->state['sends'] ?? []) < 60 && $this->remaining() > 22;
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
        return $this->telegram->send($text, min(10, $this->remaining()));
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
                    $text = $item['text'] . ($item['suppressed'] > 0?' (+' . $item['suppressed'] . ' similar)': '');
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
        $name = $this->state['bot_name'] ?? '';
        if ($name === '') {
            $me = $this->telegram->request('getMe', [], min(10, $this->remaining()));
            if (!$me['ok']) {
                return;
            }
            $name = (string) ($me['result']['username'] ?? '');
            $this->state['bot_name'] = $name;
        }
        $updates = $this->telegram->request('getUpdates', ['offset' => (int) ($this->state['offset'] ?? 0) + 1, 'timeout' => 0, 'allowed_updates' => ['message']], min(10, $this->remaining()));
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
            if (!is_array($targets) || count($targets) > 20) {
                throw new \RuntimeException('invalid health config');
            }
            foreach ($targets as $target) {
                if ($this->remaining() < 32) {
                    break;
                }
                $r = (new Health())->probe($target, min(5, $this->remaining() - 22));
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
                    $this->alert('disk.low', $level, 'disk:' . hash('sha256', $path) . ':' . $level, 'Disk space low · ' . (int) round($free / $total * 100) . '%', 21600);
                }
            }
            if ($this->remaining() > 22) {
                $backup = $this->request('GET', '/admin/ops/backup-status');
                if (!($backup['ok'] ?? false) || (int) ($backup['at'] ?? 0) < time() - 26 * 3600) {
                    $this->alert('backup.stale', 'crit', 'backup', 'Backup missing or older than 26 hours', 86400);
                }
            }
            $this->state['health_at'] = time();
        }
        if ($this->remaining() > 21) {
            $this->request('POST', '/admin/ops/health', ['results' => $results, 'telegram' => $this->telegram->enabled(), 'fingerprint' => $this->telegram->fingerprint()]);
        }
        $this->save();
    }
}
