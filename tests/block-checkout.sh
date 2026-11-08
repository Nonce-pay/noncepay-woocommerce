#!/bin/bash
# Places an order through the Store API used by the Checkout block: tests/block-checkout.sh <coin> [product_id]
set -e
cd "$(dirname "$0")"
B=http://localhost:8088/wp-json/wc/store/v1; J=$(mktemp); H=$(mktemp); COIN=${1:-sol/usdc}; PRODUCT=${2:-10}
curl -s -c $J -b $J -D $H "$B/cart" -o /dev/null
NONCE=$(grep -i '^nonce:' $H | tr -d '\r' | awk '{print $2}')
curl -s -c $J -b $J -H "Nonce: $NONCE" -H 'Content-Type: application/json' "$B/cart/add-item" -d "{\"id\":$PRODUCT,\"quantity\":1}" -o /dev/null
ADDR='{"first_name":"Grace","last_name":"Hopper","address_1":"1 rue Test","city":"Paris","postcode":"75001","country":"FR","email":"grace@example.com","phone":"0600000000"}'
curl -s -c $J -b $J -H "Nonce: $NONCE" -H 'Content-Type: application/json' "$B/checkout" \
  -d "{\"billing_address\":$ADDR,\"shipping_address\":$ADDR,\"payment_method\":\"trezorpay\",\"payment_data\":[{\"key\":\"trezorpay_coin\",\"value\":\"$COIN\"}]}" \
  | python3 -c 'import json,sys; d=json.load(sys.stdin); print(json.dumps({k:d.get(k) for k in ("order_id","status","payment_result","code","message")}))'
rm -f $J $H
