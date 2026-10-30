<?php

defined('ABSPATH') || exit;

/**
 * Core's shared /stream + /img endpoint with WordPress's status plumbing.
 *
 * Everything else (token checks, Range, X-Accel, the preview watermark overlay,
 * AVIF/WebP negotiation) is the unmodified core class; the only seam WordPress
 * needs is status_header(), so the `status_header` filter and the right HTTP
 * protocol string apply to these raw responses too.
 */
class FluxFilesMediaEndpoint extends \FluxFiles\MediaEndpoint
{
    protected function status(int $code): void
    {
        status_header($code);
    }
}
