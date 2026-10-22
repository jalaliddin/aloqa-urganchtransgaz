/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'

// Two ways to run the dev server:
// - Docker (docker-compose.dev.yml sets VITE_BEHIND_NGINX=true): the app is opened through nginx
//   on APP_PORT, which routes /api itself; the HMR websocket connects back to that port.
// - On the host (`npm run dev`): Vite proxies /api and /sanctum to the running stack
//   (VITE_API_PROXY, default http://localhost:8090). Keep the Host header so Sanctum sees the SPA origin.
const behindNginx = process.env.VITE_BEHIND_NGINX === 'true'
const apiProxy = process.env.VITE_API_PROXY ?? 'http://localhost:8090'

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    allowedHosts: true,
    hmr: behindNginx ? { clientPort: Number(process.env.VITE_HMR_CLIENT_PORT ?? 8090) } : undefined,
    proxy: behindNginx
      ? undefined
      : {
          '/api': { target: apiProxy, changeOrigin: false },
          '/sanctum': { target: apiProxy, changeOrigin: false },
        },
  },
  build: {
    chunkSizeWarningLimit: 1200,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
  },
})
