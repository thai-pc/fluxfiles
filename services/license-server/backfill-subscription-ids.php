<?php

declare(strict_types=1);

/**
 * One-off backfill for POST /refresh: store Polar's `subscription_id` (and the order's
 * `billing_reason`) on licence rows issued before the webhook captured them.
 *
 * For every `gateway='polar'` row with no subscription id that has not been looked up yet,
 * it calls Polar `GET /v1/orders/{order_id}` and writes what the order says. A one-time
 * order has no subscription: its row only gets billing_reason, which marks it as done so
 * a re-run does not ask again. Idempotent, safe to re-run, never overwrites a value that
 * is already set. Mirrors backfill-supersede-duplicates.php.
 *
 * After the ids are written (--apply) it keeps only the newest live row of each
 * subscription `active` (the rest `superseded`; a head that was wrongly superseded is
 * set back to `active`), idempotently, so legacy duplicates stop
 * surfacing in the reminder cron. A new order also supersedes the buyer's NULL-id rows
 * itself, so the deploy-to-backfill gap is safe. Rows Polar cannot resolve stay NULL.
 *
 * Run it BEFORE enabling /refresh.
 *
 * Usage:
 *   POLAR_TOKEN=polar_oat_... php backfill-subscription-ids.php            # dry run (reads Polar, writes nothing)
 *   POLAR_TOKEN=polar_oat_... php backfill-subscription-ids.php --apply
 *   php backfill-subscription-ids.php --token-file=/secure/polar-token --apply
 *   php backfill-subscription-ids.php --sandbox                            # sandbox-api.polar.sh, POLAR_TOKEN_SANDBOX
 *
 * Options: --delay-ms=N (pause between Polar calls, default 150), --backoff-ms=N (first wait
 * after an HTTP 429, doubled up to 4 retries, default 1000), --limit=N (rows per run).
 * Env: FLUXFILES_LICENSE_DB (sqlite path, as every script here). The token is read from
 * the env or a file, never from argv, and is never printed.
 * --api-base=URL exists for tests; it must be Polar's own API or an http loopback URL, so
 * the token cannot be sent anywhere else.
 */

if (PHP_SAPI !== 'cli') {   // never reachable over HTTP, even if the dir is served
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/LicenseStore.php';

use FluxFiles\LicenseServer\LicenseStore;

$args = array_slice($argv, 1);
$opt = static function (string $name) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=")) { return substr($a, strlen($name) + 3); }
    }
    return null;
};
$apply = in_array('--apply', $args, true);
$sandbox = in_array('--sandbox', $args, true);
$delayMs = max(0, (int) ($opt('delay-ms') ?? 150));
$backoffMs = max(0, (int) ($opt('backoff-ms') ?? 1000));
$limit = max(1, (int) ($opt('limit') ?? 5000));

$base = $opt('api-base') ?? ($sandbox ? 'https://sandbox-api.polar.sh/v1' : 'https://api.polar.sh/v1');
$base = rtrim($base, '/');
// The token is sent to this host: no userinfo / '@' / backslash tricks (http://127.0.0.1@evil/).
if (str_contains($base, '@') || str_contains($base, '\\') || parse_url($base, PHP_URL_USER) !== null || parse_url($base, PHP_URL_PASS) !== null) {
    fwrite(STDERR, "[backfill] refusing --api-base: credentials, '@' and backslashes are not allowed\n");
    exit(1);
}
$host = (string) parse_url($base, PHP_URL_HOST);
$scheme = (string) parse_url($base, PHP_URL_SCHEME);
$polarOk = $scheme === 'https' && in_array($host, ['api.polar.sh', 'sandbox-api.polar.sh'], true);
$loopOk = $scheme === 'http' && in_array(trim($host, '[]'), ['127.0.0.1', 'localhost', '::1'], true);
if (!$polarOk && !$loopOk) {
    fwrite(STDERR, "[backfill] refusing --api-base: must be Polar's API (https) or http loopback\n");
    exit(1);
}

$token = '';
$tokenFile = $opt('token-file');
if ($tokenFile !== null) {
    $token = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
} else {
    $token = trim((string) (getenv($sandbox ? 'POLAR_TOKEN_SANDBOX' : 'POLAR_TOKEN') ?: getenv('POLAR_TOKEN') ?: ''));
}
if ($token === '') {
    fwrite(STDERR, "[backfill] no Polar token: set POLAR_TOKEN (or POLAR_TOKEN_SANDBOX with --sandbox) or pass --token-file=PATH\n");
    exit(1);
}

/** @return array{0:int,1:?array<string,mixed>} http status (0 = transport error) and decoded body */
$fetchOrder = static function (string $orderId) use ($base, $token): array {
    $ch = curl_init($base . '/orders/' . rawurlencode($orderId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // the token must not follow a redirect elsewhere
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $d = is_string($raw) ? json_decode($raw, true) : null;
    return [$raw === false ? 0 : $st, is_array($d) ? $d : null];
};
$clean = static fn (string $v): string => preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', trim($v)) === 1 ? trim($v) : '';

$store = new LicenseStore();
$rows = $store->polarRowsNeedingSubscription($limit);
$supersedeStep = static function () use ($store, $apply): void {
    $sids = $store->subscriptionsNeedingRepair();
    $n = 0;
    foreach ($sids as $sid) {
        $revive = $store->repairableHead($sid);
        if ($revive !== null) {
            echo "[backfill] subscription={$sid}: " . ($apply ? 'reviving' : 'would revive') . " wrongly superseded jti={$revive} (newest row, still in term)\n";
        }
        if ($apply) { $n += $store->supersedeOlderInSubscription($sid); }
    }
    echo '[backfill] ' . count($sids) . ' subscription(s) to repair (several active rows, or a wrongly superseded head)' . ($apply ? ": {$n} row(s) changed\n" : " (dry run — no changes)\n");
};
if ($rows === []) {
    echo "[backfill] no Polar rows need a subscription id\n";
    $supersedeStep();
    exit(0);
}
echo '[backfill] ' . count($rows) . ' Polar row(s) to look up' . ($apply ? '' : ' (dry run — pass --apply to write)') . "\n";

$withSub = 0; $oneTime = 0; $failed = 0;
foreach ($rows as $i => $row) {
    if ($i > 0 && $delayMs > 0) { usleep($delayMs * 1000); }
    [$st, $d] = $fetchOrder((string) $row['order_id']);
    for ($try = 0, $wait = $backoffMs; $st === 429 && $try < 4; $try++, $wait *= 2) {
        echo "[backfill] Polar rate limit — waiting {$wait} ms\n";
        usleep($wait * 1000);
        [$st, $d] = $fetchOrder((string) $row['order_id']);
    }
    if ($st === 401 || $st === 403) {
        fwrite(STDERR, "[backfill] Polar rejected the token (HTTP {$st}) — stopping\n");
        exit(1);
    }
    if ($st !== 200 || $d === null) {
        $failed++;
        echo "[backfill] jti={$row['jti']}: Polar answered " . ($st === 0 ? 'no response' : "HTTP {$st}") . " — left as is\n";
        continue;
    }
    $sid = $clean((string) ($d['subscription_id'] ?? $d['subscription']['id'] ?? ''));
    $reason = $clean((string) ($d['billing_reason'] ?? ''));
    if ($sid !== '') { $withSub++; } else { $oneTime++; }
    echo "[backfill] jti={$row['jti']} plan={$row['plan']}: " . ($sid !== '' ? "subscription={$sid}" : 'no subscription (one-time)')
        . ($reason !== '' ? " reason={$reason}" : '') . "\n";
    if ($apply) {
        $store->setSubscription((string) $row['jti'], $sid, $reason);
    }
}

$supersedeStep();
echo "[backfill] done: {$withSub} with a subscription, {$oneTime} one-time, {$failed} failed"
    . ($apply ? " — written\n" : " — dry run, no changes made\n");
exit($failed > 0 ? 2 : 0);
