# Deployment (self-host)

Web server config, directory permissions, and upload-size limits for running
FluxFiles yourself. This is separate from [`OPERATIONS.md`](OPERATIONS.md),
which is the runbook for the different job of **selling** FluxFiles (Polar,
the licence server, module hosting) — none of that applies here.

Requires PHP **8.2.35 or newer** (8.3/8.4 supported) with `gd`, `curl`, `json`,
`openssl`, `mbstring`, `fileinfo`. Optional binaries: Ghostscript **≥ 10.05.0** (PDF
optimize, PDF posters — an older `gs` is treated as absent), ffmpeg (video posters),
ClamAV / tesseract / c2patool for the matching paid modules. All of them run with a
minimal environment, never the PHP process's own.

## Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name fm.yourdomain.com;
    root /var/www/fluxfiles/packages/core;

    # SSL
    ssl_certificate     /etc/ssl/certs/fm.yourdomain.com.pem;
    ssl_certificate_key /etc/ssl/private/fm.yourdomain.com.key;

    # Max request body — must be >= the largest `max_upload` (MB) you issue in a
    # JWT, or nginx rejects big uploads with 413 BEFORE the request reaches PHP.
    # Set this a bit above your biggest per-file limit (here: 100 MB).
    client_max_body_size 100M;

    # API — rewrite to PHP router
    location /api/ {
        try_files $uri /api/index.php?$query_string;
    }

    # Public HTML — MUST go through PHP so the resolved locale (messages + dir)
    # is injected before the UI boots. A plain `try_files $uri ...` would serve
    # the static index.html first and skip PHP, causing a flash of raw i18n keys.
    location = /public           { rewrite ^ /api/index.php last; }
    location = /public/          { rewrite ^ /api/index.php last; }
    location = /public/index.html { rewrite ^ /api/index.php last; }

    # Static assets (JS, CSS)
    location /assets/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # SDK file
    location = /fluxfiles.js {
        expires 7d;
        add_header Cache-Control "public";
    }

    # Never expose storage-resident bookkeeping (metadata, audit and trash).
    # A regex location (it guards every non-`^~` location; the `^~`
    # /storage/uploads/ block below repeats it as a nested rule) because a path-scoped token keeps its bookkeeping at
    # `<prefix>/_fluxfiles/…`, so the directory is usually NESTED — a
    # `^~ /storage/uploads/_fluxfiles/` prefix rule only covers the unscoped
    # layout. Case-insensitive (`~*`) since on a case-insensitive filesystem
    # `_FLUXFILES/` resolves to the same directory. `_variants/` is deliberately
    # not blocked: variant URLs are public by design.
    location ~* /_fluxfiles/ { deny all; }

    # Everything else under storage/ is server runtime state, not public content:
    # ssh-sockets/ (multiplex index.json + ephemeral BYOB private keys), tus/
    # (partial resumable uploads), locks, caches. `^~` stops the `\.php$` regex
    # from claiming a file in there.
    # /storage/uploads/ below is a longer prefix, so public uploads still serve.
    location ^~ /storage/ { deny all; }

    # Uploaded files (local disk only).
    # Security: stop MIME-sniffing and neutralize active content (e.g. <script>
    # inside an uploaded SVG/HTML) so user files can't run as same-origin XSS.
    location ^~ /storage/uploads/ {
        alias /var/www/fluxfiles/packages/core/storage/uploads/;
        expires 7d;
        add_header Cache-Control "public";
        add_header X-Content-Type-Options "nosniff" always;
        add_header Content-Security-Policy "sandbox" always;
        # `^~` above means nginx does NOT consult the server-level regex locations
        # for anything under here — so the `\.php$` FastCGI rule can never execute
        # an uploaded file, and the deny rules must be repeated as nested locations.
        location ~* /_fluxfiles/ { deny all; }
        location ~ /\. { deny all; }
        # Belt-and-braces: never hand a script-shaped upload to any handler.
        location ~* \.(php[0-9]?|pht|phtml?|phar|phps|pgif|shtml?|stm|cgi|pl|py|jsp|aspx?)$ { deny all; }
        # HTML/SVG are never safe to render inline — force download.
        location ~* \.(html?|xhtml|shtml|xml|svg)$ {
            add_header X-Content-Type-Options "nosniff" always;
            add_header Content-Security-Policy "sandbox" always;
            add_header Content-Disposition "attachment" always;
        }
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    # Block dotfiles and sensitive paths
    location ~ /\. { deny all; }
    location ~ ^/(\.env|composer\.|vendor/) { deny all; }
    location /storage/rate_limit.json { deny all; }
    location /_fluxfiles/ { deny all; }
}
```

> **Private local media:** when `FLUXFILES_LOCAL_PRIVATE=true`, do not serve
> `/storage/uploads/` publicly at all — otherwise its static URL bypasses the
> token-gated `/api/fm/stream` route. Remove the uploads `location` above and,
> for nginx's efficient Range handling, add the internal `/_ff_media/` location
> from [`docker/nginx.conf`](../../docker/nginx.conf), then set
> `FLUXFILES_XACCEL=/_ff_media`. The stock Docker nginx config serves public
> local uploads; derive it before enabling private-local mode.

## Apache (.htaccess)

```apache
RewriteEngine On

# API routes
RewriteRule ^api/(.*)$ api/index.php [QSA,L]

# Public HTML through PHP for locale injection
RewriteRule ^public/(index\.html)?$ api/index.php [QSA,L]

# Never expose storage-resident bookkeeping through the public uploads path.
# `_fluxfiles/` at ANY depth (a path-scoped token keeps its bookkeeping at
# `<prefix>/_fluxfiles/…`) and case-insensitively (NC) — on a case-insensitive
# filesystem `_FLUXFILES/` is the same directory. `_variants/` stays public.
RewriteRule (^|/)_fluxfiles/ - [F,L,NC]

# Block sensitive files
<FilesMatch "^\.env|composer\.(json|lock)">
    Require all denied
</FilesMatch>

# Everything under storage/ except uploads/ is server runtime state
# (rate_limit.json, demo_ip.json, tus/ partial uploads, ssh-sockets/ with
# ephemeral BYOB private keys).
RewriteRule ^storage/(?!uploads/) - [F,L,NC]
# Dotfiles anywhere (.env, an uploaded .user.ini/.htaccess, .git/…).
RewriteRule (^|/)\. - [F,L]
```

And in `storage/uploads/.htaccess` (user content — never execute it, never let
it render as same-origin active content):

```apache
# No script handler of any kind for user uploads.
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .pht .phtml .phar .cgi .pl .py .shtml
RemoveType .php .php3 .php4 .php5 .php7 .php8 .pht .phtml .phar
Options -ExecCGI -Includes -Indexes
<FilesMatch "\.(?i:php[0-9]?|pht|phtml?|phar|phps|pgif|shtml?|stm|cgi|pl|py)$">
    Require all denied
</FilesMatch>

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Content-Security-Policy "sandbox"
    <FilesMatch "\.(?i:html?|xhtml|shtml|xml|svg)$">
        Header always set Content-Disposition "attachment"
    </FilesMatch>
</IfModule>
```

## Directory Permissions

```bash
# Set ownership
chown -R www-data:www-data /var/www/fluxfiles/storage/

# Writable directories
chmod -R 755 storage/
chmod 600 .env
chmod 600 storage/rate_limit.json   # if exists
```

`storage/` also holds `tus/` (partial resumable uploads, swept after 24 h). On a
**multi-node** deployment behind a load balancer, either share
`<FLUXFILES_STORAGE_PATH>/tus/` between the nodes or use sticky sessions — a tus
`PATCH` must reach the node that holds the upload's partial bytes.

## Upload size limits (three layers)

A large upload must pass **three** independent limits — the smallest one wins.
The JWT `max_upload` is the only one FluxFiles controls; the other two are your
server/PHP config and must be **≥** your largest `max_upload`, or files are
rejected *before* reaching the app (often as a confusing `413` or
`400 No file uploaded` instead of FluxFiles' own `413 upload_too_large`).

| Layer | Setting | Where | Note |
|-------|---------|-------|------|
| Web server | `client_max_body_size` (nginx) / `LimitRequestBody` (Apache) | nginx `server`/`http` block | Rejects the request body if too big. |
| PHP | `upload_max_filesize` **and** `post_max_size` | `php.ini` (or php-fpm pool) | `post_max_size` must be ≥ `upload_max_filesize`; both ≥ your `max_upload`. |
| FluxFiles | `max_upload` (JWT claim, **MB, per file**) | issued in your token | Returns `413 upload_too_large` when exceeded. |

```ini
; php.ini — example for a 100 MB per-file limit
upload_max_filesize = 100M
post_max_size       = 110M     ; a little above upload_max_filesize
```

> Two paths avoid the PHP limits for big files. **S3/R2 disks:** files larger than
> 10 MB use chunked (multipart) upload, which sends 5 MB parts directly to the bucket,
> bypassing `post_max_size`. **Local/SFTP disks (standalone core):** files larger than
> 8 MB use resumable **tus** upload in small chunks, so only each chunk — not the whole
> file — has to fit the web-server/PHP body limits, and `max_upload` still caps the total.
> Behind the Laravel/WordPress proxies there is no `/tus` route, so local uploads there
> are a single request and the PHP/nginx limits above apply in full.

## Large libraries: when to switch to the database backend

With the default JSON backend (`FLUXFILES_STORAGE_BACKEND=json`), each disk keeps
**one** metadata index, `_fluxfiles/index.json`, covering every file on that disk
across all tenants. Every list page reads and decodes the whole file. Every
upload, rename, move or delete rewrites the whole file while holding a lock.
Cost grows with the number of files on the disk, not the number in the folder
being opened. Measured on a local disk (PHP 8.2, one list page of 200 items):

| Files on the disk | `index.json` | One list page | PHP peak memory |
|---|---|---|---|
| 1,500 | 0.3 MB | 18 ms | 7 MB |
| 20,500 | 5.5 MB | 66 ms | 58 MB |
| 100,500 | 27.7 MB | 299 ms | 269 MB |

On S3/R2 add the transfer of the whole file on each request. Past roughly
20,000 files (or a `memory_limit` under 256 MB), use the database backend:
set `FLUXFILES_STORAGE_BACKEND=db` with `FLUXFILES_DB_DSN`, then run
`php scripts/migrate-json-to-db.php` once to import the existing bookkeeping.
The database backend reads and writes only the rows a request needs instead of
one file covering the whole disk. File bytes stay in your storage either way. See
`docs/design/DB-STORAGE-MIGRATION-DESIGN.md`.

The server warns before this becomes a problem. When the index passes
`FLUXFILES_INDEX_WARN_MB` (default 5), it logs a warning at most once an hour
per disk. The usage dashboard also shows operators (tokens with no `prefix`) a
banner.
