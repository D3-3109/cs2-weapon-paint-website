import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

function qrQueryRoute() {
  return {
    name: 'qr-query-route',
    configureServer(server) {
      server.middlewares.use((req, _res, next) => {
        if (req.url?.startsWith('/?qr=')) {
          req.url = `/qr.php${req.url.slice(1)}`
        }
        next()
      })
    },
  }
}

export default defineConfig({
  plugins: [qrQueryRoute(), react()],
  server: {
    host: true,
    port: 5173,
    fs: {
      allow: [path.resolve(__dirname, '..')],
    },
    proxy: {
      '/images_qr': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
      '/qr.php': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
      // Lokalne testy: /api/* → PHP built-in server (pnpm run dev)
      '/api': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
        cookieDomainRewrite: '',
        rewrite: (p) => p.replace(/^\/api/, ''),
      },
      // LielXD 3D models/textures/HDRI - same-origin proxy (CORS-safe, streamed)
      '/lielxd': {
        target:
          'https://raw.githubusercontent.com/LielXD/CS2-WeaponPaints-Website/refs/heads/main/src',
        changeOrigin: true,
        secure: true,
        rewrite: (p) => p.replace(/^\/lielxd/, ''),
      },
    },
  },
  build: {
    outDir: path.resolve(__dirname, '../dist'),
    emptyOutDir: true,
  },
})
