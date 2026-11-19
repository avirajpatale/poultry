import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      // same-origin API calls in dev → no CORS/cookie issues
      '/api': { target: 'http://localhost:8000', changeOrigin: false },
    },
  },
})
