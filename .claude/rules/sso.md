---
paths:
  - "packages/sso/**"
  - "packages/core/api/SsoBootToken.php"
  - "packages/core/api/SsoStateToken.php"
---

**SSO bridge is pre-auth, so it can't use the normal 3-layer `ModuleRegistry::require()`** — there's no `Claims` yet to check a per-token claim against. `ModuleRegistry::requireServer($id, $license)` does layers 1+2 only (installed + licensed); layer 3 becomes an explicit `FLUXFILES_SSO_ENABLED` env check in each route (`SsoModule::claim()` returns `''`, per `ModuleInterface`'s contract for claim-less modules). Logins use PKCE (S256). The FluxFiles user id is the IdP `sub` by default (`FLUXFILES_SSO_USER_CLAIM`; `email` only when `email_verified`), and `FLUXFILES_SSO_CLAIMS_MAP` precedence is the map's own order, never the IdP's group order. The real JWT, once minted post-login, is sealed (AES-256-GCM) inside a one-time boot token handed to the standalone UI via a URL **fragment** (`#boot=…`, 60s TTL) rather than a query string — fragments never reach server logs — then a `POST /api/fm/sso/exchange` trades it for the real JWT over a response body, held in JS memory only, never persisted. OIDC only (no SAML).
