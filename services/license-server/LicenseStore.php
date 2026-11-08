<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;

/**
 * The vendor customer/license DB (SQLite). This is the stateful back-office — kept
 * OUTSIDE the stateless FluxFiles core. One row per issued license key.
 */
final class LicenseStore
{
    private PDO $db;
    /** @var array<int,array{jti:string,plan:string,email:string}> rows the last cascade changed */
    private array $cascaded = [];
    private bool $inTx = false;   // own flag: PDO::inTransaction() ignores a hand-issued BEGIN on older PHP

    public function __construct(?string $path = null)
    {
        $path = $path ?? (getenv('FLUXFILES_LICENSE_DB') ?: __DIR__ . '/data/licenses.sqlite');
        if ($path !== ':memory:') {
            @mkdir(dirname($path), 0700, true);
        }
        // The DB holds every buyer's email and licence key: create it (and its
        // -wal/-shm files) owner-only, whatever the process umask is.
        $prevUmask = umask(0077);
        try {
            $this->db = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } finally {
            umask($prevUmask);
        }
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA busy_timeout=5000');   // a concurrent writer waits instead of failing
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS licenses (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            jti         TEXT UNIQUE NOT NULL,
            email       TEXT NOT NULL,
            customer    TEXT,
            plan        TEXT,
            edition     TEXT,
            modules     TEXT,
            enforcement TEXT,
            issued      INTEGER,
            expires     INTEGER,
            license_key TEXT NOT NULL,
            gateway     TEXT,
            order_id    TEXT,
            status      TEXT DEFAULT \'active\',
            created_at  INTEGER
        )');
        // Public /pro-download throttling and the per-licence download-link cache (ProDownload).
        // Small, short-lived rows; pruned on write.
        $this->db->exec('CREATE TABLE IF NOT EXISTS rate_hits (bucket TEXT NOT NULL, win INTEGER NOT NULL, n INTEGER NOT NULL, PRIMARY KEY (bucket, win))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS dl_cache (jti TEXT PRIMARY KEY, url TEXT NOT NULL, version TEXT, expires_at INTEGER NOT NULL)');
        // Subscriptions an admin revoked/refunded by id. A later order.paid of the same
        // subscription is still issued (the buyer paid) but stays in that status, so
        // revoke-by-subscription outlives the next billing cycle.
        $this->db->exec('CREATE TABLE IF NOT EXISTS revoked_subscriptions (subscription_id TEXT PRIMARY KEY, status TEXT NOT NULL, at INTEGER NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_email ON licenses(email)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_order ON licenses(gateway, order_id)');

        // When the key was actually delivered to the buyer. NULL means "issued but the
        // buyer has not been told", which is the state a mail outage leaves behind and
        // the reason the webhook can retry delivery without minting a second licence.
        // Added after the first deploys, hence the guarded ALTER rather than a column
        // in the CREATE above.
        $cols = $this->db->query('PRAGMA table_info(licenses)')->fetchAll(\PDO::FETCH_COLUMN, 1) ?: [];
        if (!in_array('mailed_at', $cols, true)) {
            $this->db->exec('ALTER TABLE licenses ADD COLUMN mailed_at INTEGER');
        }
        // Polar redirects the buyer with {CHECKOUT_ID}; the webhook only knows the
        // ORDER id. Storing both is what lets the success page find the licence.
        if (!in_array('checkout_id', $cols, true)) {
            $this->db->exec('ALTER TABLE licenses ADD COLUMN checkout_id TEXT');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_checkout ON licenses(checkout_id)');
        }
        // The smallest/worst threshold-bucket already notified by the renewal-reminder
        // cron (one of the configured day thresholds as a string, or "grace"/"expired"
        // for the two non-day touch points). NULL means never reminded. This is the
        // idempotency key for needingReminder() — see LICENSE-EXPIRY-NOTIFICATIONS-
        // DESIGN.md §6.
        if (!in_array('reminder_stage', $cols, true)) {
            $this->db->exec('ALTER TABLE licenses ADD COLUMN reminder_stage TEXT');
        }
        // Mirrors LicenseSigner::mint()'s graceDays option (default 14), persisted at
        // issuance so the reminder job can compute the grace-window boundary exactly
        // instead of hardcoding it and silently drifting from a future custom plan.
        if (!in_array('grace_days', $cols, true)) {
            $this->db->exec('ALTER TABLE licenses ADD COLUMN grace_days INTEGER DEFAULT 14');
        }
        // Polar's customer id (stable per buyer, unlike the free-text checkout email) and
        // the row a renewal extended. NULL on every pre-existing row: both are only
        // consulted when present, so old data keeps working unchanged.
        if (!in_array('customer_id', $cols, true)) {
            $this->addColumn('customer_id', 'TEXT');
        }
        if (!in_array('renews_jti', $cols, true)) {
            $this->addColumn('renews_jti', 'TEXT');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_renews ON licenses(renews_jti)');
        }
        // Polar's subscription id and the order's billing_reason (from order.paid). The
        // subscription id is what links a monthly buyer's keys for POST /refresh and for
        // superseding; NULL on every row that predates it (backfill-subscription-ids.php
        // fills them in). billing_reason '' marks a row the backfill looked up.
        if (!in_array('subscription_id', $cols, true)) {
            $this->addColumn('subscription_id', 'TEXT');
        }
        if (!in_array('billing_reason', $cols, true)) {
            $this->addColumn('billing_reason', 'TEXT');
        }
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_subscription ON licenses(subscription_id)');
        // One licence per (gateway, order): record()'s check-then-insert alone lets two
        // concurrent deliveries of the same order both mint. A database that already
        // holds such a duplicate can't take the index — keep running and say so.
        try {
            $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_order ON licenses(gateway, order_id) WHERE order_id != ''");
        } catch (\PDOException $e) {
            error_log('license store: duplicate (gateway, order_id) rows exist; uniq_order not created — ' . $e->getMessage());
        }
    }

    /** ADD COLUMN that survives a concurrent first request adding it at the same moment. */
    private function addColumn(string $name, string $type): void
    {
        try {
            $this->db->exec("ALTER TABLE licenses ADD COLUMN {$name} {$type}");
        } catch (\PDOException $e) {
            $cols = $this->db->query('PRAGMA table_info(licenses)')->fetchAll(\PDO::FETCH_COLUMN, 1) ?: [];
            if (!in_array($name, $cols, true)) {
                throw $e;
            }
        }
    }

    /** Mark the licence as delivered. Returns false when the row is gone. */
    public function markMailed(string $jti, ?int $at = null): bool
    {
        $s = $this->db->prepare('UPDATE licenses SET mailed_at = ? WHERE jti = ?');
        $s->execute([$at ?? time(), $jti]);
        return $s->rowCount() > 0;
    }

    /**
     * Record an issued license. Idempotent on (gateway, order_id): a repeated webhook
     * for the same order returns the existing row instead of minting a duplicate.
     *
     * @param array<string,mixed> $rec
     * @return array<string,mixed> the stored row
     */
    public function record(array $rec): array
    {
        if (!empty($rec['gateway']) && !empty($rec['order_id'])) {
            $existing = $this->findByOrder((string) $rec['gateway'], (string) $rec['order_id']);
            if ($existing !== null) {
                return $existing;
            }
        }
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO licenses
            (jti,email,customer,plan,edition,modules,enforcement,issued,expires,license_key,gateway,order_id,checkout_id,status,created_at,grace_days,customer_id,renews_jti,subscription_id,billing_reason)
            VALUES (:jti,:email,:customer,:plan,:edition,:modules,:enforcement,:issued,:expires,:license_key,:gateway,:order_id,:checkout_id,:status,:created_at,:grace_days,:customer_id,:renews_jti,:subscription_id,:billing_reason)');
        $row = [
            'jti'         => (string) $rec['jti'],
            'email'       => (string) $rec['email'],
            'customer'    => (string) ($rec['customer'] ?? ''),
            'plan'        => (string) ($rec['plan'] ?? ''),
            'edition'     => (string) ($rec['edition'] ?? ''),
            'modules'     => implode(',', (array) ($rec['modules'] ?? [])),
            'enforcement' => (string) ($rec['enforcement'] ?? 'perpetual'),
            'issued'      => (int) ($rec['issued'] ?? time()),
            'expires'     => isset($rec['expires']) ? (int) $rec['expires'] : null,
            'license_key' => (string) $rec['license_key'],
            'gateway'     => (string) ($rec['gateway'] ?? 'manual'),
            'order_id'    => (string) ($rec['order_id'] ?? ''),
            'checkout_id' => (string) ($rec['checkout_id'] ?? ''),
            'status'      => (string) ($rec['status'] ?? 'active'),
            'created_at'  => time(),
            // Mirrors LicenseSigner::mint()'s own graceDays default (14) — see the
            // migrate() comment above for why this is persisted separately from the
            // signed key payload.
            'grace_days'  => (int) ($rec['grace_days'] ?? 14),
            'customer_id' => ($rec['customer_id'] ?? '') !== '' ? (string) $rec['customer_id'] : null,
            'renews_jti'  => ($rec['renews_jti'] ?? '') !== '' ? (string) $rec['renews_jti'] : null,
            'subscription_id' => ($rec['subscription_id'] ?? '') !== '' ? (string) $rec['subscription_id'] : null,
            'billing_reason'  => ($rec['billing_reason'] ?? '') !== '' ? (string) $rec['billing_reason'] : null,
        ];
        $stmt->execute($row);
        if ($stmt->rowCount() === 0 && $row['order_id'] !== '') {
            // Lost a race with a concurrent delivery of the same order (uniq_order):
            // the licence that won is the one this order has.
            $winner = $this->findByOrder($row['gateway'], $row['order_id']);
            if ($winner !== null) {
                return $winner;
            }
        }
        return $this->findByJti($row['jti']) ?? $row;
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $key): ?array
    {
        if ($key === '' || strlen($key) > 4096) {
            return null;
        }
        $s = $this->db->prepare('SELECT * FROM licenses WHERE license_key = ?');
        $s->execute([$key]);
        $r = $s->fetch();
        return $r === false ? null : $r;
    }

    /**
     * Fixed-window counter. Counts the call, returns true while it is within $limit per
     * $periodSec for $bucket (e.g. "ip:1.2.3.4"). Atomic enough for a throttle: one UPSERT.
     */
    public function rateHit(string $bucket, int $periodSec, int $limit, ?int $now = null): bool
    {
        $now ??= time();
        $periodSec = max(1, $periodSec);
        // `win` is the window's START as a unix timestamp, so every bucket's rows are
        // comparable no matter what period their caller uses. (It used to be intdiv(now, period):
        // the sweep below then compared one caller's window numbers with another's, and a
        // /pro-download call (600 s) wiped every hourly /refresh window.) Rows written by the
        // old format are small integers, far below the sweep horizon: the first sweep after
        // deploy drops them, which only forgives the current window's hits once.
        $win = intdiv($now, $periodSec) * $periodSec;
        $this->db->prepare('INSERT INTO rate_hits (bucket, win, n) VALUES (?, ?, 1) ON CONFLICT(bucket, win) DO UPDATE SET n = n + 1')->execute([$bucket, $win]);
        $s = $this->db->prepare('SELECT n FROM rate_hits WHERE bucket = ? AND win = ?');
        $s->execute([$bucket, $win]);
        if (random_int(1, 50) === 1) {
            // Prune windows that started more than 2 h ago: longer than any period used (<= 1 h).
            $this->db->prepare('DELETE FROM rate_hits WHERE win < ?')->execute([$now - 7200]);
        }
        return (int) $s->fetchColumn() <= $limit;
    }

    /** A cached download link for this licence that is still valid for at least $minLeft seconds. @return array{url:string,version:string,expires_at:int}|null */
    public function cachedLink(string $jti, int $minLeft, ?int $now = null): ?array
    {
        $now ??= time();
        $s = $this->db->prepare('SELECT url, version, expires_at FROM dl_cache WHERE jti = ? AND expires_at >= ?');
        $s->execute([$jti, $now + $minLeft]);
        $r = $s->fetch();
        return $r === false ? null : ['url' => (string) $r['url'], 'version' => (string) $r['version'], 'expires_at' => (int) $r['expires_at']];
    }

    public function cacheLink(string $jti, string $url, string $version, int $expiresAt): void
    {
        $this->db->prepare('INSERT INTO dl_cache (jti, url, version, expires_at) VALUES (?, ?, ?, ?) ON CONFLICT(jti) DO UPDATE SET url = excluded.url, version = excluded.version, expires_at = excluded.expires_at')
            ->execute([$jti, $url, $version, $expiresAt]);
        $this->db->prepare('DELETE FROM dl_cache WHERE expires_at < ?')->execute([time()]);
    }

    /** @return array<string,mixed>|null */
    public function findByJti(string $jti): ?array
    {
        $s = $this->db->prepare('SELECT * FROM licenses WHERE jti = ?');
        $s->execute([$jti]);
        $r = $s->fetch();
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null */
    public function findByOrder(string $gateway, string $orderId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM licenses WHERE gateway = ? AND order_id = ?');
        $s->execute([$gateway, $orderId]);
        $r = $s->fetch();
        return $r === false ? null : $r;
    }

    /** @return array<int,array<string,mixed>> */
    /** Find by the checkout id the buyer's success page was redirected with. */
    public function findByCheckout(string $checkoutId): ?array
    {
        if ($checkoutId === '') { return null; }
        $s = $this->db->prepare('SELECT * FROM licenses WHERE checkout_id = ? LIMIT 1');
        $s->execute([$checkoutId]);
        return $s->fetch() ?: null;
    }

    public function findByEmail(string $email): array
    {
        $s = $this->db->prepare('SELECT * FROM licenses WHERE email = ? ORDER BY created_at DESC');
        $s->execute([$email]);
        return $s->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function all(int $limit = 200, int $offset = 0): array
    {
        $s = $this->db->prepare('SELECT * FROM licenses ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->bindValue(2, $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Licences that were issued but never reached the buyer — the backlog a mail
     * outage leaves behind. Revoked/refunded rows are excluded: re-sending a key to
     * someone whose order was reversed is worse than not sending it. Manual rows
     * (POST /issue: comp, enterprise, test keys) are excluded too: /issue never mails,
     * so a NULL mailed_at there is not a delivery failure and /redeliver must not
     * e-mail those keys.
     *
     * @return array<int,array<string,mixed>>
     */
    public function undelivered(int $limit = 100): array
    {
        $s = $this->db->prepare(
            "SELECT * FROM licenses WHERE mailed_at IS NULL AND status = 'active' AND gateway IS NOT 'manual'
             ORDER BY created_at ASC LIMIT ?"
        );
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /** Mark a license revoked/refunded (status only — offline verify can't enforce it;
     *  it gates the UPDATE channel + is your record of truth). */
    public function setStatus(string $jti, string $status): bool
    {
        $s = $this->db->prepare('UPDATE licenses SET status = ? WHERE jti = ?');
        $s->execute([$status, $jti]);
        return $s->rowCount() > 0;
    }

    /**
     * Update-channel eligibility. `superseded` only retires a row from renewal
     * reminders; it must not revoke a still-in-term perpetual key when a customer
     * renews early and has not yet installed their replacement key.
     */
    public function isUpdateEligible(string $jti): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM licenses WHERE jti = ? AND status IN ('active', 'superseded') LIMIT 1");
        $s->execute([$jti]);
        return $s->fetchColumn() !== false;
    }

    /**
     * Licences due for a renewal-approaching or expiry/grace reminder — the
     * cron-driven counterpart to undelivered() above. Only `active` rows (never
     * re-solicit revoked/refunded, same exclusion undelivered() applies) with an
     * actual expiry (`expires IS NOT NULL` — a lifetime licence has nothing to
     * remind about, structurally excluded here rather than as a special case
     * below).
     *
     * The SQL only narrows to that filterable subset; the threshold/idempotency
     * bucketing is plain PHP on top — simpler to read and to get right than folding
     * the day-math into SQLite for a service this small.
     *
     * Each returned row carries an extra '_reminder_bucket' key: the single most
     * urgent (smallest) bucket that has newly fired for that row — one of
     * $thresholdDays (as a string, e.g. "7") while still before `expires`, or
     * "grace"/"expired" once past it. A row is included at most once per call, even
     * if it is late enough that several thresholds are technically crossed at once.
     *
     * @param array<int,int> $thresholdDays day-out thresholds, e.g. [30,14,7,1]
     * @return array<int,array<string,mixed>>
     */
    public function needingReminder(array $thresholdDays, int $now): array
    {
        $s = $this->db->query(
            "SELECT * FROM licenses WHERE status = 'active' AND expires IS NOT NULL ORDER BY created_at ASC"
        );
        $rows = $s->fetchAll();

        $due = [];
        foreach ($rows as $row) {
            $expires = (int) $row['expires'];
            $graceDays = ($row['grace_days'] ?? null) !== null ? (int) $row['grace_days'] : 14;
            $stage = $row['reminder_stage'] ?? null;

            if ($now <= $expires) {
                // Still before expiry: find the most urgent configured threshold the
                // remaining days have crossed, and only report it if that's more
                // urgent than whatever was last reminded (or nothing yet).
                $daysLeft = (int) floor(($expires - $now) / 86400);
                $crossed = [];
                foreach ($thresholdDays as $t) {
                    $t = (int) $t;
                    if ($daysLeft <= $t) {
                        $crossed[] = $t;
                    }
                }
                if (empty($crossed)) {
                    continue;
                }
                $bucket = min($crossed);
                $stageInt = ($stage !== null && $stage !== '' && is_numeric($stage)) ? (int) $stage : null;
                if ($stageInt === null || $bucket < $stageInt) {
                    $row['_reminder_bucket'] = (string) $bucket;
                    $due[] = $row;
                }
                continue;
            }

            // Past expiry: the two non-day touch points, each fired at most once.
            $graceEnd = $expires + $graceDays * 86400;
            if ($now <= $graceEnd) {
                if ($stage !== 'grace' && $stage !== 'expired') {
                    $row['_reminder_bucket'] = 'grace';
                    $due[] = $row;
                }
            } elseif ($stage !== 'expired') {
                $row['_reminder_bucket'] = 'expired';
                $due[] = $row;
            }
        }

        return $due;
    }

    /** Record that a reminder for $bucket was sent, so a later run in the same or a
     *  better bucket doesn't re-fire it. Returns false when the row is gone. */
    public function markReminderSent(string $jti, string $bucket): bool
    {
        $s = $this->db->prepare('UPDATE licenses SET reminder_stage = ? WHERE jti = ?');
        $s->execute([$bucket, $jti]);
        return $s->rowCount() > 0;
    }

    /**
     * A recurring (Polar) subscription fires a NEW webhook with a NEW order_id on
     * every renewal, so `record()`'s (gateway, order_id) idempotency mints a fresh
     * row each cycle instead of updating one — by design, since the idempotency key
     * has to stay order-scoped for a repeat webhook of the SAME order to be a no-op.
     * The side effect: the previous row for that customer+plan is left `active`
     * forever, with its own now-stale `expires`, so it keeps surfacing in
     * needingReminder() (an "expired" nag) right alongside the brand new row (a
     * "renews soon" nag) — a self-contradicting pair every billing cycle.
     *
     * Call this right after storing the new row: it retires every OTHER `active` row
     * for the same (buyer email, plan) — gateway rows only — so only the newest stays
     * eligible for reminders
     * (and stops the admin/licenses list from showing stale duplicate "active" rows
     * for the same customer). Matched on the lower-cased EMAIL, never the display name (anyone can call
     * themselves "John Smith"). No-op when email or plan is blank, since either
     * would otherwise match too broadly across unrelated purchases.
     *
     * @return int number of rows superseded
     */
    public function supersedeActiveForBuyerPlan(string $email, string $plan, string $exceptJti): int
    {
        if (trim($email) === '' || $plan === '') {
            return 0;
        }
        $s = $this->db->prepare(
            "UPDATE licenses SET status = 'superseded'
             WHERE lower(email) = lower(?) AND plan = ? AND status = 'active' AND gateway != 'manual' AND jti != ?"
        );
        $s->execute([trim($email), $plan, $exceptJti]);
        return $s->rowCount();
    }

    /**
     * (buyer email, plan) pairs with MORE THAN ONE `active` gateway row — the backlog
     * supersedeActiveForBuyerPlan() prevents going forward but can't retroactively
     * clean up, since it only runs on the NEXT issue() for a pair. Used by the
     * one-off backfill-supersede-duplicates.php script. Manual rows are left out,
     * matching supersedeActiveForBuyerPlan().
     *
     * @return array<int,array{email:string,plan:string,cnt:int}>
     */
    public function duplicateActiveBuyerPlans(): array
    {
        $s = $this->db->query(
            "SELECT lower(email) AS email, plan, COUNT(*) AS cnt FROM licenses
             WHERE status = 'active' AND gateway != 'manual' AND email != '' AND plan != ''
             GROUP BY lower(email), plan HAVING COUNT(*) > 1"
        );
        return $s->fetchAll();
    }

    /**
     * `active` gateway rows for one (buyer email, plan) pair, newest-issued first —
     * so callers can keep rows[0] and supersede the rest.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeRowsForBuyerPlan(string $email, string $plan): array
    {
        $s = $this->db->prepare(
            "SELECT * FROM licenses WHERE lower(email) = lower(?) AND plan = ? AND status = 'active'
             AND gateway != 'manual' ORDER BY issued DESC, created_at DESC"
        );
        $s->execute([trim($email), $plan]);
        return $s->fetchAll();
    }

    /**
     * Run $fn inside one write transaction (BEGIN IMMEDIATE: a concurrent writer waits
     * for the lock instead of reading the same snapshot). Used by renewals, whose
     * lookup -> mint -> record -> supersede must be atomic or two orders can extend the
     * same base once and leave a broken chain.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn)
    {
        if ($this->inTx) {
            return $fn();
        }
        $this->db->exec('BEGIN IMMEDIATE');
        $this->inTx = true;
        try {
            $r = $fn();
            $this->db->exec('COMMIT');
            return $r;
        } catch (\Throwable $e) {
            try { $this->db->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $e;
        } finally {
            $this->inTx = false;
        }
    }

    /**
     * Candidate licences a renewal can extend: the buyer's `active` rows (by email,
     * any gateway) in one of $plans that HAVE an expiry, newest first. A legacy
     * no-expiry key already has lifetime updates, so it is not renewable and must not
     * shadow a newer dated licence. When $customerId (Polar's) is known, rows with a
     * DIFFERENT known customer id are excluded, and rows with the same id win over
     * rows that predate customer ids (matched by email alone).
     *
     * @param array<int,string> $plans
     * @return array<string,mixed>|null
     */
    public function activeForBuyerPlans(string $email, array $plans, string $customerId = ''): ?array
    {
        if (trim($email) === '' || $plans === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($plans), '?'));
        $s = $this->db->prepare(
            "SELECT * FROM licenses WHERE lower(email) = lower(?) AND status = 'active' AND expires IS NOT NULL AND plan IN ({$in})
             ORDER BY expires DESC, created_at DESC"
        );
        $s->execute(array_merge([trim($email)], array_values($plans)));
        $rows = $s->fetchAll();
        $same = []; $unknown = [];
        foreach ($rows as $r) {
            $cid = (string) ($r['customer_id'] ?? '');
            if ($customerId !== '' && $cid === $customerId) { $same[] = $r; }
            elseif ($cid === '' || $customerId === '') { $unknown[] = $r; }
        }
        return ($same[0] ?? $unknown[0] ?? null);
    }

    // ── Subscription linkage (POST /refresh) ────────────────────────────────

    /**
     * Retire every OTHER `active` row of one Polar subscription. The subscription-keyed
     * counterpart of supersedeActiveForBuyerPlan(): two subscriptions of one buyer on the
     * same plan no longer retire each other's keys.
     */
    public function supersedeActiveForSubscription(string $subscriptionId, string $exceptJti): int
    {
        if ($subscriptionId === '') {
            return 0;
        }
        $s = $this->db->prepare("UPDATE licenses SET status = 'superseded' WHERE subscription_id = ? AND status = 'active' AND jti != ?");
        $s->execute([$subscriptionId, $exceptJti]);
        return $s->rowCount();
    }

    /**
     * Apply a status to every row of one subscription, so its keys stop refreshing (and,
     * for revoked/refunded, stop being update-eligible). revoked/refunded touch only
     * active/superseded rows (an earlier refund is not rewritten as a revoke). `active`
     * un-revokes: the newest-issued row becomes active again, the rest superseded.
     *
     * @return int rows changed
     */
    public function setStatusForSubscription(string $subscriptionId, string $status): int
    {
        if ($subscriptionId === '') {
            return 0;
        }
        return $this->transaction(function () use ($subscriptionId, $status): int {
            if ($status === 'active') {
                $this->db->prepare('DELETE FROM revoked_subscriptions WHERE subscription_id = ?')->execute([$subscriptionId]);
                // Only `revoked` rows come back: a `refunded` row stays refunded. The newest
                // non-refunded row becomes the active head, the revived older ones superseded.
                $q = $this->db->prepare("SELECT jti, status FROM licenses WHERE subscription_id = ? AND status != 'refunded' ORDER BY issued DESC, id DESC");
                $q->execute([$subscriptionId]);
                $n = 0;
                $up = $this->db->prepare("UPDATE licenses SET status = ? WHERE jti = ? AND status = 'revoked'");
                foreach ($q->fetchAll() as $i => $r) {
                    if ($r['status'] !== 'revoked') { continue; }
                    $up->execute([$i === 0 ? 'active' : 'superseded', $r['jti']]);
                    $n += $up->rowCount();
                }
                return $n;
            }
            $this->db->prepare('INSERT INTO revoked_subscriptions (subscription_id, status, at) VALUES (?, ?, ?) ON CONFLICT(subscription_id) DO UPDATE SET status = excluded.status, at = excluded.at')
                ->execute([$subscriptionId, $status, time()]);
            $s = $this->db->prepare("UPDATE licenses SET status = ? WHERE subscription_id = ? AND status IN ('active','superseded')");
            $s->execute([$status, $subscriptionId]);
            return $s->rowCount();
        });
    }

    /** The status an admin revoked this subscription with ('revoked'/'refunded'), or null when it is not revoked. */
    public function revokedSubscriptionStatus(string $subscriptionId): ?string
    {
        if ($subscriptionId === '') {
            return null;
        }
        $s = $this->db->prepare('SELECT status FROM revoked_subscriptions WHERE subscription_id = ?');
        $s->execute([$subscriptionId]);
        $r = $s->fetchColumn();
        return $r === false ? null : (string) $r;
    }

    /**
     * Retire the buyer's `active` legacy Polar rows (no subscription id yet) for this plan,
     * with a compatible customer id (equal, or either unknown). A new subscription-linked key
     * is the newest of what those legacy rows were; without this they linger as `active` and
     * the reminder cron mails a paying customer "your subscription lapsed".
     */
    public function supersedeLegacyForBuyerPlan(string $email, string $plan, string $customerId, string $exceptJti): int
    {
        if (trim($email) === '' || $plan === '') {
            return 0;
        }
        $s = $this->db->prepare(
            "UPDATE licenses SET status = 'superseded'
             WHERE lower(email) = lower(?) AND plan = ? AND status = 'active' AND gateway = 'polar'
               AND subscription_id IS NULL AND jti != ?
               AND (? = '' OR customer_id IS NULL OR customer_id = '' OR customer_id = ?)"
        );
        $s->execute([trim($email), $plan, $exceptJti, $customerId, $customerId]);
        return $s->rowCount();
    }

    /**
     * Keep only the newest-issued live row of a subscription `active`: the rest of its
     * `active` rows become `superseded`. Idempotent; used by the backfill once ids are known.
     */
    public function supersedeOlderInSubscription(string $subscriptionId): int
    {
        $s = $this->db->prepare(
            "SELECT jti FROM licenses WHERE subscription_id = ? AND status IN ('active','superseded') ORDER BY issued DESC, id DESC LIMIT 1"
        );
        $s->execute([$subscriptionId]);
        $head = $s->fetchColumn();
        return $head === false ? 0 : $this->supersedeActiveForSubscription($subscriptionId, (string) $head);
    }

    /** @return string[] subscription ids that have more than one `active` row */
    public function subscriptionsWithMultipleActive(): array
    {
        return $this->db->query("SELECT subscription_id FROM licenses WHERE subscription_id IS NOT NULL AND status = 'active' GROUP BY subscription_id HAVING COUNT(*) > 1")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /** Highest `issued` among a subscription's rows (0 when none): the floor for the next key's `issued`. */
    public function maxIssuedForSubscription(string $subscriptionId): int
    {
        $s = $this->db->prepare('SELECT MAX(issued) FROM licenses WHERE subscription_id = ?');
        $s->execute([$subscriptionId]);
        return (int) $s->fetchColumn();
    }

    /** Highest `issued` among a buyer's gateway rows for one plan (0 when none). */
    public function maxIssuedForBuyerPlan(string $email, string $plan): int
    {
        $s = $this->db->prepare("SELECT MAX(issued) FROM licenses WHERE lower(email) = lower(?) AND plan = ? AND gateway != 'manual'");
        $s->execute([trim($email), $plan]);
        return (int) $s->fetchColumn();
    }

    /**
     * Gateway rows the subscription-id backfill still has to look up: Polar rows with no
     * subscription id that no earlier run (or webhook) classified via billing_reason.
     *
     * @return array<int,array<string,mixed>>
     */
    public function polarRowsNeedingSubscription(int $limit = 5000): array
    {
        $s = $this->db->prepare("SELECT jti, order_id, plan FROM licenses WHERE gateway = 'polar' AND subscription_id IS NULL AND billing_reason IS NULL AND order_id != '' ORDER BY id ASC LIMIT ?");
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /** Record what the backfill learned (an empty $subscriptionId leaves it NULL; billing_reason '' = looked up). */
    public function setSubscription(string $jti, string $subscriptionId, string $billingReason): bool
    {
        $s = $this->db->prepare("UPDATE licenses SET subscription_id = COALESCE(subscription_id, ?), billing_reason = COALESCE(billing_reason, ?) WHERE jti = ?");
        $s->execute([$subscriptionId !== '' ? $subscriptionId : null, $billingReason, $jti]);
        return $s->rowCount() > 0;
    }

    /**
     * What POST /refresh may hand the holder of $presented (a licences row).
     *
     * Returns ['outcome' => 'refused'|'current'|'refreshed', 'row' => newest row (refreshed
     * only), 'reason' => ops-log word]. The caller answers every 'refused' identically.
     *
     * Candidate set: the same subscription_id; else the renews_jti descendants (one-time
     * lifetime + updates renewals); else, for a legacy Polar row with neither, the buyer's
     * rows for the same plan with a compatible customer id, unless those carry two or more
     * distinct subscription ids (ambiguous: refusing beats crossing two subscriptions).
     * A manual row with no chain only ever has itself. A candidate must be
     * active/superseded, strictly newer `issued`, and `expires` no earlier than the
     * presented key's, so a refresh can never downgrade. If the chain's newest row of any
     * status is revoked/refunded, the whole chain is dead (a refunded latest order, or
     * revoke-by-subscription).
     *
     * @param array<string,mixed> $presented
     * @return array{outcome:string,row:?array<string,mixed>,reason:string}
     */
    public function newestRefreshable(array $presented, ?int $now = null, int $maxStaleDays = 90): array
    {
        $now ??= time();
        $no = static fn (string $why): array => ['outcome' => 'refused', 'row' => null, 'reason' => $why];

        if (!in_array((string) ($presented['status'] ?? ''), ['active', 'superseded'], true)) {
            return $no('status');
        }
        if (trim((string) ($presented['modules'] ?? '')) === '' || (string) ($presented['edition'] ?? '') === 'support') {
            return $no('support');
        }
        if (($presented['expires'] ?? null) === null) {
            return $no('no-expiry');
        }
        $expires = (int) $presented['expires'];
        $grace = ($presented['grace_days'] ?? null) !== null ? (int) $presented['grace_days'] : 14;
        if ($now > $expires + $grace * 86400 + max(0, $maxStaleDays) * 86400) {
            return $no('stale');
        }

        $jti = (string) $presented['jti'];
        $renewChain = false;
        $sid = (string) ($presented['subscription_id'] ?? '');
        if ($sid !== '') {
            $q = $this->db->prepare('SELECT * FROM licenses WHERE subscription_id = ?');
            $q->execute([$sid]);
            $chain = $q->fetchAll();
        } else {
            $chain = $this->renewalDescendants($jti);
            $renewChain = $chain !== [];
            // email+plan fallback: ONLY for unclassified legacy rows (no subscription id and
            // no billing_reason). A classified one-time purchase (two `lifetime` orders by one
            // buyer) must never be offered another order's key.
            if ($chain === [] && (string) ($presented['gateway'] ?? '') === 'polar' && empty($presented['renews_jti'])
                && ($presented['billing_reason'] ?? null) === null
                && !isset((Plans::get((string) ($presented['plan'] ?? '')) ?? [])['renews'])) {
                $q = $this->db->prepare("SELECT * FROM licenses WHERE gateway = 'polar' AND lower(email) = lower(?) AND plan = ?");
                $q->execute([trim((string) $presented['email']), (string) $presented['plan']]);
                $cid = (string) ($presented['customer_id'] ?? '');
                $chain = [];
                $sids = [];
                foreach ($q->fetchAll() as $r) {
                    $rc = (string) ($r['customer_id'] ?? '');
                    if ($cid !== '' && $rc !== '' && $rc !== $cid) { continue; }
                    if ((string) ($r['subscription_id'] ?? '') === '' && ($r['billing_reason'] ?? null) !== null) { continue; }
                    $chain[] = $r;
                    if ((string) ($r['subscription_id'] ?? '') !== '') { $sids[(string) $r['subscription_id']] = true; }
                }
                if (count($sids) >= 2) {
                    return $no('ambiguous');
                }
            }
        }

        // The chain's newest row, whatever its status: if that is revoked/refunded the chain is dead.
        $newest = null;
        foreach ($chain as $r) {
            // renews_jti chains: a cancelled tail (R2 refunded) does not kill the chain, the
            // cascade already brought R1 back; the presented key just refreshes to R1.
            if ($renewChain && in_array((string) $r['status'], ['revoked', 'refunded'], true)) { continue; }
            if ($newest === null || (int) $r['issued'] > (int) $newest['issued']
                || ((int) $r['issued'] === (int) $newest['issued'] && (int) $r['id'] > (int) $newest['id'])) {
                $newest = $r;
            }
        }
        if ($newest !== null && (int) $newest['issued'] >= (int) $presented['issued']
            && in_array((string) $newest['status'], ['revoked', 'refunded'], true)) {
            return $no('chain-dead');
        }

        $best = null;
        foreach ($chain as $r) {
            if (!in_array((string) $r['status'], ['active', 'superseded'], true)) { continue; }
            if ($r['expires'] === null || (int) $r['issued'] <= (int) $presented['issued'] || (int) $r['expires'] < $expires) { continue; }
            if (trim((string) $r['modules']) === '') { continue; }
            if ($best === null || (int) $r['issued'] > (int) $best['issued']) { $best = $r; }
        }
        return $best === null
            ? ['outcome' => 'current', 'row' => null, 'reason' => 'newest']
            : ['outcome' => 'refreshed', 'row' => $best, 'reason' => 'newer'];
    }

    /** Every row reachable forward over renews_jti from $jti (BFS), excluding $jti itself. @return array<int,array<string,mixed>> */
    private function renewalDescendants(string $jti): array
    {
        $out = []; $queue = [$jti]; $seen = [$jti];
        $kids = $this->db->prepare('SELECT * FROM licenses WHERE renews_jti = ?');
        while ($queue) {
            $cur = array_shift($queue);
            $kids->execute([$cur]);
            foreach ($kids->fetchAll() as $k) {
                if (in_array($k['jti'], $seen, true)) { continue; }
                $seen[] = $k['jti']; $queue[] = $k['jti']; $out[] = $k;
            }
        }
        return $out;
    }

    /**
     * Rows the last setStatusCascade() changed besides the one named: renewals whose OWN
     * order was never refunded/revoked, so the operator may need to reissue them.
     *
     * @return array<int,array{jti:string,plan:string,email:string}>
     */
    public function lastCascade(): array
    {
        return $this->cascaded;
    }

    /** Retire exactly one row (the licence a renewal extended). No-op unless `active`. */
    public function supersedeJti(string $jti): bool
    {
        $s = $this->db->prepare("UPDATE licenses SET status = 'superseded' WHERE jti = ? AND status = 'active'");
        $s->execute([$jti]);
        return $s->rowCount() > 0;
    }

    /**
     * setStatus() for refunds/revocations, with the renewal-chain consequences:
     *  - every descendant (rows whose renews_jti leads back here) that is still
     *    active/superseded gets the same status, so a refunded or revoked base cannot
     *    leave a renewal key that is still update-eligible;
     *  - the row this one extended is brought back to `active` if a renewal had
     *    superseded it, so the buyer can renew again and gets reminders again.
     * Only revoked/refunded cascade; anything else is a plain setStatus().
     */
    public function setStatusCascade(string $jti, string $status): bool
    {
        $this->cascaded = [];
        return $this->transaction(function () use ($jti, $status): bool {
            $row = $this->findByJti($jti);
            $ok = $this->setStatus($jti, $status);
            if (!$ok) {
                return $ok;
            }
            if ($status === 'active') {
                // Un-revoke: put the chain back in a consistent shape. A row that has a
                // live renewal is superseded again; a renewal re-supersedes the row it
                // extended. Descendants cancelled by an earlier cascade are NOT revived
                // (they may have been cancelled for a reason): un-revoke them one by one.
                $live = $this->db->prepare("SELECT 1 FROM licenses WHERE renews_jti = ? AND status IN ('active','superseded') LIMIT 1");
                $live->execute([$jti]);
                if ($live->fetchColumn() !== false) {
                    $this->supersedeJti($jti);
                }
                if (!empty($row['renews_jti'])) {
                    $this->supersedeJti((string) $row['renews_jti']);
                }
                return true;
            }
            if (!in_array($status, ['revoked', 'refunded'], true)) {
                return $ok;
            }
            $queue = [$jti]; $seen = [$jti];
            $up = $this->db->prepare("UPDATE licenses SET status = ? WHERE renews_jti = ? AND status IN ('active','superseded')");
            $pick = $this->db->prepare("SELECT jti, plan, email FROM licenses WHERE renews_jti = ? AND status IN ('active','superseded')");
            $kids = $this->db->prepare('SELECT jti FROM licenses WHERE renews_jti = ?');
            while ($queue) {
                $cur = array_shift($queue);
                $pick->execute([$cur]);
                foreach ($pick->fetchAll() as $c) { $this->cascaded[] = $c; }
                $up->execute([$status, $cur]);
                $kids->execute([$cur]);
                foreach ($kids->fetchAll(\PDO::FETCH_COLUMN) as $k) {
                    if (!in_array($k, $seen, true)) { $seen[] = $k; $queue[] = $k; }
                }
            }
            $parent = (string) ($row['renews_jti'] ?? '');
            if ($parent !== '') {
                $this->db->prepare("UPDATE licenses SET status = 'active' WHERE jti = ? AND status = 'superseded'")->execute([$parent]);
            }
            return true;
        });
    }
}
