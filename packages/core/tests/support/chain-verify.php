<?php

/**
 * Test-only oracle: a verbatim copy of AuditChain::verify() (core 1.0.x), kept so the
 * chain tests keep an independent verifier once the shipped one moves to the
 * audit-export module. Uses only AuditChain's public helpers.
 */

declare(strict_types=1);

use FluxFiles\AuditChain;

function fxChainRowEntry(array $row): array
{
    return [
        'ts' => (int) ($row['created_at'] ?? 0), 'action' => (string) ($row['action'] ?? ''),
        'user_id' => $row['user_id'] ?? null, 'file_key' => $row['file_key'] ?? null,
        'ip' => $row['ip'] ?? null, 'user_agent' => $row['user_agent'] ?? null,
        'detail' => $row['detail'] ?? null,
    ];
}

/**
 * @param array<int,array<string,mixed>> $entries readAudit()/readAuditArchive() rows
 * @return array{ok:bool,configured:bool,entries:int,chained:int,unchained:int,tampered:array<int,array<string,mixed>>,gaps:int,forks:int,first_ts:?int,last_ts:?int,head:?string}
 */
function fxChainVerify(array $entries): array
{
    $key = AuditChain::key();
    $report = [
        'ok' => true, 'configured' => $key !== null, 'entries' => count($entries),
        'chained' => 0, 'unchained' => 0, 'tampered' => [], 'gaps' => 0, 'forks' => 0,
        'first_ts' => null, 'last_ts' => null, 'head' => null,
    ];
    $byHash = [];
    $childrenOf = [];
    $unchainedRows = [];
    foreach ($entries as $row) {
        $h = (string) ($row['entry_hash'] ?? '');
        if ($h === '') {
            $report['unchained']++;
            $unchainedRows[] = $row;
            continue;
        }
        $report['chained']++;
        $prev = (string) ($row['prev_hash'] ?? '');
        $ts = (int) ($row['created_at'] ?? 0);
        $entry = fxChainRowEntry($row);
        if (!AuditChain::isAuthentic($row, $key)) {
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
    foreach ($unchainedRows as $row) {
        $ts = (int) ($row['created_at'] ?? 0);
        if ($ts > (int) $report['first_ts']) {
            if (count($report['tampered']) < 50) {
                $report['tampered'][] = ['created_at' => $ts, 'action' => (string) ($row['action'] ?? ''), 'user_id' => $row['user_id'] ?? null, 'hash' => ''];
            }
            $report['ok'] = false;
        }
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
