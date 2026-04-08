#!/bin/bash

REPO="/root/rcm"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

# نسخ الملفات للمستودع
cp -r /etc/asterisk/. "$REPO/asterisk/"
cp -r /var/www/html/. "$REPO/html/"
cp -r /usr/local/bin/rcm* "$REPO/bin/" 2>/dev/null

# Push لو في تغييرات
cd "$REPO"
git add -A

if ! git diff --cached --quiet; then
    git commit -m "Auto backup: $TIMESTAMP"
    git push origin php
    echo "[$TIMESTAMP] Pushed successfully"
else
    echo "[$TIMESTAMP] No changes"
fi
