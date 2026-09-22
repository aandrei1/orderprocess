import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// The API is served by the Symfony container on :8000. Proxying /api keeps
// the browser on a single origin, so there is no CORS setup to maintain.
// `server` and `preview` each need their own proxy — Vite does not share it
// between `vite dev` (:5173) and `vite preview` (:4173, the built bundle).
const apiProxy = {
  '/api': {
    target: 'http://localhost:8000',
    changeOrigin: true,
  },
}

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: apiProxy,
  },
  preview: {
    port: 4173,
    proxy: apiProxy,
  },
})
