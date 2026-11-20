<?php

/**
 * Paid-module design specs must not land in this PUBLIC repo.
 *
 * On 2026-11-20 the specs for the paid modules (Share, Intake, DLP, legal
 * hold/retention, …) and the Pro add-on moved to the owner's private repo
 * `fluxfiles-private-docs`; code cites them as
 * `fluxfiles-private-docs: design/<FILE>.md`. This guard fails when:
 *   1. a moved spec reappears anywhere in the repo;
 *   2. a new file is added to docs/design/ without being on the allowlist below.
 *
 * Adding a design record for a FREE/core feature? Add it to $publicDesign.
 * A spec for a paid module or the Pro add-on goes to fluxfiles-private-docs.
 *
 * Usage: php tests/unit/test-no-paid-specs-public.php
 */

declare(strict_types=1);

$repo = dirname(__DIR__, 4);

/** Free/core (or already-public code, e.g. services/license-server) design records. */
$publicDesign = [
    'ACL-ROLE-PRESETS-DESIGN.md',
    'COMPANION-EMBED-DESIGN.md',
    'COMPLIANCE-SCORECARD-DESIGN.md',
    'CONNECTION-TEST-DESIGN.md',
    'DB-STORAGE-MIGRATION-DESIGN.md',
    'LICENSE-ADMIN-UI-DESIGN.md',
    'LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md',
    'LICENSE-KEY-REFRESH-DESIGN.md',
    'MULTI-PREFIX-TOKEN-DESIGN.md',
    'PYTHON-TOKEN-SDK-DESIGN.md',
    'TEAMMATE-FOLDER-SHARING-DESIGN.md',
];

/** Moved to fluxfiles-private-docs; must never come back. */
$moved = [
    'DLP-PII-REDACTION-DESIGN.md', 'FEATURE-IDEAS-2026-10.md',
    'INTAKE-BRANDING-ANALYTICS-DESIGN.md', 'INTAKE-DOCUMENT-REQUESTS-DESIGN.md',
    'INTAKE-NOTIFY-ON-RECEIPT-DESIGN.md', 'INTAKE-SHARE-NOTIFICATIONS-DESIGN.md',
    'OPERATOR-SHARE-INTAKE-UI.md', 'RETENTION-LEGAL-HOLD-DESIGN.md',
    'SHARE-ANALYTICS-DESIGN.md', 'SHARE-PUBLIC-LANDING.md',
    'WP-MEDIA-FOLDERS-DESIGN.md', 'WPORG-SPLIT-DESIGN.md',
    // Never committed, keep it that way.
    'COMMERCIAL-STRATEGY.md', 'LICENSING-PLAN.md', 'WP-PRO-ADDON-DESIGN.md',
];

if (!is_dir("$repo/docs/design")) {
    echo "  SKIP (no docs/design: not the monorepo)\n";
    exit(0);
}

$failed = 0;

// Tracked files only when git is available, so a local gitignored copy is fine.
$tracked = [];
exec('git -C ' . escapeshellarg($repo) . ' ls-files 2>/dev/null', $tracked, $rc);
if ($rc !== 0 || $tracked === []) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$repo/docs", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { $tracked[] = substr($f->getPathname(), strlen($repo) + 1); }
}

foreach ($tracked as $path) {
    if (in_array(basename($path), $moved, true)) {
        echo "  FAIL $path is a private spec (belongs in fluxfiles-private-docs)\n";
        $failed++;
    }
    if (preg_match('#^docs/design/[^/]+\.md$#', $path) && !in_array(basename($path), $publicDesign, true)) {
        echo "  FAIL $path is not on the public design allowlist — free/core: add it to \$publicDesign; paid module/Pro: move it to fluxfiles-private-docs\n";
        $failed++;
    }
}

echo $failed === 0 ? "  PASS no paid-module spec in the public repo\n" : "\n  Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
