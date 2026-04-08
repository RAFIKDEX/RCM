#!/bin/bash

REPO="/root/rcm"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

# نسخ الملفات للمستودع
cp -r /etc/asterisk/. "$REPO/asterisk/"
cp -r /var/www/html/. "$REPO/html/"

# تنظيف فولدر bin ثم نسخ ملفات rcm فقط
rm -rf "$REPO/bin/"*
cp -r /usr/local/bin/rcm* "$REPO/bin/" 2>/dev/null

# الذهاب للريبو
cd "$REPO" || exit

# إضافة التغييرات
git add -A

# عمل commit و push لو في تغييرات
if ! git diff --cached --quiet; then
    git commit -m "Auto backup: $TIMESTAMP"
    git push origin php
    echo "[$TIMESTAMP] Pushed successfully"
else
    echo "[$TIMESTAMP] No changes"
fi
