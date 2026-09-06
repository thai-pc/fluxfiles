<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Compliance Readiness Scorecard — a read-only, stateless capability checklist
 * over features a compliance program commonly cares about (virus scanning,
 * content provenance, audit retention, SSO, DLP, legal hold). See
 * docs/COMPLIANCE-SCORECARD-DESIGN.md for the full design.
 *
 * IMPORTANT (§3 of the design doc): this is a feature-toggle report, NOT a
 * compliance/legal certification. Never add a `score`/`percent`/`compliant`
 * field, and never phrase a string as "you are compliant" — only "these
 * FluxFiles features are enabled/available".
 *
 * Fully stateless — computed fresh from the already-decoded Claims, an
 * existing LicenseManager, and ModuleRegistry::installed() (a class_exists
 * check). No new storage, no new JWT claim, no license check on the view
 * itself (gated by the `audit` perm at the route level instead).
 */
final class ComplianceScorecard
{
    private const PRICING_URL = 'https://fluxfiles.dev/pricing';

    /**
     * The static capability table — id/label/category/module/claim, in the
     * stable display order returned to callers. `claim` is null for a row
     * gated by a server env flag instead of a per-token claim (SSO).
     *
     * @var array<int, array{id:string,label:string,category:string,module:string,claim:?string}>
     */
    private const TABLE = [
        [
            'id'       => 'virus_scan',
            'label'    => 'Virus / malware scanning on upload',
            'category' => 'content_security',
            'module'   => 'virus',
            'claim'    => 'allow_virus_scan',
        ],
        [
            'id'       => 'c2pa',
            'label'    => 'Content provenance (C2PA)',
            'category' => 'content_provenance',
            'module'   => 'c2pa',
            'claim'    => 'allow_c2pa',
        ],
        [
            'id'       => 'audit_export',
            'label'    => 'Audit log export & retention purge',
            'category' => 'audit_retention',
            'module'   => 'audit-export',
            'claim'    => 'allow_audit_export',
        ],
        [
            'id'       => 'sso',
            'label'    => 'SSO login bridge (OIDC)',
            'category' => 'identity_access',
            'module'   => 'sso',
            'claim'    => null,
        ],
        [
            'id'       => 'dlp_scan',
            'label'    => 'DLP / PII redaction',
            'category' => 'data_protection',
            'module'   => 'dlp',
            'claim'    => 'allow_dlp_scan',
        ],
        [
            'id'       => 'legal_hold',
            'label'    => 'Legal hold',
            'category' => 'legal_ediscovery',
            'module'   => 'legal-hold',
            'claim'    => 'allow_legal_hold',
        ],
    ];

    /**
     * `enabled` per row — a per-row lookup table because SSO is gated by a
     * server env flag, not a per-token claim (see §4 of the design doc). Every
     * other row just reads `$claims->isAllowed($claim)`.
     */
    private static function enabledFor(string $id, ?string $claim, Claims $claims): bool
    {
        if ($id === 'sso') {
            // SsoModule::claim() returns '' — SSO's layer-3 gate is a server env
            // flag, not a per-token claim. Independent of any Claims field.
            return ($_ENV['FLUXFILES_SSO_ENABLED'] ?? 'false') === 'true';
        }
        return $claim !== null && $claims->isAllowed($claim);
    }

    /**
     * Build the full scorecard response for the given request's Claims + the
     * server's license. Always succeeds (no throws) — every row degrades to
     * `available: false` on an unlicensed/uninstalled server rather than
     * erroring, so free-core callers get a complete, useful checklist.
     *
     * @return array{generated_at:int, disclaimer:string, summary:array{enabled_count:int,available_count:int,total_count:int}, categories:string[], items:array<int,array<string,mixed>>}
     */
    public static function build(Claims $claims, LicenseManager $license): array
    {
        $items = [];
        $enabledCount = 0;
        $availableCount = 0;

        foreach (self::TABLE as $row) {
            $enabled = self::enabledFor($row['id'], $row['claim'], $claims);
            $available = ModuleRegistry::installed($row['module']) && $license->licensed($row['module']);

            // `available` (installed + licensed) takes priority over `enabled`: a
            // claim can be true on an unlicensed/uninstalled server (the operator
            // flipped the claim but never bought/installed the module), and that
            // case must render as `locked`, not `on` — the feature literally
            // cannot run. See docs/COMPLIANCE-SCORECARD-DESIGN.md §8's worked
            // example: enabled:true + available:false => status:locked.
            if (!$available) {
                $status = 'locked';
                $whyNot = ModuleRegistry::installed($row['module']) ? 'not_licensed' : 'not_installed';
            } elseif ($enabled) {
                $status = 'on';
                $whyNot = null;
            } else {
                $status = 'off';
                $whyNot = 'claim_off';
            }

            $items[] = [
                'id'            => $row['id'],
                'label'         => $row['label'],
                'category'      => $row['category'],
                'module'        => $row['module'],
                'claim'         => $row['claim'],
                'enabled'       => $enabled,
                'available'     => $available,
                'status'        => $status,
                'why_not'       => $whyNot,
                // A copy-paste snippet only makes sense for an 'off' row (installed
                // + licensed, just not opted-in for this token) and only when the
                // row has a real per-token claim (SSO has none — it's an env flag).
                'claim_snippet' => ($status === 'off' && $row['claim'] !== null)
                    ? "'claims' => ['{$row['claim']}' => true]"
                    : null,
                // Only a locked row is a genuine upsell surface.
                'docs_url'      => $status === 'locked' ? self::PRICING_URL : null,
            ];

            if ($enabled) {
                $enabledCount++;
            }
            if ($available) {
                $availableCount++;
            }
        }

        return [
            'generated_at' => time(),
            'disclaimer'   => 'Reflects which FluxFiles features are enabled for this token. Not a legal or regulatory compliance certification.',
            'summary'      => [
                'enabled_count'   => $enabledCount,
                'available_count' => $availableCount,
                'total_count'     => count($items),
            ],
            'categories'   => array_values(array_unique(array_column(self::TABLE, 'category'))),
            'items'        => $items,
        ];
    }
}
