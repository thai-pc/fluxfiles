import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { FluxFilesClient, FluxFilesError } from './client.js';
import { buildTools, type ToolOptions } from './tools.js';

export interface ServerConfig extends Partial<ToolOptions> {
  baseUrl: string;
  token: string;
  fetch?: typeof fetch;
  allowInsecureHttp?: boolean;
}

export const VERSION = '0.1.2';

export function createServer(cfg: ServerConfig): McpServer {
  const client = new FluxFilesClient({ baseUrl: cfg.baseUrl, token: cfg.token, fetch: cfg.fetch, allowInsecureHttp: cfg.allowInsecureHttp });
  const server = new McpServer({ name: 'fluxfiles', version: VERSION });
  const tools = buildTools(client, {
    disk: cfg.disk ?? 'local',
    allowWrite: cfg.allowWrite ?? false,
    allowDelete: (cfg.allowWrite ?? false) && (cfg.allowDelete ?? false),
    maxTextChars: cfg.maxTextChars ?? 200_000,
  });
  for (const t of tools) {
    server.registerTool(
      t.name,
      {
        title: t.title,
        description: t.description,
        inputSchema: t.shape,
        annotations: { readOnlyHint: t.readOnly, destructiveHint: t.name === 'move_to_trash', openWorldHint: false },
      },
      async (args: Record<string, unknown>) => {
        try {
          const result = await t.run(args);
          return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
        } catch (e) {
          const msg = e instanceof FluxFilesError ? `${e.message}${e.code ? ` (${e.code})` : ''}` : String(e);
          return { isError: true, content: [{ type: 'text' as const, text: msg }] };
        }
      },
    );
  }
  return server;
}
