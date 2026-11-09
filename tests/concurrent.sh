#!/bin/bash
# Fires pre-signed callbacks in parallel: tests/concurrent.sh <order_id> <uuid:value>...
cd "$(dirname "$0")"
ORDER=$1; shift
read -r CB ADDR TICKER <<<"$(./wp.sh eval "\$o=wc_get_order($ORDER); echo add_query_arg(['order'=>$ORDER,'ticker'=>str_replace('/','_',\$o->get_meta('_noncepay_ticker')),'token'=>\$o->get_meta('_noncepay_token')], rest_url('noncepay/v1/callback')),' ',\$o->get_meta('_noncepay_address'),' ',str_replace('/','_',\$o->get_meta('_noncepay_ticker'));")"
for pair in "$@"; do
  UUID=${pair%%:*}; VALUE=${pair#*:}
  URL="$CB&uuid=$UUID&address_in=$ADDR&txid_in=tx$UUID&txid_out=out$UUID&value_coin=$VALUE&value_forwarded_coin=$VALUE&coin=$TICKER&pending=0"
  SIG=$(printf '%s' "$URL" | openssl dgst -sha256 -sign keys/private.pem | base64)
  curl -s -o /dev/null -w "$UUID %{http_code}\n" -H "x-ca-signature: $SIG" "$URL" &
done
wait
