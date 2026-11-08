#!/bin/bash
# Builds the distributable plugin into dist/trezorpay-for-woocommerce and dist/trezorpay-for-woocommerce.zip,
# leaving out everything listed in .distignore.
set -e
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/trezorpay-for-woocommerce
rsync -a --exclude-from=.distignore --exclude=dist ./ dist/trezorpay-for-woocommerce/
(cd dist && zip -qr trezorpay-for-woocommerce.zip trezorpay-for-woocommerce)
echo "dist/trezorpay-for-woocommerce.zip"
