#!/bin/bash
# Prints an order's status, NoncePay totals and notes: tests/order-state.sh <order_id>
cd "$(dirname "$0")"
./wp.sh eval "\$o=wc_get_order($1); echo 'status=',\$o->get_status(),' txid=',\$o->get_transaction_id(),' received=',\$o->get_meta('_noncepay_received'),' expected=',\$o->get_meta('_noncepay_amount'),' confirmed=',json_encode(\$o->get_meta('_noncepay_confirmed_uuids')),PHP_EOL; foreach(array_reverse(wc_get_order_notes(['order_id'=>$1])) as \$n) echo '  - ',\$n->content,PHP_EOL;"
