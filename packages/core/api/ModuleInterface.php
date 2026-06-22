<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Contract a commercial (paid) module implements so the MIT core can gate + invoke
 * it without depending on the module's code. The module ships in its own
 * proprietary package; the core only ever sees this interface.
 *
 * The actual work method is module-specific (the core route calls it by name after
 * the gate passes) — only the identity + the claim it requires are standardised
 * here, which is all `ModuleRegistry` needs to enforce the three-layer gate
 * (capability `class_exists` · license · JWT claim).
 */
interface ModuleInterface
{
    /** Stable module id, e.g. `optimize`. Matches the license `modules[]` entry. */
    public static function id(): string;

    /**
     * The JWT claim a tenant's token must carry to use this module, e.g.
     * `allow_optimize`. Return '' for a module with no per-tenant claim gate.
     */
    public static function claim(): string;
}
