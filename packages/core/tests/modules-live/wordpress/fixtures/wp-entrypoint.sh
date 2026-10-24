#!/bin/bash
# clamd (virus module) → wait for MariaDB → install + configure WordPress through
# wp-cli the way an operator would in wp-admin (plugin options, not env) → serve.
set -e
clamd &
cd /var/www/html
for i in $(seq 1 90); do php -r 'exit(@mysqli_connect("ff-db","wp","wp","wp") ? 0 : 1);' 2>/dev/null && break; sleep 2; done
if [ ! -f wp-config.php ]; then
  wp config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost=ff-db --skip-check --quiet
  wp config set DISABLE_WP_CRON true --raw --quiet
fi
wp core is-installed 2>/dev/null || wp core install --url=http://localhost:8390 --title='FluxFiles live' \
  --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email --quiet
wp plugin activate fluxfiles --quiet
wp option update fluxfiles_secret "$FF_E2E_SECRET" --quiet
# The licence goes where a WordPress customer pastes it (Settings → FluxFiles → Licence).
printf '%s' "$FF_E2E_LICENSE" | wp option update fluxfiles_license_key --quiet
# Second disk for Backup: the plugin's own S3 settings, pointed at LocalStack.
wp option update fluxfiles_s3_bucket ff-backup --quiet
wp option update fluxfiles_s3_region us-east-1 --quiet
wp option update fluxfiles_s3_key test --quiet
wp option update fluxfiles_s3_secret test --quiet
wp option update fluxfiles_s3_endpoint http://localstack:4566 --quiet
wp option update fluxfiles_disks '["local","s3"]' --format=json --quiet
# Module claims: the edition preset + per-module tuning, through the filter above.
wp option update fluxfiles_e2e_overrides "$(cat /wpfixtures/overrides.json)" --quiet
mkdir -p wp-content/mu-plugins && cp /wpfixtures/fluxfiles-live.php wp-content/mu-plugins/
wp rewrite structure '/%postname%/' --hard --quiet
# Full-width block group: in the theme's default content column the iframe is
# < 768px wide and the UI switches to its mobile layout (action sheet, collapsed
# toolbar), which is a different UI from the one these specs drive.
CONTENT='<!-- wp:group {"align":"full","layout":{"type":"default"}} --><div class="wp-block-group alignfull"><!-- wp:shortcode -->[fluxfiles disk="local" mode="browser" height="900px"]<!-- /wp:shortcode --></div><!-- /wp:group -->'
ID="$(wp post list --post_type=page --name=files --field=ID | head -1)"
if [ -z "$ID" ]; then
  wp post create --post_type=page --post_status=publish --post_title=Files --post_name=files --post_content="$CONTENT" --quiet
else
  wp post update "$ID" --post_content="$CONTENT" --quiet
fi
for i in $(seq 1 120); do [ -S /run/clamav/clamd.ctl ] && break; sleep 1; done
echo "wordpress ready"
exec php -S 0.0.0.0:8390 -t /var/www/html /wpfixtures/wp-router.php
