import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import {
  ApiError,
  fetchProducts,
  formatMoney,
  placeOrder,
  type Order,
  type Product,
} from './api'
import { currentCustomerId, resetCustomerId } from './customer'

interface Line {
  productId: string
  quantity: number
}

const emptyLine: Line = { productId: '', quantity: 1 }

export function NewOrder() {
  const [products, setProducts] = useState<Product[]>([])
  const [loadError, setLoadError] = useState<string | null>(null)
  const [customerId, setCustomerId] = useState(currentCustomerId)
  const [lines, setLines] = useState<Line[]>([emptyLine])
  const [submitting, setSubmitting] = useState(false)
  const [placed, setPlaced] = useState<Order | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    fetchProducts()
      .then(({ products }) => {
        if (!cancelled) setProducts(products)
      })
      .catch((e: unknown) => {
        if (!cancelled) setLoadError(e instanceof ApiError ? e.message : String(e))
      })

    return () => {
      cancelled = true
    }
  }, [])

  const byId = new Map(products.map((p) => [p.id, p]))
  const filled = lines.filter((line) => line.productId !== '')
  const total = filled.reduce((sum, line) => {
    const product = byId.get(line.productId)
    return product === undefined ? sum : sum + product.price * line.quantity
  }, 0)

  function updateLine(index: number, patch: Partial<Line>) {
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setPlaced(null)

    if (filled.length === 0) {
      setError('Alege cel puțin un produs.')
      return
    }

    // The domain rejects a duplicated product in one order; catching it here
    // saves a round trip and gives a clearer message.
    const ids = new Set(filled.map((line) => line.productId))
    if (ids.size !== filled.length) {
      setError('Același produs apare de mai multe ori. Folosește o singură linie per produs.')
      return
    }

    setSubmitting(true)

    try {
      const order = await placeOrder(customerId, filled)
      setPlaced(order)
      setLines([emptyLine])
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : String(e))
    } finally {
      setSubmitting(false)
    }
  }

  if (loadError !== null) {
    return <p className="error">Nu am putut încărca produsele: {loadError}</p>
  }

  return (
    <form onSubmit={submit} className="card">
      <label className="field">
        <span>Client</span>
        <div className="row">
          <input
            value={customerId}
            onChange={(e) => setCustomerId(e.target.value)}
            spellCheck={false}
          />
          <button type="button" onClick={() => setCustomerId(resetCustomerId())}>
            Client nou
          </button>
        </div>
        <small>UUID generat automat și memorat în browser.</small>
      </label>

      <fieldset>
        <legend>Produse</legend>

        {lines.map((line, index) => {
          const product = byId.get(line.productId)

          return (
            <div className="row line" key={index}>
              <select
                value={line.productId}
                onChange={(e) => updateLine(index, { productId: e.target.value })}
              >
                <option value="">— alege un produs —</option>
                {products.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name} · {formatMoney(p.price, p.currency)} · stoc {p.stockQuantity}
                  </option>
                ))}
              </select>

              <input
                type="number"
                min={1}
                max={product?.stockQuantity ?? undefined}
                value={line.quantity}
                onChange={(e) => updateLine(index, { quantity: Math.max(1, e.target.valueAsNumber || 1) })}
              />

              <span className="subtotal">
                {product === undefined ? '—' : formatMoney(product.price * line.quantity, product.currency)}
              </span>

              <button
                type="button"
                className="ghost"
                disabled={lines.length === 1}
                onClick={() => setLines((current) => current.filter((_, i) => i !== index))}
                aria-label="Șterge linia"
              >
                ✕
              </button>
            </div>
          )
        })}

        <button type="button" onClick={() => setLines((current) => [...current, { ...emptyLine }])}>
          + Adaugă produs
        </button>
      </fieldset>

      <div className="row total">
        <strong>Total: {formatMoney(total, 'RON')}</strong>
        <button type="submit" disabled={submitting || products.length === 0}>
          {submitting ? 'Se trimite…' : 'Plasează comanda'}
        </button>
      </div>

      {error !== null && <p className="error">{error}</p>}

      {placed !== null && (
        <div className="success">
          <p>
            Comandă plasată — status <strong>{placed.status}</strong>
          </p>
          <dl>
            <dt>ID</dt>
            <dd>
              <code>{placed.id}</code>
            </dd>
            <dt>Total</dt>
            <dd>{formatMoney(placed.total, placed.currency)}</dd>
          </dl>
          <Link to="/orders">Vezi toate comenzile →</Link>
        </div>
      )}
    </form>
  )
}
