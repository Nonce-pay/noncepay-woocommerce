#!/bin/bash
# Builds the distributable plugin into dist/noncepay-for-woocommerce and dist/noncepay-for-woocommerce.zip,
# leaving out everything listed in .distignore.
set -e
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/noncepay-for-woocommerce
rsync -a --exclude-from=.distignore --exclude=dist ./ dist/noncepay-for-woocommerce/
(cd dist && zip -qr noncepay-for-woocommerce.zip noncepay-for-woocommerce)
echo "dist/noncepay-for-woocommerce.zip"
