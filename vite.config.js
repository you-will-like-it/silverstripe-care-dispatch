import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

/**
 * Builds two independent bundles into client/dist (exposed via composer "extra.expose"):
 *
 *   board.js / board.css   – React PDL board, mounted on #dispatch-app-root
 *   mobile.js / mobile.css – Alpine.js component for the field staff view
 *
 * Entry files keep stable names because Silverstripe's Requirements API references them
 * directly (it appends ?m=<mtime> for cache busting). Shared chunks are content-hashed.
 */
export default defineConfig(({ mode }) => ({
  plugins: [react(), tailwindcss()],
  base: './',
  publicDir: false,
  define: {
    'process.env.NODE_ENV': JSON.stringify(mode === 'development' ? 'development' : 'production'),
  },
  build: {
    outDir: resolve(import.meta.dirname, 'client/dist'),
    emptyOutDir: true,
    sourcemap: mode === 'development',
    target: 'es2022',
    manifest: false,
    rolldownOptions: {
      input: {
        board: resolve(import.meta.dirname, 'client/src/board/main.jsx'),
        mobile: resolve(import.meta.dirname, 'client/src/mobile/main.js'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: '[name][extname]',
      },
    },
  },
}));
