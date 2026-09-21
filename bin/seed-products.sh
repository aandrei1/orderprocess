#!/usr/bin/env bash
#
# Populates the product catalog, so `app:place-order` has something to order.
# Run inside the php container:  make seed-products n=10 seed=123
#
# Products are inserted directly via dbal:run-sql (a single INSERT, a single
# Symfony boot) — creating a product has no domain logic worth exercising.

set -euo pipefail

COUNT="${1:-10}"
SEED="${2:-}"

if ! [[ "$COUNT" =~ ^[0-9]+$ ]] || [[ "$COUNT" -lt 1 ]]; then
    echo "n must be a positive integer, got: '$COUNT'" >&2
    exit 1
fi

# Without an explicit seed, start from something unpredictable; with a seed, the run is reproducible.
if [[ -z "$SEED" ]]; then
    SEED="$(od -An -N2 -tu2 < /dev/urandom | tr -d ' ')"
fi
RANDOM="$SEED"

NAMES=(
    "Coffee beans" "Green tea" "Dark chocolate" "Acacia honey" "Olive oil"
    "Whole wheat flour" "Basmati rice" "Penne pasta" "Tomato sauce" "Dried beans"
    "Shelled walnuts" "Raw almonds" "Raisins" "Cinnamon" "Black pepper"
)

rand_hex() {
    local n="$1" out="" i
    for ((i = 0; i < n; i++)); do
        out+=$(printf '%x' $((RANDOM % 16)))
    done
    printf '%s' "$out"
}

# UUID v4 generated from $RANDOM, so it respects the seed (uuidgen would ignore the seed).
rand_uuid() {
    local variant="89ab"
    printf '%s-%s-4%s-%s%s-%s' \
        "$(rand_hex 8)" "$(rand_hex 4)" "$(rand_hex 3)" \
        "${variant:$((RANDOM % 4)):1}" "$(rand_hex 3)" "$(rand_hex 12)"
}

VALUES=()
IDS=()

for ((i = 0; i < COUNT; i++)); do
    id="$(rand_uuid)"
    name="${NAMES[$((RANDOM % ${#NAMES[@]}))]} #$((i + 1))"
    stock=$((50 + RANDOM % 451))      # 50..500 units
    threshold=$((5 + RANDOM % 16))    # 5..20
    price=$((1000 + RANDOM % 49001))  # 10.00 .. 500.00 RON, in cents

    IDS+=("$id")
    VALUES+=("('$id', '$name', $stock, $threshold, $price, 1)")
done

# A single INSERT for the whole batch.
SQL="INSERT INTO products (id, name, stock_quantity, min_threshold, price_amount, version) VALUES $(
    IFS=,
    echo "${VALUES[*]}"
)"

php bin/console dbal:run-sql --no-ansi "$SQL" > /dev/null

echo "Inserted $COUNT products (seed=$SEED):"
for id in "${IDS[@]}"; do
    echo "  $id"
done
