#!/bin/bash

echo -e "\n\x1b[34;1m                     
          ▄█████  ▄▄▄  ▄▄▄▄▄ ▄▄▄▄▄ ▄▄▄▄▄▄ ▄▄ ▄▄ 
          ▀▀▀▄▄▄ ██▀██ ██▄▄  ██▄▄    ██   ▀███▀ 
          █████▀ ██▀██ ██    ██▄▄▄   ██     █ 

                    See you soon!
\x1b[0m"

# Remove controller file
if [ -d "$PTERODACTYL_DIRECTORY/app/Http/Controllers/Admin/Extensions/$EXTENSION_IDENTIFIER" ]; then
    rmdir "$PTERODACTYL_DIRECTORY/app/Http/Controllers/Admin/Extensions/$EXTENSION_IDENTIFIER" 2>/dev/null || true
fi

# Remove route file
rm -f "$PTERODACTYL_DIRECTORY/routes/blueprint/web/$EXTENSION_IDENTIFIER.php"

# Clear Laravel caches
cd "$PTERODACTYL_DIRECTORY"
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true
php artisan config:clear 2>/dev/null || true

# Restore admin layout from backup if present (prevent broken layout after uninstall)
ADMIN_LAYOUT="$PTERODACTYL_DIRECTORY/resources/views/layouts/admin.blade.php"
if [ -f "$ADMIN_LAYOUT" ]; then
    # Look for backup files named admin.blade.php.backup* and pick the newest
    BACKUP=$(ls -1t "$PTERODACTYL_DIRECTORY/resources/views/layouts/admin.blade.php.backup"* 2>/dev/null | head -n1 || true)
    if [ -n "$BACKUP" ] && [ -f "$BACKUP" ]; then
        echo "Restoring admin layout from backup: $BACKUP"
        cp -f "$BACKUP" "$ADMIN_LAYOUT" 2>/dev/null || echo "Failed to restore backup to $ADMIN_LAYOUT"
        chmod 644 "$ADMIN_LAYOUT" 2>/dev/null || true
    fi
fi