/**
 * The Permissions-Policy `allow` value every FluxFiles iframe grants, so fullscreen
 * + clipboard work in embedded mode. Keep this identical across the SDK, React, Vue
 * and WordPress surfaces — `scripts/check-iframe-allow.sh` enforces parity in CI.
 */
export const IFRAME_ALLOW = 'clipboard-write; fullscreen';
