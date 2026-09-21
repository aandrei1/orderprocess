#!/usr/bin/env bash
#
# Places N random orders by running `bin/console app:place-order` N times,
# to exercise the real flow: stock -> payment -> outbox -> events.
# Run inside the php container:  make seed-orders n=50 seed=123
#
# Products must already exist: make seed-products

set -euo pipefail

COUNT="${1:-10}"
SEED="${2:-}"

if ! [[ "$COUNT" =~ ^[0-9]+$ ]] || [[ "$COUNT" -lt 1 ]]; then
    echo "n must be a positive integer, got: '$COUNT'" >&2
    exit 1
fi

if [[ -z "$SEED" ]]; then
    SEED="$(od -An -N2 -tu2 < /dev/urandom | tr -d ' ')"
fi
RANDOM="$SEED"

rand_hex() {
    local n="$1" out="" i
    for ((i = 0; i < n; i++)); do
        out+=$(printf '%x' $((RANDOM % 16)))
    done
    printf '%s' "$out"
}

rand_uuid() {
    local variant="89ab"
    printf '%s-%s-4%s-%s%s-%s' \
        "$(rand_hex 8)" "$(rand_hex 4)" "$(rand_hex 3)" \
        "${variant:$((RANDOM % 4)):1}" "$(rand_hex 3)" "$(rand_hex 12)"
}

# Fetch the existing products from the DB; extract the UUIDs from the table dbal:run-sql prints.
mapfile -t PRODUCTS < <(
    php bin/console dbal:run-sql --no-ansi "SELECT id FROM products WHERE stock_quantity > 0" \
        | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
)

if [[ ${#PRODUCTS[@]} -eq 0 ]]; then
    echo "No products with stock in the DB. Run first: make seed-products" >&2
    exit 1
fi

echo "Placing $COUNT orders from ${#PRODUCTS[@]} products (seed=$SEED)..."

placed=0
failed=0

for ((i = 1; i <= COUNT; i++)); do
    customer="$(rand_uuid)"

    # 1..3 distinct products per order: partial Fisher-Yates on a copy of the list.
    pool=("${PRODUCTS[@]}")
    line_count=$((1 + RANDOM % 3))
    [[ $line_count -gt ${#pool[@]} ]] && line_count=${#pool[@]}

    items=()
    for ((k = 0; k < line_count; k++)); do
        pick=$((k + RANDOM % (${#pool[@]} - k)))
        tmp="${pool[$k]}"
        pool[$k]="${pool[$pick]}"
        pool[$pick]="$tmp"
        items+=("${pool[$k]}:$((1 + RANDOM % 5))")
    done

    if output=$(php bin/console app:place-order "$customer" "${items[@]}" 2>&1); then
        placed=$((placed + 1))
        printf '  [%d/%d] ok   %s\n' "$i" "$COUNT" "$(echo "$output" | tail -n 1)"
    else
        failed=$((failed + 1))
        # The first non-empty line of the error is enough (insufficient stock, payment declined, etc).
        reason=$(echo "$output" | grep -vE '^\s*$' | head -n 1)
        printf '  [%d/%d] fail %s\n' "$i" "$COUNT" "$reason"
    fi
done

echo
echo "Done: $placed placed, $failed failed (seed=$SEED)"

[[ $failed -eq 0 ]]
