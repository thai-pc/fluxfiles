# Deployment (self-host)

Web server config, directory permissions, and upload-size limits for running
FluxFiles yourself. This is separate from [`OPERATIONS.md`](OPERATIONS.md),
which is the runbook for the different job of **selling** FluxFiles (Polar,
the licence server, module hosting) — none of that applies here.

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

    # Uploaded files (local disk only).
    # Security: stop MIME-sniffing and neutralize active content (e.g. <script>
    # inside an uploaded SVG/HTML) so user files can't run as same-origin XSS.
    location /storage/uploads/ {
        alias /var/www/fluxfiles/packages/core/storage/uploads/;
        expires 7d;
        add_header Cache-Control "public";
        add_header X-Content-Type-Options "nosniff" always;
        add_header Content-Security-Policy "sandbox" always;
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

## Apache (.htaccess)

```apache
RewriteEngine On

# API routes
RewriteRule ^api/(.*)$ api/index.php [QSA,L]

# Public HTML through PHP for locale injection
RewriteRule ^public/(index\.html)?$ api/index.php [QSA,L]

# Block sensitive files
<FilesMatch "^\.env|composer\.(json|lock)">
    Require all denied
</FilesMatch>
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

> Files **larger than 10 MB on S3/R2 disks** use chunked (multipart) upload, which
> sends 5 MB parts directly to the bucket — so those bypass `post_max_size`. Local
> disk always uses a single request, so the PHP/nginx limits above apply in full.
