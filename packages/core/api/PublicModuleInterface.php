<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * A paid module that serves routes with no main JWT (the recipient side of Share and
 * Intake). {@see PublicRoutes::dispatch()} has already passed the gate (installed +
 * licensed) and checked the signing secret by the time `handlePublic()` runs; the
 * module owns everything after that — token verification, its own rate limits (via
 * {@see PublicContext}), enforcement and the response (it emits the JSON envelope or the
 * bytes itself, as the in-core handlers did). Throw {@see ApiException} to refuse: the
 * dispatcher turns it into the standard envelope.
 */
interface PublicModuleInterface extends ModuleInterface
{
    /**
     * @param string $method HTTP method
     * @param string $uri    request path, e.g. `/api/fm/share/info`
     */
    public function handlePublic(string $method, string $uri, PublicContext $ctx): void;

    /**
     * The recipient pages the module ships, keyed by module id: `['share' => '/abs/path/share.html']`.
     *
     * @return array<string,string>
     */
    public static function pages(): array;
}
