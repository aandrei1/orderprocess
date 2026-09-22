import { NavLink, Navigate, Route, Routes } from 'react-router'
import { NewOrder } from './NewOrder'
import { OrderList } from './OrderList'

/**
 * Two real URLs rather than a tab in component state: back/forward work, a
 * reload stays on the same page, and the order list can be linked to.
 *
 * Switching route unmounts the previous page, so OrderList refetches every
 * time it is opened — a freshly placed order is always visible.
 */
export function App() {
  return (
    <div className="app">
      <header>
        <h1>Order Process</h1>
        <nav>
          <NavLink to="/" className={({ isActive }) => (isActive ? 'tab active' : 'tab')} end>
            Comandă nouă
          </NavLink>
          <NavLink to="/orders" className={({ isActive }) => (isActive ? 'tab active' : 'tab')}>
            Comenzi existente
          </NavLink>
        </nav>
      </header>

      <main>
        <Routes>
          <Route path="/" element={<NewOrder />} />
          <Route path="/orders" element={<OrderList />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
    </div>
  )
}
