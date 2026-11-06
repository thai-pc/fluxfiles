<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Everything a paid module's public handler needs from the host, in one value object:
 * the storage, the metadata backend, the signing secret, the licence and a few
 * request helpers. It replaces the `ff_*` globals the handlers used to reach for, so a
 * module depends on this class and not on which file the host happened to include.
 *
 * Hosts whose storage isn't laid out like the standalone app's (WordPress, Laravel) pass
 * their own DiskManager, disk config and metadata repo; the standalone app builds
 * everything from the environment with {@see standalone()}. The DiskManager and disk
 * config are resolved on first use, so a request that is refused at the gate (501/402)
 * never touches the disk config.
 */
final class PublicContext
{
    public const DEFAULT_SECRET = 'change-me-to-random-32-char-string';

    private ?DiskManager $dm;

    /** @var array<string,mixed>|null */
    private ?array $diskConfigs;

    private ?MetadataRepositoryInterface $injectedMeta;

    private ?MetadataRepositoryInterface $meta = null;

    public readonly string $secret;

    public readonly LicenseCheckInterface $license;

    /** True when the host supplied its own disks (so the standalone env switches don't apply). */
    public readonly bool $hostInjected;

    /**
     * @param array<string,mixed>|null $diskConfigs
     */
    public function __construct(
        ?DiskManager $dm,
        ?array $diskConfigs,
        ?MetadataRepositoryInterface $injectedMeta,
        string $secret,
        LicenseCheckInterface $license,
        bool $hostInjected = false
    ) {
        $this->dm = $dm;
        $this->diskConfigs = $diskConfigs;
        $this->injectedMeta = $injectedMeta;
        $this->secret = $secret;
        $this->license = $license;
        $this->hostInjected = $hostInjected;
    }

    /** The standalone app's context: env secret, `DiskConfig::load()`, the registry's licence. */
    public static function standalone(?LicenseCheckInterface $license = null): self
    {
        return new self(null, null, null, (string) ($_ENV['FLUXFILES_SECRET'] ?? ''), $license ?? ModuleRegistry::license(), false);
    }

    /** @return array<string,mixed> */
    public function diskConfigs(): array
    {
        return $this->diskConfigs ??= DiskConfig::load();
    }

    public function dm(): DiskManager
    {
        return $this->dm ??= new DiskManager($this->diskConfigs());
    }

    /** The host-injected metadata repo, or null (see {@see meta()}). */
    public function injectedMeta(): ?MetadataRepositoryInterface
    {
        return $this->injectedMeta;
    }

    /** Is the signing secret set to something other than the shipped placeholder? */
    public function secretConfigured(): bool
    {
        return $this->secret !== '' && $this->secret !== self::DEFAULT_SECRET;
    }

    /**
     * The metadata repository a public request must use: the host's, else the one the
     * operator configured (`FLUXFILES_STORAGE_BACKEND=db` → the self-hosted DB, else JSON in
     * storage) — built like index.php builds `$metaRepo`, minus the auto-migrate (a public
     * request is no place to run migrations). A host that injects its own DiskManager keeps
     * the JSON handler unless it injects a repo too: its env is not the standalone app's.
     */
    public function meta(): MetadataRepositoryInterface
    {
        return $this->meta ??= self::resolveMeta($this->dm(), $this->injectedMeta, $this->hostInjected);
    }

    public static function resolveMeta(DiskManager $dm, ?MetadataRepositoryInterface $injected = null, bool $hostInjectedDisks = false): MetadataRepositoryInterface
    {
        if ($injected !== null) {
            return $injected;
        }
        if (!$hostInjectedDisks && ($_ENV['FLUXFILES_STORAGE_BACKEND'] ?? 'json') === 'db') {
            return new Db\DbMetadataHandler(Db\Connection::fromEnv(), $dm);
        }
        return new StorageMetadataHandler($dm);
    }

    /**
     * One string request parameter, safe against an array-shaped one. `?token[]=a` makes
     * `$_GET['token']` an array and `(string) $array` warns (a path disclosure with
     * display_errors on); anything non-scalar collapses to the default instead.
     *
     * @param array<string,mixed> $src
     */
    public function param(array $src, string $key, string $default = ''): string
    {
        $v = $src[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    /**
     * Best-effort audit line for an anonymous public access. These requests carry no main
     * JWT, so the post-route audit in index.php never sees them. Never throws: a failed
     * audit write must not turn a served download or a landed upload into an error.
     */
    public function audit(string $actor, string $action, string $disk, string $key, string $detail): void
    {
        try {
            (new AuditLogStorage($this->meta(), [$disk]))->log($actor, $action, $disk, $key, null, null, $detail);
        } catch (\Throwable $e) {
            Log::warn('[fluxfiles] public ' . $action . ' audit failed: ' . $e->getMessage());
        }
    }

    /**
     * Count one hit against `$bucket` (a 60 s window of `$limit` hits) in the JSON-file
     * limiter. Throws the translated `rate_limited` (429) when the bucket is full.
     */
    public function rateLimit(string $bucket, int $limit): void
    {
        $limit = max(1, $limit);
        RateLimiterFactory::make($limit, $limit, 60)->check($bucket, 'read');
    }

    /**
     * The rate-limit bucket for a share/intake token: its `jti` when the signature
     * verifies, else a per-client-IP bucket. Verifying first bounds the key space to real
     * ids plus client IPs, and still never collapses junk tokens into one global bucket a
     * single visitor could exhaust for everybody.
     */
    public function tokenBucket(#[\SensitiveParameter] string $token): string
    {
        return self::tokenBucketFor($token, $this->secret);
    }

    public static function tokenBucketFor(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $secret): string
    {
        if ($token !== '' && $secret !== '') {
            try {
                $payload = JwtCompat::decode($token, $secret);
                $jti = $payload->jti ?? '';
                if (is_string($jti) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $jti)) {
                    return $jti;
                }
            } catch (\Throwable $e) {
                // invalid / expired / wrong key → the per-IP bucket below
            }
        }
        return substr(hash('sha256', 'unverified|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 24);
    }
}
