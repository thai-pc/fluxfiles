<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Tamper-evident audit log: every entry carries `prev` (the previous entry's
 * hash) and `hash` = HMAC-SHA256(key, prev + "\n" + canonical(entry)).
 *
 * The key is derived (HKDF) from FLUXFILES_SECRET, so someone with write access
 * to the STORAGE but not the app secret — a bucket admin, a leaked storage
 * credential, a co-tenant who reached `_fluxfiles/` — can edit, insert or
 * reorder entries only by breaking the chain, and verify() reports it.
 *
 * The canonical form is built from the entry's fields, not from how a backend
 * happens to serialise them, so the same chain verifies whether it lives in
 * `audit.jsonl` (+ archives) or the `db` backend's audit_log table — and
 * survives JsonToDbMigrator copying it across.
 *
 * What a chain cannot prove on its own: that the NEWEST entries weren't cut off
 * (tail truncation) or that the oldest weren't (a purge legitimately removes
 * them). Anchor the head externally — a webhook/SIEM copy of `head` — for that.
 */
final class AuditChain
{
    private const HKDF_INFO = 'fluxfiles-audit-chain';

    /** Explicit secret set by a host that doesn't keep it in the environment. */
    private static ?string $secret = null;

    /** Adapters (Laravel config, WordPress option) hand their secret in here. */
    public static function configure(#[\SensitiveParameter] string $secret): void
    {
        self::$secret = $secret !== '' ? $secret : null;
    }

    /** Raw HMAC key, or null when no secret is known (entries are then unchained). */
    public static function key(): ?string
    {
        $secret = self::$secret
            ?? (string) ($_ENV['FLUXFILES_SECRET'] ?? (getenv('FLUXFILES_SECRET') ?: ''));
        if ($secret === '') {
            return null;
        }
        return hash_hkdf('sha256', $secret, 32, self::HKDF_INFO);
    }

    /**
     * Backend-independent canonical form of one entry.
     *
     * @param array{ts:int,action:string,user_id:?string,file_key:?string,ip:?string,user_agent:?string,detail:?string} $e
     */
    public static function canonical(array $e): string
    {
        $s = static fn ($v): ?string => ($v === null || $v === '') ? null : (string) $v;
        return (string) json_encode([
            (int) $e['ts'],
            (string) $e['action'],
            $s($e['user_id'] ?? null),
            $s($e['file_key'] ?? null),
            $s($e['ip'] ?? null),
            $s($e['user_agent'] ?? null),
            $s($e['detail'] ?? null),
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The entry hash given its predecessor's ('' = chain start). */
    public static function hash(#[\SensitiveParameter] string $key, string $prev, array $entry): string
    {
        return hash_hmac('sha256', $prev . "\n" . self::canonical($entry), $key);
    }

    /**
     * Verify a set of entries (any order; live + archived merged).
     *
     * @param array<int,array<string,mixed>> $entries readAudit()/readAuditArchive()
     *        rows — created_at, action, user_id, file_key, ip, user_agent, detail,
     *        prev_hash, entry_hash.
     * @return array{ok:bool,configured:bool,entries:int,chained:int,unchained:int,tampered:array<int,array<string,mixed>>,gaps:int,forks:int,first_ts:?int,last_ts:?int,head:?string}
     */
    public static function verify(array $entries): array
    {
        $key = self::key();
        $report = [
            'ok' => true, 'configured' => $key !== null, 'entries' => count($entries),
            'chained' => 0, 'unchained' => 0, 'tampered' => [], 'gaps' => 0, 'forks' => 0,
            'first_ts' => null, 'last_ts' => null, 'head' => null,
        ];
        $byHash = [];
        $childrenOf = [];
        foreach ($entries as $row) {
            $h = (string) ($row['entry_hash'] ?? '');
            if ($h === '') {
                $report['unchained']++;
                continue;
            }
            $report['chained']++;
            $prev = (string) ($row['prev_hash'] ?? '');
            $ts = (int) ($row['created_at'] ?? 0);
            $entry = [
                'ts' => $ts, 'action' => (string) ($row['action'] ?? ''),
                'user_id' => $row['user_id'] ?? null, 'file_key' => $row['file_key'] ?? null,
                'ip' => $row['ip'] ?? null, 'user_agent' => $row['user_agent'] ?? null,
                'detail' => $row['detail'] ?? null,
            ];
            if ($key === null || !hash_equals(self::hash($key, $prev, $entry), $h)) {
                if (count($report['tampered']) < 50) {
                    $report['tampered'][] = ['created_at' => $ts, 'action' => $entry['action'], 'user_id' => $entry['user_id'], 'hash' => $h];
                }
                $report['ok'] = false;
            }
            $byHash[$h] = $ts;
            $childrenOf[$prev][] = $h;
            $report['first_ts'] = $report['first_ts'] === null ? $ts : min($report['first_ts'], $ts);
            $report['last_ts'] = $report['last_ts'] === null ? $ts : max($report['last_ts'], $ts);
        }
        if ($report['chained'] === 0) {
            return $report;
        }
        // Starts = entries whose predecessor isn't present. Exactly one is normal (the
        // first entry ever, or the oldest one left after a purge/rotation-delete);
        // every extra start means entries were removed from the MIDDLE.
        $starts = 0;
        foreach ($childrenOf as $prev => $kids) {
            if ($prev === '' || !isset($byHash[$prev])) {
                $starts += count($kids);
            }
            if (count($kids) > 1) {
                $report['forks'] += count($kids) - 1; // one predecessor, two successors
            }
        }
        $report['gaps'] = max(0, $starts - 1);
        // Head = the hash no other entry builds on.
        foreach (array_keys($byHash) as $h) {
            if (!isset($childrenOf[$h])) {
                $report['head'] = (string) $h;
                break;
            }
        }
        if ($report['gaps'] > 0 || $report['forks'] > 0) {
            $report['ok'] = false;
        }
        return $report;
    }
}
