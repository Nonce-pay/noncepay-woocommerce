#!/bin/bash
# Places an order through the classic (shortcode) checkout: tests/classic-checkout.sh <coin> [product_id]
set -e
cd "$(dirname "$0")"
B=http://localhost:8088; J=$(mktemp); COIN=${1:-trc20/usdt}; PRODUCT=${2:-10}
curl -s -c $J -b $J "$B/?add-to-cart=$PRODUCT" -o /dev/null
NONCE=$(curl -s -c $J -b $J "$B/classic-checkout/" | grep -o 'name="woocommerce-process-checkout-nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')
curl -s -c $J -b $J "$B/?wc-ajax=checkout" \
  --data-urlencode "billing_first_name=Ada" --data-urlencode "billing_last_name=Lovelace" \
  --data-urlencode "billing_country=FR" --data-urlencode "billing_address_1=1 rue Test" \
  --data-urlencode "billing_city=Paris" --data-urlencode "billing_postcode=75001" \
  --data-urlencode "billing_email=ada@example.com" --data-urlencode "billing_phone=0600000000" \
  --data-urlencode "payment_method=noncepay" --data-urlencode "noncepay_coin=$COIN" \
  --data-urlencode "terms=1" --data-urlencode "woocommerce-process-checkout-nonce=$NONCE" \
  --data-urlencode "_wp_http_referer=/classic-checkout/"
echo
rm -f $J
