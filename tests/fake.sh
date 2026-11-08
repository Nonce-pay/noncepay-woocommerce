#!/bin/bash
# tests/fake.sh <amount> <minimum> <expires_in_seconds>: prints "<order_id> <order_key>"
cd "$(dirname "$0")" && ./wp.sh eval-file wp-content/plugins/trezorpay-for-woocommerce/tests/fake-order.php "$@" 2>/dev/null | grep -v sendmail
