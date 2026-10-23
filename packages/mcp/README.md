# @fluxfiles/mcp

A [Model Context Protocol](https://modelcontextprotocol.io) server for
[FluxFiles](https://github.com/thai-pc/fluxfiles). It lets an AI agent — Claude
Desktop, Claude Code, or any MCP client — browse, search and read the files in
your FluxFiles storage, and optionally write to it.

It talks to the normal FluxFiles HTTP API with a **JWT you mint**, so the agent
can only ever do what that token allows: its disks, its path prefix, its
permissions, its quota. No storage credentials are involved.

## Tools

| Tool | Default | What it does |
|---|---|---|
| `list_files` | ✅ | List a folder (non-recursive) |
| `search_files` | ✅ | Search names, titles, alt text, captions, tags |
| `read_text_file` | ✅ | Return a text file's content (capped at 200 000 chars) |
| `get_file_info` | ✅ | Size, type, dates and descriptive metadata |
| `get_download_link` | ✅ | A short-lived signed URL (private storage) or the public URL |
| `storage_usage` | ✅ | Usage, quota, breakdown by type and folder |
| `write_text_file` | `FLUXFILES_MCP_WRITE=1` | Create a text file (or overwrite one — needs the token's code-editor permission) |
| `create_folder` | `FLUXFILES_MCP_WRITE=1` | Create a folder |
| `move_to_trash` | `FLUXFILES_MCP_WRITE=1` + `FLUXFILES_MCP_DELETE=1` | Move to the (restorable) trash |

## Setup

1. Mint a **narrow** token for the agent — a dedicated prefix, read-only unless
   you really want writes, and a TTL you're comfortable with:

   ```js
   import { createToken } from '@fluxfiles/node';
   const token = createToken({ userId: 'ai-agent', perms: ['read'], disks: ['local'],
     prefix: 'shared/knowledge-base', ttl: 30 * 24 * 3600 }); // secret: FLUXFILES_SECRET env
   ```

2. Add the server to your MCP client. Claude Desktop (`claude_desktop_config.json`):

   ```json
   {
     "mcpServers": {
       "fluxfiles": {
         "command": "npx",
         "args": ["-y", "@fluxfiles/mcp"],
         "env": {
           "FLUXFILES_URL": "https://files.example.com",
           "FLUXFILES_TOKEN": "eyJ…"
         }
       }
     }
   }
   ```

   Claude Code: `claude mcp add fluxfiles -e FLUXFILES_URL=… -e FLUXFILES_TOKEN=… -- npx -y @fluxfiles/mcp`

| Variable | Meaning |
|---|---|
| `FLUXFILES_URL` | Everything before `/api/fm` — `https://files.example.com`, or for WordPress `https://site.example/wp-json/fluxfiles/v1` |
| `FLUXFILES_TOKEN` | The JWT from step 1 |
| `FLUXFILES_DISK` | Default disk (default `local`) |
| `FLUXFILES_MCP_WRITE` | `1` to expose the write tools |
| `FLUXFILES_MCP_DELETE` | `1` (with `WRITE`) to expose `move_to_trash` |
| `FLUXFILES_MCP_ALLOW_HTTP` | `1` to allow plain `http://` to a non-local host. Off by default — the token would travel in clear text; `localhost` is always allowed |

## Security notes

- **Scope the token.** It is the only boundary that matters: a path prefix and
  `perms: ['read']` make the agent unable to see or touch anything else, no
  matter what it is asked to do.
- **File content is untrusted.** A document can contain text written to
  manipulate an agent ("ignore previous instructions…"). The tools return
  content as data and say so, but keep write/delete tools off for agents that
  read files from people you don't trust.
- Every call is an ordinary FluxFiles request: rate-limited, and audited under
  the token's `user` like any other client.

## License

MIT
