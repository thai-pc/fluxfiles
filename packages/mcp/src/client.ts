/**
 * Thin HTTP client for the FluxFiles API (`/api/fm/*`), authenticated with a
 * JWT the operator minted (e.g. with @fluxfiles/node). It never sees storage
 * credentials: everything it can do is bounded by that token's claims
 * (disks, path prefix, permissions, owner_only, …).
 */
export class FluxFilesError extends Error {
  constructor(message: string, public readonly status: number, public readonly code?: string) {
    super(message);
    this.name = 'FluxFilesError';
  }
}

export interface ClientConfig {
  /** Everything before `/api/fm` — e.g. `https://files.example.com` or a WordPress `…/wp-json/fluxfiles/v1`. */
  baseUrl: string;
  token: string;
  fetch?: typeof fetch;
  /** Allow plain `http://` to a non-local host. Off: the token would cross the network in clear text. */
  allowInsecureHttp?: boolean;
}

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]', '::1']);

/** Refuse to send the bearer token in clear text, except to this machine. */
export function assertSafeBaseUrl(baseUrl: string, allowInsecureHttp = false): void {
  let u: URL;
  try {
    u = new URL(baseUrl);
  } catch {
    throw new Error(`FLUXFILES_URL is not a valid URL: ${baseUrl}`);
  }
  if (u.protocol === 'https:') return;
  if (u.protocol !== 'http:') throw new Error(`FLUXFILES_URL must be http(s), got ${u.protocol}`);
  if (LOCAL_HOSTS.has(u.hostname) || allowInsecureHttp) return;
  throw new Error(
    `Refusing to send the FluxFiles token over plain http to ${u.hostname} — use https, ` +
      'or set FLUXFILES_MCP_ALLOW_HTTP=1 if this network is trusted.',
  );
}

export class FluxFilesClient {
  private readonly base: string;
  private readonly f: typeof fetch;

  constructor(private readonly cfg: ClientConfig) {
    assertSafeBaseUrl(cfg.baseUrl, cfg.allowInsecureHttp);
    this.base = cfg.baseUrl.replace(/\/+$/, '');
    this.f = cfg.fetch ?? fetch;
  }

  url(path: string, query: Record<string, string | number | undefined> = {}): string {
    const qs = Object.entries(query)
      .filter(([, v]) => v !== undefined && v !== '')
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
      .join('&');
    return `${this.base}/api/fm${path}${qs ? (this.base.includes('?') ? '&' : '?') + qs : ''}`;
  }

  async request<T = unknown>(method: string, path: string, opts: { query?: Record<string, string | number | undefined>; json?: unknown; form?: FormData } = {}): Promise<T> {
    const headers: Record<string, string> = { Authorization: `Bearer ${this.cfg.token}`, Accept: 'application/json' };
    let body: BodyInit | undefined;
    if (opts.json !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.json);
    } else if (opts.form) {
      body = opts.form;
    }
    // redirect: 'error' — a redirect (open redirect, compromised proxy, http→https
    // downgrade games) must never carry the bearer token to another URL.
    const res = await this.f(this.url(path, opts.query), { method, headers, body, redirect: 'error' });
    type Envelope = { data?: T; error?: string | null; error_code?: string };
    let payload: Envelope | null = null;
    try {
      payload = (await res.json()) as Envelope;
    } catch {
      payload = null;
    }
    if (!res.ok || (payload && payload.error)) {
      throw new FluxFilesError(payload?.error || `HTTP ${res.status}`, res.status, payload?.error_code);
    }
    return (payload?.data ?? (null as T)) as T;
  }
}
