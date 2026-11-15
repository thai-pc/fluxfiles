<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON API exceptions: the message travels in the JSON error envelope and is never rendered as HTML

/**
 * Shared handler for the AI connection test (docs/design/CONNECTION-TEST-DESIGN.md §6), so
 * core and the Laravel/WordPress proxies answer identically in one line each.
 *
 *   POST /api/fm/ai/test  {disk?}   → run()
 *   GET  /api/fm/disk/doctor        → doctorBlock() appended as `ai`
 *
 * Same eligibility as `/ai-tag` (disk in the token, `write`, a provider configured);
 * `ai_auto_tag` plays no part. The per-user 2/min `ai_test` bucket is the caller's job
 * (it runs before routing, next to `notify_test`).
 */
final class AiTestEndpoint
{
    /**
     * One real tiny vision call. A failed test is a successful diagnosis: this returns the
     * result shape (HTTP 200) for pass and fail alike; only the gate throws. `detail` (the
     * redacted provider text) is kept for an unscoped (admin) token only: a tenant gets
     * code + hint, never the operator's provider/billing text.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function run(Claims $claims, ?AiTagger $tagger, array $body): array
    {
        $disk = isset($body['disk']) && is_string($body['disk']) && $body['disk'] !== '' ? $body['disk'] : 'local';
        if (!$claims->hasDisk($disk)) {
            throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
        }
        if (!$claims->hasPerm('write')) {
            throw new ApiException('Permission denied: write', 403, 'permission_denied');
        }
        if ($tagger === null) {
            throw new ApiException('AI tagging is not configured', 400, 'ai_not_configured');
        }

        $result = $tagger->testConnection();
        if (trim($claims->pathPrefix, '/') !== '') {
            $result['detail'] = null;
        }
        return $result;
    }

    /**
     * The additive `ai` field of the Doctor report: what is configured, no network call.
     * Null (field absent) unless the token may write AND a provider is configured.
     *
     * @return array{configured: bool, provider: string, model: string}|null
     */
    public static function doctorBlock(Claims $claims, ?AiTagger $tagger): ?array
    {
        if ($tagger === null || !$claims->hasPerm('write')) {
            return null;
        }
        $d = $tagger->describe();
        return ['configured' => true, 'provider' => $d['provider'], 'model' => $d['model']];
    }
}
