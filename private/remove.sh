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