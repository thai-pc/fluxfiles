// Shared constants for the real-picker editor specs.

// Throwaway HS256 secret (>= 32 bytes for firebase/php-jwt v7). Written to the core
// .env by global-setup and used to mint the test tokens.
export const TEST_SECRET = 'pw-editors-secret-key-0123456789abcdefgh';

// Ports: STATIC serves the repo root (pages + vendored libs + shipped plugins),
// CORE is the live FluxFiles backend whose picker iframe the editors open,
// CK5 is the Vite dev server bundling the CKEditor 5 adapter source.
export const STATIC_PORT = Number(process.env.FF_EDITORS_PORT || 8197);
export const CORE_PORT = Number(process.env.FF_EDITORS_CORE_PORT || 8201);
export const CK5_PORT = Number(process.env.FF_EDITORS_CK5_PORT || 8203);

export const CORE_ENDPOINT = `http://127.0.0.1:${CORE_PORT}`;
