<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * The hook wiring every FileManager host needs: quota, stream secret, upload
 * optimizer, AI tagger, version keeper/purger/mover, virus scanner, DLP scanner,
 * webhook dispatcher.
 *
 * index.php and both proxy adapters each built this by hand and drifted (the
 * adapters wired the AI tagger only on `ai_auto_tag`, forgot hooks when a new one
 * landed, kept `?? false` shims for old cores). One implementation means a new
 * hook is wired for every host at once.
 *
 * Two kinds of hook, deliberately wired differently:
 *
 *  - PASSIVE hooks (version purger/mover): wired whenever the module is installed
 *    and licensed, whatever the acting token says — history is written by one
 *    token and outlives it, so a delete by a token WITHOUT `allow_versioning` must
 *    still erase it, and a rename must still re-key it.
 *  - FAIL-CLOSED hooks (virus, DLP): wired ONLY on the token's claim, and the
 *    ModuleRegistry::require() gate runs INSIDE the callback, lazily, on the first
 *    write that needs a scan. That is load-bearing: a tenant with
 *    `allow_virus_scan` but a missing/unlicensed module must get 501/402/403 on
 *    upload instead of silently unscanned files, while plain reads still work.
 *    Resolving the module here instead would either break reads or (if the check
 *    were skipped) fail OPEN. Do not "optimise" the gate out of the closure.
 */
final class FileManagerFactory
{
    /**
     * Wire every hook onto $fm and return it.
     *
     * @param string $streamSecret enables gated-local / `img_base` URLs in list()
     *        (the /stream and /img routes mint tokens with it). Unconditional, as in core.
     * @param AiTagger|null $aiTagger already-built tagger, or null for none. The
     *        host decides the gating: core wires it whenever an AI provider is
     *        configured, the proxies only on `ai_auto_tag`.
     */
    public static function wire(
        FileManager $fm,
        Claims $claims,
        DiskManager $diskManager,
        MetadataRepositoryInterface $metaRepo,
        #[\SensitiveParameter] string $streamSecret,
        ?AiTagger $aiTagger = null
    ): FileManager {
        $fm->setQuotaManager(new QuotaManager($diskManager));
        // Enables gated-local media: file URLs on a `private => true` local disk become
        // tokened /api/fm/stream links, and list() emits img_base for /api/fm/img.
        $fm->setStreamSecret($streamSecret);

        if ($aiTagger !== null) {
            $fm->setAiTagger($aiTagger);
        }

        // On-upload auto-optimize (FREE/core). Wire the optimizer hook when the token
        // asks for it (`auto_optimize`). The module does the work; FileManager records
        // the savings + renames to .webp.
        if ($claims->autoOptimize) {
            $optimizeModule = new OptimizeModule();
            $fm->setUploadOptimizer(static function (string $bytes, int $quality) use ($optimizeModule) {
                return $optimizeModule->optimizeBytes($bytes, $quality);
            });
        }

        // File versioning (paid module). Two halves with different gates:
        //  · housekeeping (purger + mover) runs whenever the module is installed +
        //    licensed, REGARDLESS of this token's claim. History is written by one
        //    token and outlives it — a `/delete` or rename by a token WITHOUT
        //    `allow_versioning` used to leave the history behind under the old path,
        //    where it was restorable after a "permanent" delete and got listed on the
        //    next file created at that path (another user's, even).
        //  · the keeper (new snapshots) only when the token asks (`allow_versioning`),
        //    so the free core and unlicensed tenants keep no versions.
        // The metadata repository is passed through so the module reads the file's
        // owner from the configured backend (DB mode included) — that owner is the
        // identity version history is bound to (see VersioningModule::lineage()).
        if (ModuleRegistry::installed('versioning')
            && LicenseManager::fromEnv()->licensed('versioning')) {
            $versioning = new \FluxFiles\Versioning\VersioningModule();
            if ($claims->allowVersioning) {
                $fm->setVersionKeeper(static function (string $d, string $key, $fs) use ($versioning, $claims, $diskManager, $metaRepo) {
                    $versioning->keep($fs, $key, $claims, $diskManager, $d, $metaRepo);
                });
            }
            // Erase version history on permanent delete, so `/delete` can't be undone via
            // a restore of a version saved before it — see VersioningModule::purge().
            $fm->setVersionPurger(static function (string $d, string $key, $fs) use ($versioning, $diskManager) {
                $versioning->purge($fs, $key, $diskManager, $d);
            });
            // Re-key version history on rename/move so it follows the file instead of
            // being orphaned under the old path's hash. method_exists: an older installed
            // module predates move() — keep/purge still work without it.
            if (method_exists($versioning, 'move')) {
                $fm->setVersionMover(static function (string $d, string $from, string $to, $fs) use ($versioning, $diskManager, $metaRepo) {
                    $versioning->move($fs, $from, $to, $diskManager, $d, $metaRepo);
                });
            }
        }

        // Virus scan (paid module). Wired whenever the token asks for it — and ONLY on the
        // claim, unlike the passive hooks above, because this one is fail-closed: the
        // module/licence gate runs INSIDE the callback (lazily, on the first write that
        // needs a scan) so a tenant with `allow_virus_scan` but no installed/licensed
        // module gets 501/402/403 on upload instead of silently unscanned files. Resolving
        // it here instead would break plain reads for the same tenant.
        if ($claims->allowVirusScan) {
            $fm->setVirusScanner(static function (string $localPath) use ($claims): array {
                /** @var \FluxFiles\Virus\VirusScanModule $virus */
                $virus = ModuleRegistry::require('virus', LicenseManager::fromEnv(), $claims);
                return $virus->scanPath($localPath);
            });
        }

        // DLP / PII scan (paid module, Enterprise bundle). Same lazy-gate-inside-callback
        // contract as virus scanning above: wired only when the token asks for it
        // (`allow_dlp_scan`), and ModuleRegistry::require() resolves install/license/claim
        // on the first eligible write rather than here, so a plain read never pays the
        // gate cost. See docs/design/DLP-PII-REDACTION-DESIGN.md.
        if ($claims->allowDlpScan) {
            $fm->setDlpScanner(static function (string $localPath, string $name) use ($claims): array {
                /** @var \FluxFiles\Dlp\DlpModule $dlp */
                $dlp = ModuleRegistry::require('dlp', LicenseManager::fromEnv(), $claims);
                return $dlp->scanPath($localPath, $claims->dlpEntityTypes, $claims->dlpMinScore);
            });
        }

        return $fm;
    }

    /**
     * Webhook dispatcher (paid module): a signed HTTP POST on file events. Non-null
     * only when the token carries a `webhook_url` + `allow_webhooks` AND the module is
     * installed + licensed. Event-driven = stateless: it fires on the request that
     * caused the event (no scheduler/queue). Best-effort — a webhook failure never
     * breaks the operation. The host decides WHEN to call it (core: after the
     * response is flushed).
     *
     * @return (callable(string $event, array $context):void)|null
     */
    public static function webhookDispatcher(Claims $claims, #[\SensitiveParameter] string $secret): ?callable
    {
        if (!$claims->allowWebhooks || $claims->webhookUrl === ''
            || !ModuleRegistry::installed('webhooks')
            || !LicenseManager::fromEnv()->licensed('webhooks')) {
            return null;
        }
        $webhooks = new \FluxFiles\Webhooks\WebhooksModule();
        return static function (string $event, array $context) use ($webhooks, $claims, $secret) {
            $webhooks->dispatch($claims, $secret, $event, $context);
        };
    }
}
