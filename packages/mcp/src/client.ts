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
}

export class FluxFilesClient {
  private readonly base: string;
  private readonly f: typeof fetch;

  constructor(private readonly cfg: ClientConfig) {
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
    const res = await this.f(this.url(path, opts.query), { method, headers, body });
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
