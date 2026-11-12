#!/bin/sh
set -e
cd /var/www

# ---- New Relic PHP agent settings (generated from env) -------------------
NEW_RELIC_ENABLED="${NEW_RELIC_ENABLED:-true}"
PHP_CONF_DIR="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"
cat > "${PHP_CONF_DIR}/zz-newrelic-override.ini" <<EOF
newrelic.enabled = ${NEW_RELIC_ENABLED}
newrelic.license = "${NEW_RELIC_LICENSE_KEY}"
newrelic.appname = "${NEW_RELIC_APP_NAME:-laravel-octane-nr-agent}"
newrelic.loglevel = "${NEW_RELIC_LOG_LEVEL:-info}"
newrelic.logfile = "/dev/stderr"
newrelic.daemon.address = "/tmp/.newrelic.sock"
; the daemon is started by this script, never by the agent
newrelic.daemon.dont_launch = 3
newrelic.distributed_tracing_enabled = true
newrelic.application_logging.enabled = true
newrelic.application_logging.forwarding.enabled = true
EOF

if [ "${NEW_RELIC_ENABLED}" = "true" ]; then
  if [ -z "${NEW_RELIC_LICENSE_KEY}" ]; then
    echo "[entrypoint] WARN: NEW_RELIC_LICENSE_KEY is empty. Agent data will not be sent." >&2
  fi
  newrelic-daemon --foreground --address /tmp/.newrelic.sock \
    --pidfile /tmp/newrelic-daemon.pid --logfile stdout --loglevel info &
fi

# ---- Laravel -------------------------------------------------------------
if [ -z "${APP_KEY}" ]; then
  php artisan key:generate --force
fi

i=0
# OTel / NR disabled here so startup migrations don't produce orphan spans / transactions
until OTEL_SDK_DISABLED=true php -d newrelic.enabled=false artisan migrate --force; do
  i=$((i + 1))
  if [ "$i" -ge 30 ]; then
    echo "[entrypoint] migration failed" >&2
    exit 1
  fi
  echo "[entrypoint] waiting for DB ($i/30)..."
  sleep 2
done

exec php artisan octane:start \
  --server=swoole \
  --host=0.0.0.0 \
  --port=8000 \
  --workers="${OCTANE_WORKERS:-2}" \
  --task-workers="${OCTANE_TASK_WORKERS:-4}"
