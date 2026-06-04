# FluxFiles for Node (server-side token SDK)

Mint [FluxFiles](https://github.com/thai-pc/fluxfiles) JWTs from any Node.js
backend (Express, Next.js, Nuxt/Nitro, NestJS, Fastify, …). Tokens are
**byte-compatible with the PHP core**, so non-PHP apps can issue access tokens —
including encrypted BYOB (Bring Your Own Bucket) credentials — without running PHP.

Zero runtime dependencies (built on `node:crypto`).

## Requirements

- Node.js 16+
- The same **`FLUXFILES_SECRET`** your FluxFiles core server uses to verify tokens
  (HS256, **must be ≥ 32 bytes**). Keep it server-side only.

## Installation

```bash
npm install @fluxfiles/node
# or
yarn add @fluxfiles/node
```

## Usage

### Mint a token

```ts
import { createToken } from '@fluxfiles/node';

const token = createToken({
  secret: process.env.FLUXFILES_SECRET, // or omit to read FLUXFILES_SECRET
  userId: 'user-42',
  perms: ['read', 'write'],
  disks: ['local', 's3'],
  prefix: 'users/42',     // scope the user to their own directory
  maxUploadMb: 25,
  allowedExt: ['png', 'jpg', 'pdf'],
  ttl: 3600,              // seconds
});
```

### BYOB — encrypt a user's own bucket credentials

```ts
import { createByobToken } from '@fluxfiles/node';

const token = createByobToken({
  userId: 'user-42',
  byobDisks: {
    'my-s3': {
      driver: 's3',
      key: process.env.USER_AWS_KEY!,
      secret: process.env.USER_AWS_SECRET!,
      bucket: 'user-personal-bucket',
      region: 'us-east-1',
      // endpoint: 'https://<acct>.r2.cloudflarestorage.com', // R2/MinIO/Spaces
    },
  },
});
```

Credentials are AES-256-GCM encrypted into the token and decrypted only at
runtime by the FluxFiles server (which also re-validates the endpoint for SSRF).

### Verify / decode (optional)

```ts
import { verifyToken, decodeToken } from '@fluxfiles/node';

const claims = verifyToken(token);   // checks HS256 signature + expiry, throws on failure
const peek = decodeToken(token);     // decode only, NO verification — never trust for auth
```

### Next.js (App Router) — Route Handler

```ts
// app/api/fluxfiles-token/route.ts
import { createToken } from '@fluxfiles/node';
import { auth } from '@/lib/auth';

export async function GET() {
  const user = await auth();
  const token = createToken({ userId: user.id, perms: ['read', 'write'], prefix: `users/${user.id}` });
  return Response.json({ token });
}
```

### Express middleware

```ts
import { createToken } from '@fluxfiles/node';

app.get('/fluxfiles/token', (req, res) => {
  res.json({ token: createToken({ userId: req.user.id, perms: ['read', 'write'] }) });
});
```

## API

| Function | Description |
|----------|-------------|
| `createToken(opts)` | Standard token. Mirrors PHP `fluxfiles_token()`. |
| `createByobToken(opts)` | Token with encrypted BYOB disk credentials. Mirrors `fluxfiles_byob_token()`. |
| `verifyToken(token, secret?)` | Verify HS256 signature + expiry; returns decoded claims or throws. |
| `decodeToken(token)` | Decode without verifying (inspection/logging only). |

`createToken` options: `secret?`, `userId`, `perms?`, `disks?`, `prefix?`,
`maxUploadMb?`, `allowedExt?`, `ttl?`, `ownerOnly?`, `maxStorageMb?`, `maxFiles?`.
`createByobToken` replaces `disks` with `byobDisks` (a map of name → S3-compatible
config) and does not take `maxStorageMb`/`maxFiles` (matching the core).

## Compatibility

Tokens and BYOB blobs are validated against the PHP core in CI: a Node-minted
token decodes in `JwtCompat::decode`, and BYOB credentials round-trip both ways
through `CredentialEncryptor` (HS256 + HKDF-SHA256 + AES-256-GCM). Always mint
tokens **on the server** — never ship `FLUXFILES_SECRET` to the browser.

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#node-server-side-token-sdk`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`
