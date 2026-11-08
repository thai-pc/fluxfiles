<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;
use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;
use FluxFiles\LicenseServer\Client\OpsText;

require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/LicenseMailer.php';
require_once __DIR__ . '/AdminExtensions.php';
require_once __DIR__ . '/SystemChecks.php';

final class AdminApi
{
    public function __construct(
        private LicenseStore $store,
        private string $role,
        private ?\Closure $issuer = null,
    ) {}

    private function rows(string $sql, array $params = []): array
    {
        $s = $this->store->pdo()->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function handle(string $method, string $path, array $b, array $q): array
    {
        try {
            return $this->dispatch($method, $path, $b, $q);
        } catch (\InvalidArgumentException $e) {
            $error = in_array(
                $e->getMessage(),
                [
                    'archive_not_allowed',
                    'invalid_status',
                    'not_found',
                    'preview_changed',
                    'replay_unavailable',
                    'feature_disabled',
                    'export_too_large',
                ],
                true,
            )
                ? $e->getMessage()
                : 'invalid_input';
            return [
                [
                    'not_found' => 404,
                    'preview_changed' => 409,
                    'replay_unavailable' => 409,
                    'feature_disabled' => 409,
                ][$error] ?? 400,
                ['error' => $error],
            ];
        }
    }

    private function dispatch(string $method, string $path, array $b, array $q): array
    {
        $s = $this->store;
        $db = $s->pdo();
        $stats = new Stats($db);
        if (str_starts_with($path, '/admin/ops/')) {
            [$code, $body] = $this->ops($method, $path, $b, $q);
            return [$code, $body + ['lang' => OpsText::language()]];
        }
        $extended = (new AdminExtensions($s, $this->role, $this->issuer))->handle(
            $method,
            $path,
            $b,
            $q,
        );
        if ($extended !== null) {
            return $extended;
        }
        if ($method === 'GET' && $path === '/admin/meta') {
            return [
                200,
                [
                    'plans' => Plans::all(),
                    'plan_map' => json_decode(Config::get('FLUXFILES_POLAR_PLAN_MAP', '{}'), true),
                    'statuses' => ['active', 'superseded', 'revoked', 'refunded'],
                    'tracked_discounts' => $stats->founding(),
                    'capabilities' => [
                        'polar_cancel' => Config::get('FLUXFILES_ADMIN_POLAR_ACTIONS') === '1',
                    ],
                ],
            ];
        }
        if ($method === 'GET' && $path === '/admin/licences') {
            return [200, $s->searchLicences($q)];
        }
        if ($method === 'GET' && $path === '/admin/stats') {
            return [
                200,
                $stats->dashboard() + [
                    'backup' => $s->backupStatus(),
                    'heartbeat' => $s->state('heartbeat'),
                ],
            ];
        }
        if (
            $method === 'GET' &&
            preg_match('~^/admin/stats/(revenue|installs|mail)$~D', $path, $m)
        ) {
            return [200, $stats->{$m[1]}($q)];
        }
        if ($method === 'POST' && $path === '/admin/lookup-key') {
            if (!is_string($b['license_key'] ?? null)) {
                throw new \InvalidArgumentException();
            }
            $r = $s->findByKey($b['license_key']);
            $s->audit('key.lookup', $r['jti'] ?? null, $r ? 'ok' : 'not_found');
            return $r ? [200, ['jti' => $r['jti']]] : [404, ['error' => 'not_found']];
        }
        if ($method === 'POST' && $path === '/admin/licences/archive') {
            $jtis = $b['jtis'] ?? [];
            if (!is_array($jtis)) {
                throw new \InvalidArgumentException();
            }
            return [
                200,
                $s->transaction(function () use ($s, $jtis): array {
                    $n = $s->archive($jtis);
                    foreach ($jtis as $jti) {
                        $s->audit('licence.archive', $jti);
                    }
                    return ['archived' => $n];
                }),
            ];
        }
        if (
            preg_match(
                '~^/admin/licences/([a-f0-9]{24})(?:/(cascade-preview|reveal|resend|flags))?$~D',
                $path,
                $m,
            )
        ) {
            $jti = $m[1];
            $action = $m[2] ?? '';
            $r = $s->findByJti($jti);
            if ($r === null) {
                return [404, ['error' => 'not_found']];
            }
            if ($method === 'GET' && $action === '') {
                return [200, $s->licenceDetail($jti)];
            }
            if ($method === 'GET' && $action === 'cascade-preview') {
                $status = $q['status'] ?? 'revoked';
                if (!in_array($status, ['active', 'refunded', 'revoked'], true)) {
                    throw new \InvalidArgumentException('invalid_status');
                }
                return [200, $s->cascadePreview($jti, $status)];
            }
            if ($method === 'POST' && $action === 'reveal') {
                // Actor headers are caller-supplied, so the token role also owns a shared cap.
                $tokenAllowed = $s->rateHit('key-reveal:role:' . $this->role, 600, 10);
                $actorAllowed = $s->rateHit(
                    'key-reveal:' . hash('sha256', MachineAuth::actor($this->role, $_SERVER)),
                    600,
                    10,
                );
                if (!$tokenAllowed || !$actorAllowed) {
                    $s->audit('key.reveal', $jti, 'rate_limited');
                    $s->enqueueAlert(
                        'key.reveal_burst',
                        'warn',
                        'key-reveal-burst',
                        OpsText::text('Key reveal burst; inspect the audit log'),
                        600,
                    );
                    return [429, ['error' => 'rate_limited']];
                }
                $s->audit(
                    'key.reveal',
                    $jti,
                    'ok',
                    is_string($b['reason'] ?? null) ? $b['reason'] : null,
                );
                return [200, ['license_key' => $r['license_key']]];
            }
            if ($method === 'POST' && $action === 'flags') {
                $s->transaction(function () use ($s, $jti, $b): void {
                    $s->flags($jti, $b);
                    $s->audit('licence.flag', $jti);
                });
                return [200, ['ok' => true]];
            }
            if ($method === 'POST' && $action === 'resend') {
                if (in_array($r['status'], ['revoked', 'refunded'], true)) {
                    return [409, ['error' => 'invalid_status']];
                }
                $ok = $this->mailer($jti)->sendLicense($r);
                if ($ok) {
                    $s->markMailed($jti);
                }
                $s->audit('mail.resend', $jti, $ok ? 'ok' : 'failed');
                return [200, ['mailed' => $ok]];
            }
            return [405, ['error' => 'method_not_allowed']];
        }
        if ($method === 'GET' && $path === '/admin/events') {
            $where = ['id>?'];
            $p = [0];
            if (($q['failures'] ?? '1') !== '0') {
                $where[] =
                    "(http_status>=400 OR outcome IN ('renewal_unmatched','mail_failed','error')) AND resolved_at IS NULL";
            }
            foreach (['source', 'outcome', 'type'] as $k) {
                if (($q[$k] ?? '') !== '') {
                    $where[] = "$k=?";
                    $p[] = (string) $q[$k];
                }
            }
            if (($q['cursor'] ?? '') !== '') {
                $where[] = 'id<?';
                $p[] = (int) $q['cursor'];
            }
            if (($q['from'] ?? '') !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $q['from']);
                if (!$date) {
                    throw new \InvalidArgumentException();
                }
                $where[] = 'received_at>=?';
                $p[] = $date->getTimestamp();
            }
            $rows = $this->rows(
                'SELECT id,source,type,verify,outcome,http_status,order_id,product_id,received_at,last_at,attempts,resolved_at FROM webhook_events WHERE ' .
                    implode(' AND ', $where) .
                    ' ORDER BY id DESC LIMIT 51',
                $p,
            );
            return [200, $this->page($rows)];
        }
        if (preg_match('~^/admin/events/([0-9]+)(?:/(resolve))?$~D', $path, $m)) {
            $id = (int) $m[1];
            $r = $this->rows('SELECT * FROM webhook_events WHERE id=?', [$id])[0] ?? null;
            if ($r === null) {
                return [404, ['error' => 'not_found']];
            }
            if ($method === 'GET' && !isset($m[2])) {
                $r['has_body'] = $r['body'] !== null && (int) $r['body_expires_at'] >= time();
                unset($r['body']);
                return [200, Mask::row($r)];
            }
            if ($method === 'POST' && ($m[2] ?? '') === 'resolve') {
                $s->transaction(function () use ($db, $s, $id): void {
                    $db->prepare(
                        'UPDATE webhook_events SET body=NULL,body_expires_at=NULL,resolved_at=?,resolved_by=? WHERE id=?',
                    )->execute([time(), MachineAuth::actor($this->role, $_SERVER), $id]);
                    $s->resolveEventItems($id);
                    $s->audit('webhook.resolve', (string) $id);
                });
                return [200, ['ok' => true]];
            }
            return [405, ['error' => 'method_not_allowed']];
        }
        if ($path === '/admin/audit') {
            if ($method === 'POST') {
                if ($this->role !== 'ui') {
                    return [403, ['error' => 'forbidden_scope']];
                }
                if (!in_array($b['action'] ?? '', ['login.ok', 'login.fail', 'logout'], true)) {
                    throw new \InvalidArgumentException();
                }
                $s->audit($b['action'], null, $b['action'] === 'login.fail' ? 'failed' : 'ok');
                return [200, ['ok' => true]];
            }
            if ($method === 'GET') {
                $where = ['1=1'];
                $p = [];
                foreach (['actor', 'action', 'target'] as $k) {
                    if (($q[$k] ?? '') !== '') {
                        $where[] = "$k=?";
                        $p[] = (string) $q[$k];
                    }
                }
                if (($q['key_only'] ?? '') === '1') {
                    $where[] = "action LIKE 'key.%'";
                }
                if (($q['cursor'] ?? '') !== '') {
                    $where[] = 'id<?';
                    $p[] = (int) $q['cursor'];
                }
                if (($q['from'] ?? '') !== '') {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $q['from']);
                    if (!$date) {
                        throw new \InvalidArgumentException();
                    }
                    $where[] = 'at>=?';
                    $p[] = $date->getTimestamp();
                }
                return [
                    200,
                    $this->page(
                        $this->rows(
                            'SELECT * FROM admin_audit WHERE ' .
                                implode(' AND ', $where) .
                                ' ORDER BY id DESC LIMIT 51',
                            $p,
                        ),
                    ),
                ];
            }
        }
        if ($method === 'POST' && $path === '/admin/test-telegram') {
            $s->enqueueAlert(
                'test',
                'crit',
                'telegram-test:' . intdiv(time(), 60),
                OpsText::text('Admin test alert'),
                60,
            );
            $s->audit('telegram.test');
            return [200, ['queued' => true]];
        }
        if ($method === 'POST' && $path === '/admin/test-mail') {
            $ok = $this->mailer()->sendTest();
            $s->audit('mail.test', null, $ok ? 'ok' : 'failed');
            return [200, ['mailed' => $ok]];
        }
        if ($method === 'GET' && $path === '/admin/system') {
            $counts = [];
            foreach (
                [
                    'licenses',
                    'orders',
                    'mail_log',
                    'webhook_events',
                    'admin_audit',
                    'install_pings',
                    'ops_outbox',
                ]
                as $table
            ) {
                $counts[$table] = (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            }
            $tokens = MachineAuth::tokens();
            $presence = [];
            foreach ($tokens as $k => $v) {
                $presence[$k] = $v !== '';
            }
            $map = json_decode(Config::get('FLUXFILES_POLAR_PLAN_MAP', '{}'), true) ?: [];
            $mapped = [];
            foreach ($map as $product => $plan) {
                $mapped[] = [
                    'product' => $product,
                    'plan' => $plan,
                    'ok' => Plans::get((string) $plan) !== null,
                ];
            }
            $queue = $this->rows(
                'SELECT MAX(sent_at) last_sent,SUM(sent_at IS NULL AND dropped_at IS NULL) queue_depth FROM ops_outbox',
            )[0];
            $health = (new Analytics($s))->health();
            return [
                200,
                [
                    'site_checks' => SystemChecks::sites(),
                    'health' => $health,
                    'signing' => $this->signingStatus(),
                    'build' => $this->build(),
                    'tokens' => $presence,
                    'tokens_distinct' => true,
                    'polar_webhook' => Config::secret('FLUXFILES_POLAR_WEBHOOK_SECRET') !== '',
                    'resend_webhook' => Config::secret('FLUXFILES_RESEND_WEBHOOK_SECRET') !== '',
                    'mail_tls' => $this->mailTls(),
                    'mail_transport' => Config::get('FLUXFILES_MAIL_TRANSPORT', 'log'),
                    'mail_from' => Mask::email(
                        Config::get('FLUXFILES_MAIL_FROM', 'licenses@localhost'),
                    ),
                    'plan_map' => $mapped,
                    'unmapped_products' => $this->rows(
                        "SELECT DISTINCT product_id FROM webhook_events WHERE outcome='unmapped_product' AND resolved_at IS NULL",
                    ),
                    'db' => [
                        'rows' => $counts,
                        'version' => (int) $db->query('PRAGMA user_version')->fetchColumn(),
                        'bytes' =>
                            (int) $db->query('PRAGMA page_count')->fetchColumn() *
                            (int) $db->query('PRAGMA page_size')->fetchColumn(),
                        'journal' => $db->query('PRAGMA journal_mode')->fetchColumn(),
                    ],
                    'telegram' => $queue + [
                        'last_error' =>
                            $this->rows(
                                'SELECT last_error FROM ops_outbox WHERE last_error IS NOT NULL ORDER BY id DESC LIMIT 1',
                            )[0]['last_error'] ?? null,
                    ],
                    'heartbeat' => $s->state('heartbeat'),
                    'backup' => $s->backupStatus(),
                ],
            ];
        }
        return [404, ['error' => 'not_found']];
    }

    private function signingStatus(): array
    {
        $expected = Config::get('FLUXFILES_LICENSE_K1_PUB');
        try {
            $public = (new LicenseSigner())->publicKey();
            return [
                'configured' => true,
                'fingerprint' => substr(hash('sha256', base64_decode($public, true)), 0, 10),
                'expected_configured' => $expected !== '',
                'matches' => $expected !== '' && hash_equals($public, trim($expected)),
            ];
        } catch (\Throwable $e) {
            return [
                'configured' => false,
                'expected_configured' => $expected !== '',
                'matches' => false,
            ];
        }
    }

    private function build(): ?string
    {
        $source = is_file(__DIR__ . '/SOURCE')
            ? trim((string) file_get_contents(__DIR__ . '/SOURCE', false, null, 0, 200))
            : '';
        return preg_match('/^[A-Za-z0-9_. -]{1,100}$/D', $source) ? $source : null;
    }

    private function mailTls(): ?bool
    {
        if (Config::get('FLUXFILES_MAIL_TRANSPORT', 'log') !== 'resend') {
            return null;
        }
        $base = Config::get('FLUXFILES_RESEND_API_BASE', 'https://api.resend.com');
        if ($base !== 'https://api.resend.com') {
            Config::loopback($base);
            return null;
        }
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => 'api.resend.com',
            ],
        ]);
        $socket = @stream_socket_client(
            'ssl://api.resend.com:443',
            $errno,
            $error,
            2,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!$socket) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private function page(array $rows): array
    {
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        return [
            'rows' => array_map([Mask::class, 'row'], $rows),
            'next_cursor' => $more ? (string) end($rows)['id'] : null,
        ];
    }

    public function mailer(?string $jti = null): LicenseMailer
    {
        return new LicenseMailer('', '', '', function (
            string $kind,
            string $to,
            bool $ok,
            ?string $error,
            ?string $providerId,
        ) use ($jti): void {
            $this->store->logMail($kind, $to, $ok, $error, $providerId, $jti);
        });
    }

    private function ops(string $method, string $path, array $b, array $q): array
    {
        if ($this->role !== 'ops') {
            return [403, ['error' => 'forbidden_scope']];
        }
        $s = $this->store;
        $db = $s->pdo();
        if ($method === 'POST' && $path === '/admin/ops/ingest') {
            if (
                !is_string($b['type'] ?? null) ||
                !is_string($b['day'] ?? null) ||
                !is_array($b['rows'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $s->ingest($b['type'], $b['day'], $b['rows']);
            return [200, ['ok' => true]];
        }
        if ($method === 'POST' && $path === '/admin/ops/report/weekly') {
            return [200, (new Communications($s, 'ops'))->weekly((string) ($b['week'] ?? ''))];
        }
        if ($method === 'POST' && $path === '/admin/ops/mail-jobs') {
            return [200, (new Communications($s, 'ops'))->jobs((int) ($b['limit'] ?? 10))];
        }
        if ($method === 'POST' && $path === '/admin/ops/outbox/claim') {
            if (($b['channel'] ?? 'telegram') !== 'telegram') {
                throw new \InvalidArgumentException();
            }
            return [200, ['items' => $s->claimOutbox((int) ($b['limit'] ?? 20))]];
        }
        if ($method === 'POST' && $path === '/admin/ops/outbox/ack') {
            $s->ackOutbox(
                (int) ($b['id'] ?? 0),
                ($b['ok'] ?? false) === true,
                (string) ($b['error'] ?? ''),
                isset($b['retry_after']) ? (int) $b['retry_after'] : null,
            );
            return [200, ['ok' => true]];
        }
        if ($method === 'POST' && $path === '/admin/ops/alert') {
            if (
                !in_array($b['severity'] ?? '', ['info', 'warn', 'crit'], true) ||
                !is_string($b['kind'] ?? null) ||
                !is_string($b['dedup_key'] ?? null) ||
                !is_string($b['text'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $s->enqueueAlert(
                $b['kind'],
                $b['severity'],
                $b['dedup_key'],
                $b['text'],
                max(60, min(86400, (int) ($b['window'] ?? 3600))),
            );
            return [200, ['queued' => true]];
        }
        if ($method === 'POST' && $path === '/admin/ops/health') {
            $results = $b['results'] ?? [];
            if (!is_array($results) || count($results) > 20) {
                throw new \InvalidArgumentException();
            }
            foreach ($results as $r) {
                if (
                    !is_array($r) ||
                    !preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', (string) ($r['target'] ?? '')) ||
                    !is_bool($r['ok'] ?? null)
                ) {
                    throw new \InvalidArgumentException();
                }
                Mask::safe((string) ($r['detail'] ?? ''), true);
                $db->prepare(
                    'INSERT OR REPLACE INTO health_checks(target,at,ok,ms,tls_days,detail) VALUES(?,?,?,?,?,?)',
                )->execute([
                    $r['target'],
                    time(),
                    (int) $r['ok'],
                    max(0, min(60000, (int) ($r['ms'] ?? 0))),
                    isset($r['tls_days']) ? (int) $r['tls_days'] : null,
                    mb_substr(Mask::clean((string) ($r['detail'] ?? '')), 0, 300),
                ]);
            }
            $s->state('heartbeat', [
                'at' => time(),
                'telegram' => ($b['telegram'] ?? false) === true,
                'fingerprint' => preg_match('/^[a-f0-9]{10}$/D', (string) ($b['fingerprint'] ?? ''))
                    ? $b['fingerprint']
                    : null,
                'stage_errors' => array_values(
                    array_filter(
                        is_array($b['stage_errors'] ?? null) ? $b['stage_errors'] : [],
                        static fn($v): bool => is_string($v) &&
                            in_array(
                                $v,
                                [
                                    'heartbeat',
                                    'outbox',
                                    'health',
                                    'mail_jobs',
                                    'inbox',
                                    'weekly',
                                    'counters',
                                    'commands',
                                ],
                                true,
                            ),
                    ),
                ),
            ]);
            if (($s->state('pruned')['day'] ?? '') !== gmdate('Y-m-d')) {
                $s->prune();
                $s->sweepOpsItems();
                $s->state('pruned', ['day' => gmdate('Y-m-d')]);
            }
            return [200, ['ok' => true]];
        }
        if ($method === 'GET' && $path === '/admin/ops/backup-status') {
            return [200, $s->backupStatus()];
        }
        if ($method === 'GET' && $path === '/admin/ops/summary') {
            $stats = new Stats($db);
            $d = $stats->dashboard();
            $text = [];
            if (($q['scope'] ?? 'stats') === 'today') {
                $today = (new \DateTimeImmutable(
                    'today',
                    new \DateTimeZone(Config::get('FLUXFILES_ADMIN_TZ', 'Asia/Ho_Chi_Minh')),
                ))->getTimestamp();
                foreach (
                    [
                        'orders' => ['orders', 'paid_at'],
                        'refunds' => ['orders', 'refunded_at'],
                        'failures' => ['webhook_events', 'last_at'],
                        'alerts' => ['ops_outbox', 'created_at'],
                    ]
                    as $label => [$table, $col]
                ) {
                    $n = $this->rows(
                        "SELECT COUNT(*) n FROM $table WHERE $col>=?" .
                            ($label === 'failures' ? ' AND http_status>=400' : ''),
                        [$today],
                    )[0]['n'];
                    $text[] = OpsText::text($label . ': {count}', ['count' => $n]);
                }
            } else {
                foreach ($d['revenue']['currencies'] as $r) {
                    $text[] = OpsText::text('{currency} net: {net} minor units; MRR: {mrr}', [
                        'currency' => $r['currency'],
                        'net' => $r['net_month'],
                        'mrr' => $r['mrr'],
                    ]);
                }
                $text[] = OpsText::text('Active installs: {count}', [
                    'count' => $d['installs']['active_30d'],
                ]);
            }
            return [
                200,
                [
                    'text' => Mask::safe(implode("\n", $text), true),
                ],
            ];
        }
        return [404, ['error' => 'not_found']];
    }
}
