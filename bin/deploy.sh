#!/usr/bin/env bash
# Заливає код на сервер: bash bin/deploy.sh
# .env, кеш і логи на сервері не перезаписуються. На сервері немає rsync, тому передаємо tar-архівом через ssh.
set -euo pipefail

SSH_KEY="${SSH_KEY:-$HOME/.ssh/universal_key}"
SSH_TARGET="${SSH_TARGET:-wrviczaw@185.156.42.119}"
REMOTE_DIR="${REMOTE_DIR:-public_html/yohanan.rdlab.com.ua}"

cd "$(dirname "$0")/.."

for f in $(find . -name '*.php' -not -path './storage/*'); do
    php -l "$f" > /dev/null
done

COPYFILE_DISABLE=1 tar --no-xattrs -czf - \
    --exclude='./.env' \
    --exclude='./wav' \
    --exclude='./storage/cache' \
    --exclude='./storage/logs/*' \
    --exclude='*.sqlite*' \
    --exclude='.DS_Store' \
    --exclude='./.idea' \
    --exclude='./.git' \
    . | ssh -i "$SSH_KEY" -o BatchMode=yes "$SSH_TARGET" "
        set -e
        cd $REMOTE_DIR
        tar -xzf - --no-same-owner 2>&1 | grep -v 'Ignoring unknown extended header' || true
        find . -path ./storage -prune -o -type d -exec chmod 755 {} +
        find . -path ./storage -prune -o -type f -not -name .env -exec chmod 644 {} +
        rm -f storage/cache/contentful.json
        echo 'Залито. Що бачить бот на сервері:'
        echo
        php bin/check-tracks.php 2>&1 | grep -v 'Unsuccessful stat' || true
    "
