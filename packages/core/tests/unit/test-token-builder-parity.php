<?php

/**
 * Token-builder claim-parity guard. Every JWT claim read in Claims.php
 * (`$payload->X`) should be reachable as a *named option* in all five token
 * builders, not only through each builder's generic `claims` escape hatch.
 *
 * Why this exists: `test-config-doc.php` already guards Claims.php against
 * docs/reference/CONFIG.md, but nothing guarded Claims.php against the
 * builders. A claim added to the core is mintable via `claims` from day one,
 * so nothing breaks and no test fails — it just never appears in anyone's
 * autocomplete, and the drift is invisible until an integrator asks why a
 * documented feature has no option. That happened: `allow_git_deploy`,
 * `git_deploy_*`, `share_brand_*`, `intake_brand_*`, `intake_analytics` and
 * `pro_hints` were shipped and documented, yet only reachable as raw strings.
 *
 * This guard is about *presence of the claim name* in each builder's source,
 * which is exactly the property that goes stale. It deliberately does not
 * check option naming or types — each language keeps its own convention
 * (camelCase in TS, snake_case in Python), and pinning those here would make
 * the guard fight the builders instead of protecting them.
 *
 * Usage: php packages/core/tests/unit/test-token-builder-parity.php
 */

declare(strict_types=1);

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $yellow = "\033[33m"; $reset = "\033[0m";

$root = dirname(__DIR__, 4);
$claimsFile = $root . '/packages/core/api/Claims.php';

/**
 * Claims that are deliberately NOT named options in the builders. Each entry
 * must say why, because an unexplained exemption is how the drift this guard
 * exists to catch creeps back in.
 */
$intentionallyRaw = [
    // Identity/structure — every builder sets these from its own first-class
    // arguments (user, perms, disks, prefix, ttl…), not from a claim option.
    'sub'         => 'set from the builder\'s own $user/user argument',
    'perms'       => 'first-class argument in every builder',
    'disks'       => 'first-class argument in every builder',
    'prefix'      => 'first-class argument in every builder',
    'owner_only'  => 'first-class argument + role-preset output',
    'max_upload'  => 'first-class argument (maxUploadMb)',
    'allowed_ext' => 'first-class argument (allowedExt)',
    'max_storage' => 'first-class argument (maxStorageMb)',
    'max_files'   => 'first-class argument (maxFiles)',
    'variants'    => 'first-class argument, sanitized via Claims::sanitizeVariants',

    // BYOB credentials are encrypted by a dedicated builder function
    // (fluxfiles_token_byob / createByobToken), never passed as a plain claim.
    'byob_disks'  => 'minted only by the dedicated BYOB builder, encrypted at mint time',
];

/**
 * The five token builders. `claims` is each one's generic escape hatch — its
 * presence is asserted separately, since it is what keeps an un-named claim
 * mintable in the meantime.
 */
$builders = [
    'core/embed.php'   => $root . '/packages/core/embed.php',
    'node'             => $root . '/packages/node/src/token.ts',
    'python'           => $root . '/packages/python/src/fluxfiles_token/token.py',
    'laravel'          => $root . '/packages/laravel/src/FluxFilesManager.php',
    'wordpress'        => $root . '/packages/wordpress/includes/FluxFilesPlugin.php',
];

/**
 * Per-builder exemptions: a claim a specific builder must NOT forward, as
 * opposed to one no builder names. Same rule as above — every entry carries
 * its reason, and the reason has to be about that builder's own constraints.
 */
$builderExempt = [
    'wordpress' => [
        // The OVERLAY watermark is preview-time compositing served by
        // /api/fm/img. The WordPress plugin is proxy-only and its /img port
        // doesn't implement the compositing branch, so forwarding these would
        // mint a token whose watermark is silently dropped — and because an
        // overlay watermark also forces the token preview-only, the result is
        // neither a clean URL nor a preview. Burn-in (POST /api/fm/watermark)
        // is the proxied path. See the NOTE in FluxFilesPlugin.php.
        'watermark_enabled'   => 'overlay watermark is not implemented by the proxy /img port',
        'watermark_type'      => 'travels with watermark_enabled',
        'watermark_text'      => 'travels with watermark_enabled',
        'watermark_logo_path' => 'travels with watermark_enabled',
        'watermark_position'  => 'travels with watermark_enabled',
        'watermark_opacity'   => 'travels with watermark_enabled',
        'watermark_font_size' => 'travels with watermark_enabled',
    ],
];

echo "\n{$cyan}══ Token-builder claim parity (Claims.php ↔ 5 builders) ══{$reset}\n\n";

if (!is_file($claimsFile)) {
    fwrite(STDERR, "  {$red}FAIL{$reset} Claims.php not found at {$claimsFile}\n");
    exit(1);
}

$src = (string) file_get_contents($claimsFile);
preg_match_all('/\$payload->([a-z_0-9]+)/', $src, $m);
$claims = array_values(array_unique($m[1]));
sort($claims);

$expected = array_values(array_diff($claims, array_keys($intentionallyRaw)));

$errors = 0;
$sources = [];

foreach ($builders as $label => $path) {
    if (!is_file($path)) {
        echo "  {$red}✗ {$label}: source not found at {$path}{$reset}\n";
        $errors++;
        continue;
    }
    $sources[$label] = (string) file_get_contents($path);
}

if ($errors > 0) {
    echo "\n{$red}{$errors} error(s) found.{$reset}\n";
    exit(1);
}

echo '  Claims parsed in Claims.php: ' . count($claims)
    . ' (' . count($expected) . ' expected as named options, '
    . count($intentionallyRaw) . " exempt)\n\n";

foreach ($sources as $label => $body) {
    // The escape hatch must exist: it is what keeps a not-yet-named claim
    // mintable, and it is the documented workaround this guard points at.
    if (strpos($body, 'claims') === false) {
        echo "  {$red}✗ {$label}: no generic `claims` escape hatch found{$reset}\n";
        $errors++;
    }

    $exempt = $builderExempt[$label] ?? [];
    $missing = [];
    foreach ($expected as $claim) {
        if (isset($exempt[$claim])) {
            continue;
        }
        // Presence of the raw snake_case claim name anywhere in the builder.
        // Every builder writes the claim key literally when it forwards it
        // (payload['x'] / payload.x = / payload["x"]), so this is a faithful
        // test of "does this builder know about this claim at all".
        if (!preg_match('/\b' . preg_quote($claim, '/') . '\b/', $body)) {
            $missing[] = $claim;
        }
    }

    if ($missing !== []) {
        echo "  {$red}✗ {$label}: " . count($missing) . " claim(s) with no named option:{$reset}\n";
        echo "      " . implode(', ', $missing) . "\n";
        $errors++;
    } else {
        $note = $exempt === [] ? '' : ' (' . count($exempt) . ' builder-specific exemption(s))';
        echo "  {$green}✓ {$label}{$reset}{$note}\n";
    }
}

if ($errors > 0) {
    echo "\n{$yellow}  Fix by adding a named option for each claim above, mirroring the\n";
    echo "  builder's existing convention. If a claim genuinely should stay\n";
    echo "  raw-only, add it to \$intentionallyRaw in this file WITH a reason.{$reset}\n";
    echo "\n{$red}{$errors} error(s) found.{$reset}\n";
    exit(1);
}

echo "\n  {$green}✓ every claim has a named option in all " . count($builders) . " token builders{$reset}\n";
echo "\n{$green}All tests passed!{$reset}\n";
exit(0);
