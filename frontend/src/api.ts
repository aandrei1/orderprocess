/**
 * The API contract, mirrored from src/Orders/UI/Controller/.
 * All money is an integer number of bani (RON cents), like Money on the PHP side.
 */

export type OrderStatus = 'placed' | 'paid' | 'cancelled'

export interface Product {
  id: string
  name: string
  price: number
  currency: string
  stockQuantity: number
}

export interface OrderItem {
  productId: string
  productName: string
  quantity: number
  unitPrice: number
  subtotal: number
}

export interface Order {
  id: string
  customerId: string
  status: OrderStatus
  total: number
  currency: string
  placedAt: string
  paidAt: string | null
  items: OrderItem[]
}

export interface OrderPage {
  orders: Order[]
  total: number
  page: number
  perPage: number
}

export interface OrderLineRequest {
  productId: string
  quantity: number
}

/** An error the API answered with, carrying the status so the UI can tell apart
 *  a rejected order (409) from a malformed request (400). */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  let response: Response

  try {
    response = await fetch(path, {
      ...init,
      headers: { 'Content-Type': 'application/json', ...init?.headers },
    })
  } catch {
    throw new ApiError('Serverul nu răspunde. Rulează `make up`?', 0)
  }

  const body: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    const message =
      body !== null && typeof body === 'object' && 'error' in body && typeof body.error === 'string'
        ? body.error
        : `Eroare HTTP ${response.status}`

    throw new ApiError(message, response.status)
  }

  return body as T
}

export function fetchProducts(): Promise<{ products: Product[] }> {
  return request('/api/products')
}

export function fetchOrders(page: number, perPage: number): Promise<OrderPage> {
  return request(`/api/orders?page=${page}&perPage=${perPage}`)
}

export function placeOrder(customerId: string, items: OrderLineRequest[]): Promise<Order> {
  return request('/api/orders', {
    method: 'POST',
    body: JSON.stringify({ customerId, items }),
  })
}

/** 12085 -> "120,85 RON" */
export function formatMoney(amount: number, currency: string): string {
  return `${(amount / 100).toLocaleString('ro-RO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })} ${currency}`
}

export function formatDate(iso: string): string {
  return new Date(iso).toLocaleString('ro-RO')
}
