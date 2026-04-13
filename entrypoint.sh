#!/bin/bash
set -e

# When running with a volume mount (development on macOS), we cannot chown
# because podman rootless doesn't allow ownership changes on bind mounts.
# Use chmod to make writable directories/files world-accessible instead.
if [ -d /var/www/html/api ]; then
    mkdir -p /var/www/html/data
    chmod 777 /var/www/html/data
    chmod 666 /var/www/html/data/* 2>/dev/null || true
    # Set umask so all new files (DB, vapid.json) are world-writable
    umask 000
fi

exec "$@"
