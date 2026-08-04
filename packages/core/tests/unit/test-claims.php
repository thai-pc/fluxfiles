<?php

/**
 * Test script for Claims value object.
 *
 * Usage:
 *   php tests/test-claims.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

foreach ([__DIR__ . "/../..", __DIR__ . "/../../../.."] as $envDir) {
    if (is_file($envDir . "/.env")) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

require_once __DIR__ . '/../../embed.php';

$green  = "\033[32m";
$red    = "\033[31m";
$yellow = "\033[33m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try {
        $fn();
        echo "  {$green}PASS{$reset} {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $msg ?: "Expected " . json_encode($expected) . " but got " . json_encode($actual)
        );
    }
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles Claims Test Suite                 ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► fromJwtPayload{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('fromJwtPayload with minimal payload uses defaults', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);

    assertEqual('0', $claims->userId);
    assertEqual(['read'], $claims->permissions);
    assertEqual(['local'], $claims->allowedDisks);
    assertEqual('', $claims->pathPrefix);
    assertEqual(10, $claims->maxUploadMb);
    assertEqual(null, $claims->allowedExt);
    assertEqual(0, $claims->maxStorageMb);
    assertEqual(0, $claims->maxFiles);
    assertEqual([], $claims->byobDisks);
});

test('fromJwtPayload with full payload', function () {
    $payload = (object) [
        'sub'         => 'user-42',
        'perms'       => ['read', 'write', 'delete'],
        'disks'       => ['local', 's3'],
        'prefix'      => 'uploads/user42',
        'max_upload'  => 50,
        'allowed_ext' => ['jpg', 'png', 'pdf'],
        'max_storage' => 500,
        'max_files'   => 25,
    ];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);

    assertEqual('user-42', $claims->userId);
    assertEqual(['read', 'write', 'delete'], $claims->permissions);
    assertEqual(['local', 's3'], $claims->allowedDisks);
    assertEqual('uploads/user42', $claims->pathPrefix);
    assertEqual(50, $claims->maxUploadMb);
    assertEqual(['jpg', 'png', 'pdf'], $claims->allowedExt);
    assertEqual(500, $claims->maxStorageMb);
    assertEqual(25, $claims->maxFiles);
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► hasPerm{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('hasPerm returns true for granted permission', function () {
    $claims = new FluxFiles\Claims('u1', ['read', 'write'], ['local'], '', 10, null, 0, false);
    assertEqual(true, $claims->hasPerm('read'));
    assertEqual(true, $claims->hasPerm('write'));
});

test('hasPerm returns false for missing permission', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual(false, $claims->hasPerm('write'));
    assertEqual(false, $claims->hasPerm('delete'));
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► hasDisk{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('hasDisk returns true for allowed disk', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local', 's3'], '', 10, null, 0, false);
    assertEqual(true, $claims->hasDisk('local'));
    assertEqual(true, $claims->hasDisk('s3'));
});

test('hasDisk returns false for disallowed disk', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual(false, $claims->hasDisk('s3'));
    assertEqual(false, $claims->hasDisk('r2'));
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► isPathInScope{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('isPathInScope: empty prefix always returns true', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual(true, $claims->isPathInScope('anything/goes/here'));
    assertEqual(true, $claims->isPathInScope(''));
    assertEqual(true, $claims->isPathInScope('/'));
});

test('isPathInScope: exact prefix match', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual(true, $claims->isPathInScope('uploads/user1'));
});

test('isPathInScope: subdirectory of prefix is in scope', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual(true, $claims->isPathInScope('uploads/user1/photos/pic.jpg'));
});

test('isPathInScope: prefix mismatch', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual(false, $claims->isPathInScope('uploads/user2'));
    assertEqual(false, $claims->isPathInScope('downloads/user1'));
    assertEqual(false, $claims->isPathInScope('other'));
});

test('isPathInScope: path traversal attempt with .. (raw path checked as-is)', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    // The raw string still starts with "uploads/user1/" so isPathInScope sees it as in scope.
    // Security relies on scopePath() stripping ".." before filesystem access.
    assertEqual(true, $claims->isPathInScope('uploads/user1/../../etc/passwd'));
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► scopePath{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('scopePath: simple path without prefix', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual('photos/pic.jpg', $claims->scopePath('photos/pic.jpg'));
});

test('scopePath: simple path with prefix', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual('uploads/user1/photos/pic.jpg', $claims->scopePath('photos/pic.jpg'));
});

test('scopePath: strips .. segments (does not resolve, just removes)', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    // ".." segments are dropped entirely, not resolved — so "foo" stays
    assertEqual('foo/etc/passwd', $claims->scopePath('foo/../../etc/passwd'));
});

test('scopePath: strips . segments', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual('foo/bar', $claims->scopePath('./foo/./bar'));
});

test('scopePath: empty path without prefix', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual('', $claims->scopePath(''));
});

test('scopePath: empty path with prefix → prefix (no trailing slash)', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual('uploads/user1', $claims->scopePath(''));
});

// Idempotency: list() returns full prefixed keys and the UI navigates with them,
// so an already-prefixed path must not be prefixed twice.
test('scopePath: already-prefixed path is NOT doubled (the navigate bug)', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual('uploads/user1/posts', $claims->scopePath('uploads/user1/posts'));
    assertEqual('uploads/user1', $claims->scopePath('uploads/user1'));
});

test('scopePath: relative path is still prefixed', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual('uploads/user1/posts', $claims->scopePath('posts'));
});

// Security: idempotency must NOT let one user reach another's prefix.
test('scopePath: foreign top-level path is sandboxed back into the prefix', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'user_1', 10, null, 0);
    // "user_2/secret" does not start with "user_1/" → prepended, stays in user_1
    assertEqual('user_1/user_2/secret', $claims->scopePath('user_2/secret'));
});

// Cross-tenant rejection: a prefix WITH a parent ("users/42") fails closed when a
// path targets a sibling tenant under the same parent ("users/99/…").
test('scopePath: cross-tenant sibling path is rejected (403 path_denied)', function () {
    $claims = new FluxFiles\Claims('u42', ['read'], ['local'], 'users/42', 10, null, 0);
    $threw = false; $code = null;
    try {
        $claims->scopePath('users/99/secret.jpg');
    } catch (\FluxFiles\ApiException $e) {
        $threw = true; $code = $e->getErrorCode();
    }
    assertEqual(true, $threw, 'sibling tenant path must throw');
    assertEqual('path_denied', $code);
});

test('scopePath: in-scope + relative paths still work with a parented prefix', function () {
    $claims = new FluxFiles\Claims('u42', ['read'], ['local'], 'users/42', 10, null, 0);
    assertEqual('users/42', $claims->scopePath(''));                       // root
    assertEqual('users/42/photos', $claims->scopePath('photos'));          // relative
    assertEqual('users/42/photos/a.jpg', $claims->scopePath('users/42/photos/a.jpg')); // absolute in-scope
});

test('scopePath: .. inside an already-prefixed path cannot escape the prefix', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'user_1', 10, null, 0);
    // ".." stripped → "user_1/user_2/secret" (a folder inside user_1, not user_2's root)
    assertEqual('user_1/user_2/secret', $claims->scopePath('user_1/../user_2/secret'));
});

test('scopePath: prefix confusion (user_1 vs user_10) is blocked by the / boundary', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'user_1', 10, null, 0);
    assertEqual('user_1/user_10/x', $claims->scopePath('user_10/x'));
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► unscopePath (strip prefix for client-facing keys){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('unscopePath: strips the prefix from an absolute key', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual('posts/a.jpg', $claims->unscopePath('uploads/user1/posts/a.jpg'));
});

test('unscopePath: the prefix root itself maps to the relative root ""', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual('', $claims->unscopePath('uploads/user1'));
});

test('unscopePath: no prefix → key unchanged', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0);
    assertEqual('posts/a.jpg', $claims->unscopePath('posts/a.jpg'));
});

test('unscopePath: already-relative key is left as-is (idempotent)', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual('posts/a.jpg', $claims->unscopePath('posts/a.jpg'));
});

test('unscopePath: round-trips with scopePath', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    $abs = $claims->scopePath('posts/a.jpg');           // uploads/user1/posts/a.jpg
    assertEqual('posts/a.jpg', $claims->unscopePath($abs));
    // and the client's relative key re-scopes back to the same absolute key
    assertEqual($abs, $claims->scopePath($claims->unscopePath($abs)));
});

test('unscopePath: the / boundary keeps user_1 vs user_10 distinct', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'user_1', 10, null, 0);
    // "user_10/x" is NOT under prefix "user_1" → left unchanged, not mangled
    assertEqual('user_10/x', $claims->unscopePath('user_10/x'));
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► Defaults and allowed extensions{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('maxUploadMb defaults to 10', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(10, $claims->maxUploadMb);
});

test('maxStorageMb defaults to 0', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(0, $claims->maxStorageMb);
});

test('allowedExt is null when not set in payload', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(null, $claims->allowedExt);
});

test('allowedExt is array when set in payload', function () {
    $payload = (object) ['allowed_ext' => ['jpg', 'png']];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(['jpg', 'png'], $claims->allowedExt);
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► ownerOnly{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('ownerOnly defaults to false', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(false, $claims->ownerOnly);
});

test('ownerOnly parsed from JWT payload', function () {
    $payload = (object) ['owner_only' => true];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(true, $claims->ownerOnly);
});

test('ownerOnly false when JWT has owner_only=false', function () {
    $payload = (object) ['owner_only' => false];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(false, $claims->ownerOnly);
});

test('ownerOnly set via constructor', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, true);
    assertEqual(true, $claims->ownerOnly);
});

test('embed.php fluxfiles_token with ownerOnly includes owner_only claim', function () {
    $token = fluxfiles_token('user-99', ['read', 'write', 'delete'], ['local'], '', 10, null, 3600, true);
    $parts = explode('.', $token);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    assertEqual(true, $payload['owner_only'] ?? null, 'owner_only should be true');
});

test('embed.php fluxfiles_token without ownerOnly omits owner_only claim', function () {
    $token = fluxfiles_token('user-99', ['read'], ['local'], '', 10, null, 3600, false);
    $parts = explode('.', $token);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    assertEqual(false, isset($payload['owner_only']), 'owner_only should not be set');
});

// ═══════════════════════════════════════════════════════════════
// Watermark overlay ⇒ preview-only (no contradictory clean download)
// ═══════════════════════════════════════════════════════════════

test('watermark overlay forces allow_download off (even if set true)', function () {
    $c = FluxFiles\Claims::fromJwtPayload((object) [
        'sub' => 'u', 'perms' => ['read'], 'disks' => ['local'],
        'allow_download' => true,                 // explicitly true…
        'watermark_enabled' => true, 'watermark_text' => '© Acme',
    ]);
    assertEqual(false, $c->allowDownload, 'overlay watermark ⇒ allow_download off');
    assertEqual(true, $c->watermark !== null, 'watermark config assembled');
});

test('no watermark ⇒ allow_download stays as given', function () {
    $on = FluxFiles\Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read'], 'disks' => ['local'], 'allow_download' => true]);
    assertEqual(true, $on->allowDownload, 'download stays true without a watermark');
    $off = FluxFiles\Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read'], 'disks' => ['local'], 'allow_download' => false]);
    assertEqual(false, $off->allowDownload, 'explicit false respected');
});

// ═══════════════════════════════════════════════════════════════
// Versioning + webhook config claims (the adapters forward these; the
// validation lives here, so this is where it gets locked)
// ═══════════════════════════════════════════════════════════════

function claimsWith(array $extra): FluxFiles\Claims
{
    return FluxFiles\Claims::fromJwtPayload((object) array_merge(
        ['sub' => 'u', 'perms' => ['read'], 'disks' => ['local']],
        $extra
    ));
}

test('versioning_max / versioning_max_mb are clamped non-negative, 0 = default', function () {
    $d = claimsWith([]);
    assertEqual(0, $d->versioningMax, 'default 0 (module applies its own default)');
    assertEqual(0, $d->versioningMaxMb, 'default 0');

    $c = claimsWith(['versioning_max' => 5, 'versioning_max_mb' => 50]);
    assertEqual(5, $c->versioningMax, 'max carried');
    assertEqual(50, $c->versioningMaxMb, 'max_mb carried');

    $neg = claimsWith(['versioning_max' => -3, 'versioning_max_mb' => -1]);
    assertEqual(0, $neg->versioningMax, 'negative floored to 0');
    assertEqual(0, $neg->versioningMaxMb, 'negative floored to 0');
});

test('webhook_url accepts only http(s) — anything else is dropped', function () {
    assertEqual('https://hooks.acme.com/f', claimsWith(['webhook_url' => 'https://hooks.acme.com/f'])->webhookUrl, 'https kept');
    assertEqual('http://hooks.acme.com/f', claimsWith(['webhook_url' => 'http://hooks.acme.com/f'])->webhookUrl, 'http kept');
    assertEqual('', claimsWith([])->webhookUrl, 'empty by default');

    // A non-HTTP scheme must never survive: the module POSTs to this URL, so a
    // file:/gopher:/javascript: value would be a scheme-confusion foothold.
    foreach (['file:///etc/passwd', 'gopher://x/', 'javascript:alert(1)', 'ftp://x/', '//evil.com', 'not a url'] as $bad) {
        assertEqual('', claimsWith(['webhook_url' => $bad])->webhookUrl, "dropped: {$bad}");
    }
});

test('webhook_events is normalized to a lowercase trimmed list; webhook_secret passes through', function () {
    $c = claimsWith(['webhook_events' => ['  Upload ', 'DELETE', '']]);
    assertEqual(['upload', 'delete'], $c->webhookEvents, 'trimmed + lowercased, blanks dropped');

    assertEqual([], claimsWith([])->webhookEvents, 'empty by default = all events');

    // A comma-separated string is also accepted, so a plain admin text field works.
    assertEqual(['upload', 'delete'], claimsWith(['webhook_events' => 'Upload, DELETE'])->webhookEvents, 'CSV string parsed');
    assertEqual(['upload'], claimsWith(['webhook_events' => 'upload,,'])->webhookEvents, 'blank CSV entries dropped');
    assertEqual([], claimsWith(['webhook_events' => '   '])->webhookEvents, 'whitespace-only string = all events');

    assertEqual('whsec_abc', claimsWith(['webhook_secret' => 'whsec_abc'])->webhookSecret, 'secret carried');
    assertEqual('', claimsWith([])->webhookSecret, 'empty = falls back to FLUXFILES_SECRET');
});

// ═══════════════════════════════════════════════════════════════
// Share landing claims (read at create time, baked into the share record —
// a public recipient request carries no claims at all)
// ═══════════════════════════════════════════════════════════════

test('share_url_ttl is clamped to [10, 300]; 0/absent = 60', function () {
    assertEqual(60, claimsWith([])->shareUrlTtl, 'default 60s');
    assertEqual(60, claimsWith(['share_url_ttl' => 0])->shareUrlTtl, '0 = default');
    assertEqual(120, claimsWith(['share_url_ttl' => 120])->shareUrlTtl, 'in-range carried');
    assertEqual(10, claimsWith(['share_url_ttl' => 1])->shareUrlTtl, 'floor 10s');
    assertEqual(300, claimsWith(['share_url_ttl' => 86400])->shareUrlTtl, 'ceiling 300s');
    assertEqual(60, claimsWith(['share_url_ttl' => -5])->shareUrlTtl, 'negative = default');
});

test('share_base_url accepts only http(s) — anything else is dropped', function () {
    $ok = 'https://files.acme.com/public/share.html';
    assertEqual($ok, claimsWith(['share_base_url' => $ok])->shareBaseUrl, 'https kept');
    assertEqual('http://f.acme.com/s', claimsWith(['share_base_url' => 'http://f.acme.com/s'])->shareBaseUrl, 'http kept');
    assertEqual('', claimsWith([])->shareBaseUrl, 'empty by default = the request origin');
    // The create response hands this straight to a UI as a link — a javascript:
    // value must never survive.
    foreach (['javascript:alert(1)', 'data:text/html,x', 'file:///etc/passwd', '//evil.com', 'nope'] as $bad) {
        assertEqual('', claimsWith(['share_base_url' => $bad])->shareBaseUrl, "dropped: {$bad}");
    }
});

test('share_preview defaults on and can be switched off', function () {
    assertEqual(true, claimsWith([])->sharePreview, 'preview on by default');
    assertEqual(false, claimsWith(['share_preview' => false])->sharePreview, 'download-only');
    assertEqual(true, claimsWith(['share_preview' => true])->sharePreview, 'explicit on');
});

test('intake_base_url accepts only http(s) — anything else is dropped', function () {
    $ok = 'https://files.acme.com/public/intake.html';
    assertEqual($ok, claimsWith(['intake_base_url' => $ok])->intakeBaseUrl, 'https kept');
    assertEqual('http://f.acme.com/i', claimsWith(['intake_base_url' => 'http://f.acme.com/i'])->intakeBaseUrl, 'http kept');
    assertEqual('', claimsWith([])->intakeBaseUrl, 'empty by default = the request origin');
    // The create response hands this straight to a UI as a link — a javascript:
    // value must never survive (same rule as share_base_url).
    foreach (['javascript:alert(1)', 'data:text/html,x', 'file:///etc/passwd', 'ftp://x/y', '//evil.com', 'nope'] as $bad) {
        assertEqual('', claimsWith(['intake_base_url' => $bad])->intakeBaseUrl, "dropped: {$bad}");
    }
});

test('pro_hints defaults ON and is an opt-OUT switch', function () {
    // Default true is safe because the UI additionally requires an unlicensed AND
    // unframed server before it renders anything — see docs/OPERATOR-SHARE-INTAKE-UI.md §5.1.
    assertEqual(true, claimsWith([])->proHints, 'absent → on');
    assertEqual(false, claimsWith(['pro_hints' => false])->proHints, 'explicit off honoured');
    assertEqual(true, claimsWith(['pro_hints' => true])->proHints, 'explicit on');
    // Coercion: a JSON payload can carry 0/1/"" rather than a real bool.
    assertEqual(false, claimsWith(['pro_hints' => 0])->proHints, '0 → off');
    assertEqual(false, claimsWith(['pro_hints' => ''])->proHints, 'empty string → off');
    assertEqual(true, claimsWith(['pro_hints' => 1])->proHints, '1 → on');
});

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  ";
echo "{$green}Passed: {$passed}{$reset}  ";
echo "{$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
