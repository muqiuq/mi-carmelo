#!/bin/bash
set -euo pipefail

TARGET_HOST="192.168.73.138"
TARGET_DIR="/var/www/html/functions/mi-carmelo"

echo "Deploying to ${TARGET_HOST}:${TARGET_DIR} ..."

ssh "$TARGET_HOST" "mkdir -p ${TARGET_DIR}/data"

rsync -avz --delete --omit-dir-times \
    --include='index.php' \
    --include='sw.js' \
    --include='api/***' \
    --include='css/***' \
    --include='js/***' \
    --include='vendor/***' \
    --include='data/' \
    --include='data/.htaccess' \
    --exclude='*' \
    ./ "${TARGET_HOST}:${TARGET_DIR}/"
 #   --include='data/questions.yaml' \


# Copy game_config.php only if it doesn't already exist on the target
#ssh "$TARGET_HOST" "test -f ${TARGET_DIR}/data/game_config.php" \
#    || rsync -avz data/game_config.php "${TARGET_HOST}:${TARGET_DIR}/data/game_config.php"

# Ensure data directory is writable by web server
# ssh "$TARGET_HOST" "chmod 775 ${TARGET_DIR}/data && chmod 664 ${TARGET_DIR}/data/*.yaml ${TARGET_DIR}/data/game_config.php 2>/dev/null || true"

echo "Done!"
