import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

const apiTarget = process.env.VITE_PROXY_TARGET ?? 'http://127.0.0.1:4200';

// https://vitejs.dev/config/
export default defineConfig({
  base: './',
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: Number(process.env.VITE_PORT ?? 5274),
    strictPort: true,
    proxy: {
      '/api': { target: apiTarget, changeOrigin: false, ws: true },
      // Lead-source receiving addresses (so a tunnel to the dev server also exposes /hooks/<id>)
      '/hooks': { target: apiTarget, changeOrigin: false },
    },
  },
  optimizeDeps: {
    // Crawl ALL source up-front so every dependency (incl. MUI deep imports used by legacy pages) is pre-bundled once.
    // Without this Vite discovers deps lazily per page, re-optimises mid-session and floods the browser with module requests.
    entries: ['index.html', 'src/**/*.{ts,tsx}'],
  },
  build: {
    outDir: '../public',
    emptyOutDir: false,
    sourcemap: true,
    rollupOptions: {
      output: {
        // Stable vendor chunks: app code changes often, framework code rarely → better long-term caching.
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          mui: ['@mui/material', '@mui/system', '@emotion/react', '@emotion/styled'],
          query: ['@tanstack/react-query'],
        },
      },
    },
  },
});
