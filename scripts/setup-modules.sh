#!/bin/bash
set -e

echo "Setting up KitchenPrinter module..."

# Enable module
php artisan module:enable KitchenPrinter

# Publish config
if [ ! -f .env ]; then
  echo "ERROR: .env file not found"
  exit 1
fi

# Add config to .env if not present
if ! grep -q "KITCHEN_PRINTER_ENABLED" .env; then
  echo "" >> .env
  echo "# Kitchen Printer Module" >> .env
  echo "KITCHEN_PRINTER_ENABLED=false" >> .env
  echo "CLOUDPRNT_URL=" >> .env
  echo "CLOUDPRNT_MAC=" >> .env
  echo "KITCHEN_PRINT_TEMPLATE=default" >> .env
fi

# Clear and rebuild caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Rebuild caches
php artisan config:cache
php artisan route:cache

# Composer autoload
composer dump-autoload

echo "✅ KitchenPrinter module setup complete"
