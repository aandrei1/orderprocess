import { useEffect, useState } from 'react'
import { ApiError, fetchOrders, formatDate, formatMoney, type OrderPage } from './api'

const PER_PAGE = 20

export function OrderList() {
  const [page, setPage] = useState(1)
  const [data, setData] = useState<OrderPage | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    setLoading(true)

    fetchOrders(page, PER_PAGE)
      .then((result) => {
        if (!cancelled) {
          setData(result)
          setError(null)
        }
      })
      .catch((e: unknown) => {
        if (!cancelled) setError(e instanceof ApiError ? e.message : String(e))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [page])

  if (error !== null) {
    return <p className="error">{error}</p>
  }

  if (data === null) {
    return <p className="muted">Se încarcă…</p>
  }

  const lastPage = Math.max(1, Math.ceil(data.total / data.perPage))

  return (
    <div className="card">
      <p className="muted">
        {data.total} {data.total === 1 ? 'comandă' : 'comenzi'} · pagina {data.page} din {lastPage}
        {loading && ' · se actualizează…'}
      </p>

      {data.orders.length === 0 ? (
        <p className="muted">Nicio comandă încă.</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Plasată</th>
              <th>ID</th>
              <th>Status</th>
              <th>Produse</th>
              <th className="numeric">Total</th>
            </tr>
          </thead>
          <tbody>
            {data.orders.map((order) => (
              <tr key={order.id}>
                <td>{formatDate(order.placedAt)}</td>
                <td>
                  <code title={order.id}>{order.id.slice(0, 8)}</code>
                </td>
                <td>
                  <span className={`status status-${order.status}`}>{order.status}</span>
                </td>
                <td>
                  <ul className="items">
                    {order.items.map((item) => (
                      <li key={item.productId}>
                        {item.quantity} × {item.productName}
                      </li>
                    ))}
                  </ul>
                </td>
                <td className="numeric">{formatMoney(order.total, order.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <div className="row pager">
        <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
          ← Anterioara
        </button>
        <button type="button" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>
          Următoarea →
        </button>
      </div>
    </div>
  )
}
