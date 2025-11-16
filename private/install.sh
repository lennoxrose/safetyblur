#!/bin/bash

echo -e "\n\x1b[34;1m                     
          ▄█████  ▄▄▄  ▄▄▄▄▄ ▄▄▄▄▄ ▄▄▄▄▄▄ ▄▄ ▄▄ 
          ▀▀▀▄▄▄ ██▀██ ██▄▄  ██▄▄    ██   ▀███▀ 
          █████▀ ██▀██ ██    ██▄▄▄   ██     █ 

       Thanks for Purchasing and Using Safety Blur!
\x1b[0m"

# Fix controller namespace and class name
CONTROLLER_FILE="$PTERODACTYL_DIRECTORY/app/Http/Controllers/Admin/Extensions/$EXTENSION_IDENTIFIER/${EXTENSION_IDENTIFIER}ExtensionController.php"
if [ -f "$CONTROLLER_FILE" ]; then
    chmod 666 "$CONTROLLER_FILE" 2>/dev/null || true
    sed -i 's|namespace Pterodactyl\\BlueprintFramework\\Controllers\\.*|namespace Pterodactyl\\Http\\Controllers\\Admin\\Extensions\\'$EXTENSION_IDENTIFIER';|' "$CONTROLLER_FILE" 2>/dev/null || true
fi

# Fix route file namespace
ROUTE_FILE="$PTERODACTYL_DIRECTORY/routes/blueprint/web/$EXTENSION_IDENTIFIER.php"
if [ -f "$ROUTE_FILE" ]; then
    chmod 666 "$ROUTE_FILE" 2>/dev/null || true
    sed -i 's|use Pterodactyl\\BlueprintFramework\\Controllers\\.*|use Pterodactyl\\Http\\Controllers\\Admin\\Extensions\\'$EXTENSION_IDENTIFIER'\\'$EXTENSION_IDENTIFIER'ExtensionController;|' "$ROUTE_FILE" 2>/dev/null || true
    sed -i "s|'middleware' => \['auth', 'admin'\]|'middleware' => ['auth']|" "$ROUTE_FILE" 2>/dev/null || true
fi

# Clear Laravel caches
cd "$PTERODACTYL_DIRECTORY"
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true
php artisan config:clear 2>/dev/null || true

echo -e "Configure your blur settings at: \x1b[33m{domain}/admin/extensions/$EXTENSION_IDENTIFIER\x1b[0m\n"
