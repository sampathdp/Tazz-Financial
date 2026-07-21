import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      // 127.0.0.1, not localhost: on Windows `localhost` resolves to ::1 (IPv6)
      // first, so any unrelated server on [::1]:8000 would silently shadow this
      // project's backend (which `npm run dev:backend` binds to 127.0.0.1:8000).
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
    headers: {
      'X-Content-Type-Options': 'nosniff',
      'X-Frame-Options': 'DENY',
      'Referrer-Policy': 'strict-origin-when-cross-origin',
    },
  },
  build: {
    target: 'es2020',
    sourcemap: false,
    cssCodeSplit: true,
    chunkSizeWarningLimit: 600,
    minify: 'esbuild',
    reportCompressedSize: false,
    rollupOptions: {
      output: {
        // Pin large vendor deps to their own long-cached chunks so app code
        // changes don't bust the React bundle in the browser cache.
        manualChunks: {
          react: ['react', 'react-dom'],
          axios: ['axios'],
          xlsx: ['xlsx'],
        },
      },
    },
  },
  esbuild: {
    // strip console + debugger calls from production bundle
    drop: ['console', 'debugger'],
    legalComments: 'none',
  },
})
