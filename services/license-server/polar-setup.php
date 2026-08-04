<?php

declare(strict_types=1);

/**
 * Create the Polar products for each licence plan, and print the plan map.
 *
 * Reads `Plans.php` and creates ONE Polar product per plan, so the two can never
 * drift: a plan that exists here but not in Polar is unsellable, and a Polar product
 * whose id is not in FLUXFILES_POLAR_PLAN_MAP makes the webhook reject the order.
 * Both failures are silent at the till, which is why this is generated rather than
 * clicked together by hand.
 *
 * SAFETY: defaults to the SANDBOX. Production creates products real customers can buy,
 * so it needs an explicit --production flag. Re-running is safe: a plan whose product
 * already exists (matched by name) is skipped, never duplicated.
 *
 * Usage:
 *   POLAR_TOKEN=polar_oat_… php services/license-server/polar-setup.php
 *   POLAR_TOKEN=polar_oat_… php services/license-server/polar-setup.php --production
 *   … --dry-run          show what would be created, call nothing
 *
 * The token needs the `products:write` and `products:read` scopes. Create it under
 * Polar → Settings → Developers. It is read from the environment and never written
 * to disk or echoed.
 */

require_once __DIR__ . '/Plans.php';

use FluxFiles\LicenseServer\Plans;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";

/**
 * What to sell, and for how much.
 *
 * Prices are in CENTS. The ranges come from docs/COMMERCIAL-STRATEGY.md (Pro $59–99/yr,
 * Studio $199–399/yr); the exact number is a business decision, so edit it here before
 * running rather than letting a script pick it.
 *
 * Plans deliberately absent:
 *   enterprise — sold custom with an SLA, not self-serve. A checkout button for it
 *                would undercut the conversation that tier exists to have.
 *   lifetime   — a one-time price, worth offering only once recurring revenue is
 *                proven. Add `'lifetime' => [...]` with recurring => null to sell it.
 */
const CATALOGUE = [
    'pro' => [
        'name' => 'FluxFiles Pro',
        'description' => 'Branded share links and client upload portals, for unlimited sites. Includes priority support and one year of updates.',
        'amount' => 7900,           // $79 / year
        'recurring' => 'year',
    ],
    'pro-monthly' => [
        'name' => 'FluxFiles Pro (monthly)',
        'description' => 'Branded share links and client upload portals, for unlimited sites. Billed monthly.',
        'amount' => 900,            // $9 / month
        'recurring' => 'month',
    ],
    'studio' => [
        'name' => 'FluxFiles Studio',
        'description' => 'Everything in Pro plus file versioning, webhooks and the AI/OCR modules (bring your own API key). For teams embedding FluxFiles in their own product.',
        'amount' => 24900,          // $249 / year
        'recurring' => 'year',
    ],
    'studio-monthly' => [
        'name' => 'FluxFiles Studio (monthly)',
        'description' => 'Everything in Pro plus file versioning, webhooks and the AI/OCR modules (bring your own API key). Billed monthly.',
        // Keeps the same annual discount as Pro (which is $9/mo against $79/yr, ~27%
        // off): 12 × $29 = $348 against $249.
        'amount' => 2900,           // $29 / month
        'recurring' => 'month',
    ],
];

$token = (string) (getenv('POLAR_TOKEN') ?: '');
$production = in_array('--production', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);
$base = $production ? 'https://api.polar.sh/v1' : 'https://sandbox-api.polar.sh/v1';

echo "\n{$cyan}══ Polar product setup ══{$reset}\n";
echo '  environment: ' . ($production ? "{$red}PRODUCTION{$reset}" : "{$green}sandbox{$reset}") . "\n";
echo "  api: {$base}\n\n";

if ($token === '') {
    echo "  {$red}POLAR_TOKEN is not set.{$reset}\n";
    echo "  Create one at Polar → Settings → Developers with the products:read and\n";
    echo "  products:write scopes, then:  POLAR_TOKEN=polar_oat_… php " . basename(__FILE__) . "\n\n";
    exit(1);
}

/**
 * One API call. Returns [httpStatus, decodedBody].
 *
 * @return array{0:int,1:array<mixed>}
 */
function api(string $method, string $path, ?array $body = null): array
{
    global $base, $token;
    $ch = curl_init($base . $path);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        // Deliberately do NOT follow redirects: this request carries a bearer token,
        // and the collection paths below already end in '/' — the API 307s to the
        // trailing-slash form otherwise, which on a POST would silently drop the body.
        CURLOPT_FOLLOWLOCATION => false,
    ] + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [0, ['error' => $err]];
    }
    return [$status, json_decode((string) $raw, true) ?: []];
}

// Existing products first, so a re-run updates the map instead of creating duplicates.
// `is_archived=false` is explicit rather than left to the endpoint's default: an
// archived product still has a name, and matching one would point the plan map at
// something customers cannot buy — a checkout that fails only for real buyers.
[$st, $list] = api('GET', '/products/?limit=100&is_archived=false');
if ($st === 401 || $st === 403) {
    echo "  {$red}The token was rejected ({$st}).{$reset} Check it carries products:read/write,\n";
    echo "  and that it belongs to " . ($production ? 'production' : 'the SANDBOX') . " — tokens are not shared between them.\n\n";
    exit(1);
}
if ($st !== 200) {
    echo "  {$red}Could not list products (HTTP {$st}).{$reset} " . json_encode($list) . "\n\n";
    exit(1);
}
$existing = [];
foreach (($list['items'] ?? []) as $p) {
    $existing[(string) ($p['name'] ?? '')] = (string) ($p['id'] ?? '');
}

$planMap = [];
$failed = 0;

foreach (CATALOGUE as $planId => $spec) {
    // A catalogue entry with no matching plan would sell a licence the issuer refuses.
    if (Plans::get($planId) === null) {
        echo "  {$red}FAIL{$reset} {$planId}: no such plan in Plans.php\n";
        $failed++;
        continue;
    }

    if (isset($existing[$spec['name']])) {
        $planMap[$existing[$spec['name']]] = $planId;
        printf("  {$yellow}SKIP{$reset} %-24s already exists (%s)\n", $spec['name'], $existing[$spec['name']]);
        continue;
    }

    if ($dryRun) {
        printf("  {$cyan}DRY {$reset} %-24s \$%s / %s\n", $spec['name'], number_format($spec['amount'] / 100, 2), $spec['recurring']);
        continue;
    }

    $price = ['amount_type' => 'fixed', 'price_amount' => $spec['amount'], 'price_currency' => 'usd'];
    if ($spec['recurring'] !== null) {
        $price['recurring_interval'] = $spec['recurring'];
    }
    [$st, $res] = api('POST', '/products/', [
        'name' => $spec['name'],
        'description' => $spec['description'],
        'recurring_interval' => $spec['recurring'],
        'prices' => [$price],
    ]);

    if ($st !== 201 && $st !== 200) {
        echo "  {$red}FAIL{$reset} {$spec['name']}: HTTP {$st} " . json_encode($res) . "\n";
        $failed++;
        continue;
    }
    $id = (string) ($res['id'] ?? '');
    $planMap[$id] = $planId;
    printf("  {$green}OK{$reset}   %-24s \$%s / %s  %s\n",
        $spec['name'], number_format($spec['amount'] / 100, 2), $spec['recurring'], $id);
}

echo "\n";
if ($dryRun) {
    echo "  dry run — nothing was created\n\n";
    exit(0);
}

// The map the webhook needs. An id missing from here makes the order fall through to
// the product NAME, which Plans::get() then refuses — a loud failure, but a failure.
echo "  Put this in the license server's environment:\n\n";
echo "  export FLUXFILES_POLAR_PLAN_MAP='" . json_encode($planMap, JSON_UNESCAPED_SLASHES) . "'\n\n";

if (!$production) {
    echo "  {$yellow}Sandbox.{$reset} Re-run with --production once the checkout is proven end to end.\n";
    echo "  Sandbox and production have separate tokens, products and webhook secrets.\n\n";
}

exit($failed > 0 ? 1 : 0);
