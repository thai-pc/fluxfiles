#!/usr/bin/env node
/**
 * stdio MCP server. Configure through the environment:
 *
 *   FLUXFILES_URL          everything before /api/fm (required)
 *   FLUXFILES_TOKEN        a FluxFiles JWT — scope it (prefix, perms, ttl)! (required)
 *   FLUXFILES_DISK         default disk (default: local)
 *   FLUXFILES_MCP_WRITE    "1" → also expose write_text_file / create_folder
 *   FLUXFILES_MCP_DELETE   "1" (with WRITE) → also expose move_to_trash
 *   FLUXFILES_MCP_ALLOW_HTTP "1" → allow plain http to a non-local host (token in clear text)
 */
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { createServer } from './server.js';

const url = process.env.FLUXFILES_URL ?? '';
const token = process.env.FLUXFILES_TOKEN ?? '';
if (!url || !token) {
  process.stderr.write('fluxfiles-mcp: set FLUXFILES_URL and FLUXFILES_TOKEN\n');
  process.exit(1);
}
const on = (v?: string) => v === '1' || v === 'true';

const server = createServer({
  baseUrl: url,
  token,
  disk: process.env.FLUXFILES_DISK || 'local',
  allowWrite: on(process.env.FLUXFILES_MCP_WRITE),
  allowDelete: on(process.env.FLUXFILES_MCP_DELETE),
  allowInsecureHttp: on(process.env.FLUXFILES_MCP_ALLOW_HTTP),
});
await server.connect(new StdioServerTransport());
