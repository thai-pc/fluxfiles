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

    public function __construct(?string $path = null)
    {
        $path = $path ?? (getenv('FLUXFILES_LICENSE_DB') ?: __DIR__ . '/data/licenses.sqlite');
        if ($path !== ':memory:') {
            @mkdir(dirname($path), 0700, true);
        }
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL');
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
            sites       INTEGER DEFAULT 0,
            enforcement TEXT,
            issued      INTEGER,
            expires     INTEGER,
            license_key TEXT NOT NULL,
            gateway     TEXT,
            order_id    TEXT,
            status      TEXT DEFAULT "active",
            created_at  INTEGER
        )');
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
        $stmt = $this->db->prepare('INSERT INTO licenses
            (jti,email,customer,plan,edition,modules,sites,enforcement,issued,expires,license_key,gateway,order_id,checkout_id,status,created_at,grace_days)
            VALUES (:jti,:email,:customer,:plan,:edition,:modules,:sites,:enforcement,:issued,:expires,:license_key,:gateway,:order_id,:checkout_id,:status,:created_at,:grace_days)');
        $row = [
            'jti'         => (string) $rec['jti'],
            'email'       => (string) $rec['email'],
            'customer'    => (string) ($rec['customer'] ?? ''),
            'plan'        => (string) ($rec['plan'] ?? ''),
            'edition'     => (string) ($rec['edition'] ?? ''),
            'modules'     => implode(',', (array) ($rec['modules'] ?? [])),
            'sites'       => (int) ($rec['sites'] ?? 0),
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
        ];
        $stmt->execute($row);
        return $this->findByJti($row['jti']) ?? $row;
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
     * someone whose order was reversed is worse than not sending it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function undelivered(int $limit = 100): array
    {
        $s = $this->db->prepare(
            'SELECT * FROM licenses WHERE mailed_at IS NULL AND status = "active"
             ORDER BY created_at ASC LIMIT ?'
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
            'SELECT * FROM licenses WHERE status = "active" AND expires IS NOT NULL ORDER BY created_at ASC'
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
     * for the same (customer, plan) so only the newest stays eligible for reminders
     * (and stops the admin/licenses list from showing stale duplicate "active" rows
     * for the same customer). No-op when customer or plan is blank, since either
     * would otherwise match too broadly across unrelated purchases.
     *
     * @return int number of rows superseded
     */
    public function supersedeActiveForCustomerPlan(string $customer, string $plan, string $exceptJti): int
    {
        if ($customer === '' || $plan === '') {
            return 0;
        }
        $s = $this->db->prepare(
            'UPDATE licenses SET status = "superseded"
             WHERE customer = ? AND plan = ? AND status = "active" AND jti != ?'
        );
        $s->execute([$customer, $plan, $exceptJti]);
        return $s->rowCount();
    }

    /**
     * (customer, plan) pairs that currently have MORE THAN ONE `active` row — the
     * backlog supersedeActiveForCustomerPlan() prevents going forward but can't
     * retroactively clean up, since it only runs on the NEXT issue() for a pair.
     * Used by the one-off backfill-supersede-duplicates.php script.
     *
     * @return array<int,array{customer:string,plan:string,cnt:int}>
     */
    public function duplicateActiveCustomerPlans(): array
    {
        $s = $this->db->query(
            'SELECT customer, plan, COUNT(*) AS cnt FROM licenses
             WHERE status = "active" AND customer != "" AND plan != ""
             GROUP BY customer, plan HAVING COUNT(*) > 1'
        );
        return $s->fetchAll();
    }

    /**
     * `active` rows for one (customer, plan) pair, newest-issued first — so callers
     * can keep rows[0] and supersede the rest.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeRowsForCustomerPlan(string $customer, string $plan): array
    {
        $s = $this->db->prepare(
            'SELECT * FROM licenses WHERE customer = ? AND plan = ? AND status = "active"
             ORDER BY issued DESC, created_at DESC'
        );
        $s->execute([$customer, $plan]);
        return $s->fetchAll();
    }
}
