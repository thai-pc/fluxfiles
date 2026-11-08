<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;
use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;

require_once __DIR__ . '/Analytics.php';
require_once __DIR__ . '/Csv.php';
require_once __DIR__ . '/Communications.php';

final class AdminExtensions
{
    public function __construct(
        private LicenseStore $store,
        private string $role,
        private ?\Closure $issuer = null,
    ) {}

    private function rows(string $sql, array $params = []): array
    {
        $q = $this->store->pdo()->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function handle(string $method, string $path, array $body, array $query): ?array
    {
        $s = $this->store;
        $db = $s->pdo();
        $analytics = new Analytics($s);
        if (
            $method === 'GET' &&
            preg_match(
                '~^/admin/stats/(customers|versions|downloads|growth|demo|anomalies)$~D',
                $path,
                $m,
            )
        ) {
            return [200, $analytics->stats($m[1], $query)];
        }
        if ($method === 'GET' && $path === '/admin/customers') {
            return [200, $analytics->customers($query)];
        }
        if (preg_match('~^/admin/customers/([a-f0-9]{16})(?:/note)?$~D', $path, $m)) {
            $customer = $analytics->customer($m[1]);
            if (!$customer) {
                return [404, ['error' => 'not_found']];
            }
            if ($method === 'GET' && !str_ends_with($path, '/note')) {
                return [200, $customer];
            }
            if ($method === 'POST' && str_ends_with($path, '/note')) {
                $note = $body['note'] ?? null;
                if (!is_string($note) || mb_strlen($note) > 500) {
                    throw new \InvalidArgumentException();
                }
                Mask::safe($note);
                $s->transaction(function () use ($db, $s, $customer, $note, $m): void {
                    $db->prepare(
                        'INSERT INTO customer_notes(email_lower,note,updated_at,updated_by) VALUES(?,?,?,?) ON CONFLICT(email_lower) DO UPDATE SET note=excluded.note,updated_at=excluded.updated_at,updated_by=excluded.updated_by',
                    )->execute([
                        $customer['email'],
                        $note,
                        time(),
                        MachineAuth::actor($this->role, $_SERVER),
                    ]);
                    $s->audit('customer.note', $m[1]);
                });
                return [200, ['ok' => true]];
            }
            return [405, ['error' => 'method_not_allowed']];
        }
        if ($method === 'GET' && $path === '/admin/activity') {
            $where = ['day>=?', 'day<=?'];
            $params = [
                (string) ($query['from'] ?? gmdate('Y-m-d', time() - 30 * 86400)),
                (string) ($query['to'] ?? gmdate('Y-m-d')),
            ];
            foreach (['kind', 'result', 'jti'] as $field) {
                if (($query[$field] ?? '') !== '') {
                    $where[] = "$field=?";
                    $params[] = (string) $query[$field];
                }
            }
            return [
                200,
                [
                    'rows' => $this->rows(
                        'SELECT * FROM activity_daily WHERE ' .
                            implode(' AND ', $where) .
                            ' ORDER BY day DESC,kind,jti,result LIMIT 500',
                        $params,
                    ),
                ],
            ];
        }
        if ($method === 'GET' && $path === '/admin/mail') {
            $where = ['1=1'];
            $params = [];
            if (($query['failures'] ?? '0') === '1') {
                $where[] = "(ok=0 OR status IN ('bounced','complained'))";
            }
            if (($query['kind'] ?? '') !== '') {
                $where[] = 'kind=?';
                $params[] = (string) $query['kind'];
            }
            if (($query['cursor'] ?? '') !== '') {
                $where[] = 'id<?';
                $params[] = (int) $query['cursor'];
            }
            if (($query['from'] ?? '') !== '') {
                [$from, $to] = (new Stats($db))->range($query);
                $where[] = 'at>=? AND at<?';
                array_push($params, $from->getTimestamp(), $to->getTimestamp());
            }
            return [
                200,
                $this->page(
                    $this->rows(
                        'SELECT * FROM mail_log WHERE ' .
                            implode(' AND ', $where) .
                            ' ORDER BY id DESC LIMIT 51',
                        $params,
                    ),
                ),
            ];
        }
        if ($method === 'POST' && preg_match('~^/admin/mail/([0-9]+)/resend$~D', $path, $m)) {
            $mail =
                $this->rows('SELECT jti,kind FROM mail_log WHERE id=?', [(int) $m[1]])[0] ?? null;
            if (!$mail || !$mail['jti'] || !in_array($mail['kind'], ['licence', 'support'], true)) {
                return [400, ['error' => 'invalid_input']];
            }
            $record = $s->findByJti($mail['jti']);
            if (!$record) {
                return [404, ['error' => 'not_found']];
            }
            if (in_array($record['status'], ['revoked', 'refunded'], true)) {
                return [409, ['error' => 'invalid_status']];
            }
            $ok = (new AdminApi($s, $this->role))->mailer($record['jti'])->sendLicense($record);
            if ($ok) {
                $s->markMailed($record['jti']);
            }
            $s->audit('mail.resend', $record['jti'], $ok ? 'ok' : 'failed');
            return [200, ['mailed' => $ok]];
        }
        if ($method === 'POST' && preg_match('~^/admin/events/([0-9]+)/replay$~D', $path, $m)) {
            $event =
                $this->rows('SELECT * FROM webhook_events WHERE id=?', [(int) $m[1]])[0] ?? null;
            if (!$event) {
                return [404, ['error' => 'not_found']];
            }
            if (
                $event['source'] !== 'polar' ||
                $event['verify'] !== 'ok' ||
                $event['body'] === null ||
                (int) $event['body_expires_at'] < time()
            ) {
                return [409, ['error' => 'replay_unavailable']];
            }
            $raw = @gzuncompress($event['body'], 65536);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($payload)) {
                return [409, ['error' => 'replay_unavailable']];
            }
            require_once __DIR__ . '/Webhooks.php';
            $actor = MachineAuth::actor($this->role, $_SERVER);
            $factory =
                $this->issuer ?? fn(): LicenseIssuer => new LicenseIssuer(new LicenseSigner(), $s);
            try {
                [$status, $out, $outcome] = (new Webhooks($s, $factory))->handlePolarEvent(
                    $payload,
                    $actor,
                );
            } catch (\Throwable $e) {
                $s->setActor($actor);
                $s->audit('webhook.replay', (string) $m[1], 'failed');
                throw $e;
            }
            $s->setActor($actor);
            $s->recordEvent(
                'polar',
                (string) $event['webhook_id'],
                'ok',
                $payload,
                $raw,
                $outcome,
                $status,
                $out['jti'] ?? null,
            );
            $s->audit('webhook.replay', (string) $m[1], $status < 400 ? 'ok' : 'failed');
            if (
                $status < 400 &&
                !in_array($outcome, ['renewal_unmatched', 'mail_failed', 'error'], true)
            ) {
                $s->resolveEventItems((int) $m[1]);
            }
            return [$status, ['outcome' => $outcome, 'jti' => $out['jti'] ?? null]];
        }
        if ($method === 'GET' && $path === '/admin/ops-items') {
            return [
                200,
                [
                    'rows' => $this->rows(
                        'SELECT * FROM ops_items WHERE resolved_at IS NULL ORDER BY at DESC,id DESC LIMIT 100',
                    ),
                ],
            ];
        }
        if ($method === 'POST' && preg_match('~^/admin/ops-items/([0-9]+)/resolve$~D', $path, $m)) {
            $db->prepare('UPDATE ops_items SET resolved_at=?,resolved_by=? WHERE id=?')->execute([
                time(),
                MachineAuth::actor($this->role, $_SERVER),
                (int) $m[1],
            ]);
            $s->audit('ops.resolve', $m[1]);
            return [200, ['ok' => true]];
        }
        if ($method === 'GET' && $path === '/admin/export/orders.csv') {
            return [200, ['csv' => $this->export($query)]];
        }
        if ($method === 'GET' && $path === '/admin/health') {
            return [200, $analytics->health()];
        }
        if ($method === 'POST' && $path === '/admin/backup') {
            $out = $this->backup();
            return [isset($out['error']) ? 429 : 200, $out];
        }
        if ($path === '/admin/advisories') {
            if ($method === 'GET') {
                return [200, ['rows' => $analytics->versions()['advisories']]];
            }
            if ($method === 'POST') {
                $this->advisory($body);
                return [200, ['ok' => true]];
            }
        }
        if (
            $method === 'GET' &&
            preg_match('~^/admin/advisories/([A-Z0-9-]{1,64})/affected$~D', $path, $m)
        ) {
            return [200, $analytics->affected($m[1], ($query['include_unknown'] ?? '0') === '1')];
        }
        if (str_starts_with($path, '/admin/notices')) {
            return (new Communications($s, $this->role))->handle($method, $path, $body, $query);
        }
        if (
            $method === 'POST' &&
            preg_match('~^/admin/subscriptions/([A-Za-z0-9_.:-]{1,100})/cancel$~D', $path, $m)
        ) {
            $out = $this->cancelSubscription($m[1], $body);
            return $out['requested']
                ? [200, $out]
                : [502, ['error' => 'provider_unavailable', 'http_status' => $out['http_status']]];
        }
        return null;
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

    private function export(array $query): string
    {
        [$from, $to] = (new Stats($this->store->pdo()))->range($query);
        $rows = $this->rows(
            "SELECT o.*,l.customer AS customer_name FROM orders o LEFT JOIN licenses l ON l.jti=o.jti WHERE o.gateway!='manual' AND (l.jti IS NULL OR (l.is_test=0 AND l.archived_at IS NULL)) AND o.paid_at>=? AND o.paid_at<? ORDER BY o.paid_at,o.order_id LIMIT 50001",
            [$from->getTimestamp(), $to->getTimestamp()],
        );
        if (count($rows) > 50000) {
            throw new \InvalidArgumentException('export_too_large');
        }
        $output = [
            [
                'paid_at_local',
                'paid_at_utc',
                'order_id',
                'status',
                'plan',
                'product_id',
                'billing_reason',
                'subscription_id',
                'customer_email',
                'customer_name',
                'currency',
                'subtotal',
                'discount',
                'discount_code',
                'net',
                'tax',
                'total',
                'refunded',
                'refunded_tax',
                'refunded_at_local',
                'jti',
            ],
        ];
        $tz = new \DateTimeZone(Config::get('FLUXFILES_ADMIN_TZ', 'Asia/Ho_Chi_Minh'));
        $local = static fn($at): string => $at
            ? (new \DateTimeImmutable('@' . (int) $at))->setTimezone($tz)->format('Y-m-d H:i:s')
            : '';
        foreach ($rows as $r) {
            $money = static fn($n): string => $n === null
                ? ''
                : number_format(
                    (int) $n / (in_array($r['currency'], ['vnd', 'jpy', 'krw'], true) ? 1 : 100),
                    in_array($r['currency'], ['vnd', 'jpy', 'krw'], true) ? 0 : 2,
                    '.',
                    '',
                );
            $output[] = [
                $local($r['paid_at']),
                $r['paid_at'] ? gmdate('c', (int) $r['paid_at']) : '',
                $r['order_id'],
                $r['status'],
                $r['plan'],
                $r['product_id'],
                $r['billing_reason'],
                $r['subscription_id'],
                $r['email_lower'],
                $r['customer_name'],
                $r['currency'],
                $money($r['subtotal_amount']),
                $money($r['discount_amount']),
                $r['discount_code'],
                $money($r['net_amount']),
                $money($r['tax_amount']),
                $money($r['total_amount']),
                $money($r['refunded_amount']),
                $money($r['refunded_tax_amount']),
                $local($r['refunded_at']),
                $r['jti'],
            ];
        }
        $csv = Csv::encode($output, $redactions);
        $this->store->audit('export.orders', null, 'ok', null, [
            'rows' => count($rows),
            'redactions' => $redactions,
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
        ]);
        return $csv;
    }

    private function advisory(array $body): void
    {
        foreach (['id', 'component', 'affected', 'severity', 'title'] as $key) {
            if (!is_string($body[$key] ?? null)) {
                throw new \InvalidArgumentException();
            }
        }
        if (
            !preg_match('/^[A-Z0-9-]{1,64}$/D', $body['id']) ||
            !preg_match('/^[a-z0-9-]{1,64}$/D', $body['component']) ||
            !VersionRange::valid($body['affected']) ||
            !in_array($body['severity'], ['low', 'moderate', 'high', 'critical'], true) ||
            $body['title'] === '' ||
            mb_strlen($body['title']) > 200
        ) {
            throw new \InvalidArgumentException();
        }
        $url = $body['url'] ?? '';
        $fixed = $body['fixed_in'] ?? '';
        if (
            !is_string($url) ||
            ($url !== '' &&
                (parse_url($url, PHP_URL_SCHEME) !== 'https' ||
                    !filter_var($url, FILTER_VALIDATE_URL) ||
                    parse_url($url, PHP_URL_USER) !== null)) ||
            !is_string($fixed) ||
            ($fixed !== '' && !VersionRange::validVersion($fixed))
        ) {
            throw new \InvalidArgumentException();
        }
        Mask::safe($body['title']);
        Mask::safe($url);
        $this->store->transaction(function () use ($body, $url, $fixed): void {
            $this->store
                ->pdo()
                ->prepare(
                    'INSERT INTO advisories(id,component,affected,fixed_in,severity,title,url,created_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET component=excluded.component,affected=excluded.affected,fixed_in=excluded.fixed_in,severity=excluded.severity,title=excluded.title,url=excluded.url',
                )
                ->execute([
                    $body['id'],
                    $body['component'],
                    $body['affected'],
                    $fixed ?: null,
                    $body['severity'],
                    $body['title'],
                    $url ?: null,
                    time(),
                ]);
            $this->store->audit('advisory.edit', $body['id']);
        });
    }

    private function backup(): array
    {
        $s = $this->store;
        if (!$s->rateHit('backup:manual', 3600, 6)) {
            return ['staged' => false, 'error' => 'rate_limited'];
        }
        $dir = Config::get(
            'FLUXFILES_LICENSE_BACKUP_STAGE',
            '/var/lib/fluxfiles-license' . (Config::sandbox() ? '-sbx' : '') . '/backup-stage',
        );
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new \RuntimeException('internal');
        }
        $mask = umask(0077);
        $file = $dir . '/manual-' . time() . '-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $s->pdo()
                ->prepare('VACUUM INTO ?')
                ->execute([$file]);
            chmod($file, 0600);
            $copy = new PDO('sqlite:' . $file);
            $integrity = $copy->query('PRAGMA integrity_check')->fetchColumn();
            $copy = null;
            if ($integrity !== 'ok') {
                @unlink($file);
                throw new \RuntimeException('internal');
            }
            $s->audit('backup.run', basename($file), 'ok', null, [
                'bytes' => filesize($file),
                'integrity' => 'ok',
            ]);
            return [
                'staged' => true,
                'file' => basename($file),
                'bytes' => filesize($file),
                'integrity' => 'ok',
            ];
        } finally {
            umask($mask);
        }
    }

    private function cancelSubscription(string $sid, array $body): array
    {
        if (Config::get('FLUXFILES_ADMIN_POLAR_ACTIONS') !== '1') {
            throw new \InvalidArgumentException('feature_disabled');
        }
        $token = $body['polar_token'] ?? null;
        if (
            !is_string($token) ||
            !preg_match('/^[A-Za-z0-9_-]{16,512}$/D', $token) ||
            ($body['confirmation'] ?? '') !== $sid
        ) {
            throw new \InvalidArgumentException();
        }
        if (!$this->store->newestForSubscription($sid)) {
            throw new \InvalidArgumentException('not_found');
        }
        $base = Config::get(
            'FLUXFILES_POLAR_API_BASE',
            Config::sandbox() ? 'https://sandbox-api.polar.sh/v1' : 'https://api.polar.sh/v1',
        );
        if (
            $base !==
            (Config::sandbox() ? 'https://sandbox-api.polar.sh/v1' : 'https://api.polar.sh/v1')
        ) {
            $base = Config::loopback($base);
        }
        $ch = curl_init($base . '/subscriptions/' . rawurlencode($sid));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => '{"cancel_at_period_end":true}',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        unset($token, $body['polar_token']);
        $ok = $raw !== false && $status >= 200 && $status < 300;
        $this->store->audit('subscription.cancel', $sid, $ok ? 'ok' : 'failed', null, [
            'http_status' => $status,
        ]);
        return ['requested' => $ok, 'http_status' => $status];
    }
}
