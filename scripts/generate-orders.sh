#!/usr/bin/env bash
#
# Generates mixed order traffic so the Kibana "Orders" dashboard has something
# to show: successful orders, insufficient stock, unknown products, over the
# CLI (docker exec op-php php bin/console app:place-order) and over HTTP
# (POST localhost:8000/api/orders, with and without X-Correlation-Id).
#
# Run on the host:  make generate-orders n=30
#
# DEV ONLY - CONSUMES STOCK from the dev database. Hard limits:
#   - at most MAX_ORDERS (100) requests per run, whatever n is
#   - every successful order buys 1 or 2 units of one product
#     => at most 2 * 100 = 200 units per run, from products with stock > 5
#   - the "insufficient stock" requests ask for 100000 units and never succeed

set -euo pipefail

CONTAINER="${PHP_CONTAINER:-op-php}"
API="${API_URL:-http://localhost:8000/api/orders}"
CUSTOMER="33333333-3333-3333-3333-333333333333"
MAX_ORDERS=100
COUNT="${1:-30}"

if ! [[ "$COUNT" =~ ^[0-9]+$ ]] || [[ "$COUNT" -lt 1 ]]; then
    echo "n must be a positive integer, got: '$COUNT'" >&2
    exit 1
fi
if [[ "$COUNT" -gt "$MAX_ORDERS" ]]; then
    echo "n capped at $MAX_ORDERS (was $COUNT) to protect dev stock." >&2
    COUNT="$MAX_ORDERS"
fi

mapfile -t PRODUCTS < <(
    docker exec "$CONTAINER" php bin/console dbal:run-sql --no-ansi \
        "SELECT id FROM products WHERE stock_quantity > 5 LIMIT 5" \
        | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
)
if [[ ${#PRODUCTS[@]} -eq 0 ]]; then
    echo "No products with stock > 5. Run first: make seed-products" >&2
    exit 1
fi

UNKNOWN="99999999-9999-4999-8999-999999999999"
echo "Sending $COUNT requests over ${#PRODUCTS[@]} products (max ${MAX_ORDERS} requests, 2 units per success)..."

declare -A tally=()
note() { tally[$1]=$(( ${tally[$1]:-0} + 1 )); }

for ((i = 1; i <= COUNT; i++)); do
    product="${PRODUCTS[$((RANDOM % ${#PRODUCTS[@]}))]}"
    qty=$((1 + RANDOM % 2))
    roll=$((RANDOM % 100))

    # Mix: 40% CLI ok, 10% CLI no stock, 10% CLI unknown product,
    #      20% HTTP ok (with correlation id), 10% HTTP ok (without),
    #      5% HTTP no stock, 5% HTTP unknown product.
    if   [[ $roll -lt 40 ]]; then kind=cli-ok;      args="$product:$qty"
    elif [[ $roll -lt 50 ]]; then kind=cli-stock;   args="$product:100000"
    elif [[ $roll -lt 60 ]]; then kind=cli-unknown; args="$UNKNOWN:1"
    elif [[ $roll -lt 80 ]]; then kind=http-cid-ok; args="$product:$qty"
    elif [[ $roll -lt 90 ]]; then kind=http-ok;     args="$product:$qty"
    elif [[ $roll -lt 95 ]]; then kind=http-stock;  args="$product:100000"
    else                          kind=http-unknown; args="$UNKNOWN:1"
    fi

    pid="${args%%:*}"
    q="${args##*:}"
    case "$kind" in
        cli-*)
            if docker exec "$CONTAINER" php bin/console app:place-order "$CUSTOMER" "$args" >/dev/null 2>&1; then
                result=ok
            else
                result=refused
            fi
            ;;
        http-*)
            headers=(-H 'Content-Type: application/json')
            [[ "$kind" == http-cid-ok ]] && headers+=(-H "X-Correlation-Id: gen-$(date +%s)-$i")
            code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API" "${headers[@]}" \
                -d "{\"customerId\":\"$CUSTOMER\",\"items\":[{\"productId\":\"$pid\",\"quantity\":$q}]}")
            result="http$code"
            ;;
    esac
    note "$kind/$result"
done

echo "Done:"
for k in "${!tally[@]}"; do printf '  %-24s %s\n' "$k" "${tally[$k]}"; done | sort
