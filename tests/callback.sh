#!/bin/bash
# Sends a callback signed with the throwaway key in tests/keys, like TrezorPay would.
# tests/callback.sh <order_id> <pending 1|0> <uuid> <value_coin> [address_in] [token]
# Set BAD_SIG=1 to send a wrong signature, NO_FORWARD=1 to leave out txid_out and value_forwarded_coin.
set -e
cd "$(dirname "$0")"
ORDER=$1; PENDING=$2; UUID=$3; VALUE=$4
read -r CB ADDR TICKER TOKEN <<<"$(./wp.sh eval "\$o=wc_get_order($ORDER); echo add_query_arg(['order'=>$ORDER,'ticker'=>str_replace('/','_',\$o->get_meta('_trezorpay_ticker')),'token'=>\$o->get_meta('_trezorpay_token')], rest_url('trezorpay/v1/callback')),' ',\$o->get_meta('_trezorpay_address'),' ',str_replace('/','_',\$o->get_meta('_trezorpay_ticker')),' ',\$o->get_meta('_trezorpay_token');")"
ADDR=${5:-$ADDR}
if [ -n "$6" ]; then CB=${CB/token=$TOKEN/token=$6}; fi
TXIN=in$(echo -n "$UUID" | md5 | cut -c1-20)
Q="uuid=$UUID&address_in=$ADDR&address_out=TJzXZQhbhJB3dA2TmxmvyPxk2nKsmZ6yWf&confirmations=1&txid_in=$TXIN"
if [ "$PENDING" = 0 ] && [ -n "$NO_FORWARD" ]; then Q="$Q&value_coin=$VALUE&coin=$TICKER&price=1&result=sent&pending=0"
elif [ "$PENDING" = 0 ]; then Q="$Q&txid_out=out$(echo -n "$UUID" | md5 | cut -c1-20)&value_coin=$VALUE&value_forwarded_coin=$VALUE&coin=$TICKER&price=1&result=sent&pending=0"
else Q="$Q&value_coin=$VALUE&coin=$TICKER&price=1&result=pending&pending=1"; fi
URL="$CB&$Q"
SIG=$(printf '%s' "$URL" | openssl dgst -sha256 -sign keys/private.pem | base64)
[ -n "$BAD_SIG" ] && SIG=$(printf '%s' "$URL-tampered" | openssl dgst -sha256 -sign keys/private.pem | base64)
curl -s -w ' [HTTP %{http_code}]\n' -H "x-ca-signature: $SIG" "$URL"
