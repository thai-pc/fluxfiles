<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Implemented by the `audit-export` module to own the HMAC-chain check behind
 * `GET /api/fm/audit/verify`. Core carries no verifier of its own: an
 * `audit-export` release that does not implement this answers 501 `module_update_required`.
 */
interface AuditVerifierInterface
{
    /**
     * Core returns this array to the client as-is, so the shape is:
     *
     * `{ok:bool, configured:bool, entries:int, chained:int, unchained:int,
     *   tampered:list<array>, gaps:int, forks:int, first_ts:?int, last_ts:?int, head:?string}`
     *
     * @param array<int,array<string,mixed>> $entries archived + live rows, any order
     * @return array<string,mixed>
     */
    public function verifyChain(array $entries): array;
}
