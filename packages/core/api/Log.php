<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Warning sink for the shipped core files. The default is PHP's `error_log()`, exactly
 * what the call sites did before; a host that wants its own channel (the WordPress
 * plugin routes it through a WP_DEBUG-gated logger) installs a sink with setSink().
 * A throwing sink is swallowed: logging must never turn a served request into an error.
 */
final class Log
{
    /** @var (callable(string):void)|null */
    private static $sink = null;

    /** @param (callable(string):void)|null $sink null restores the error_log() default */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    public static function warn(string $msg): void
    {
        if (self::$sink === null) {
            error_log($msg);
            return;
        }
        try {
            (self::$sink)($msg);
        } catch (\Throwable $e) {
            // never let a logging failure surface
        }
    }
}
