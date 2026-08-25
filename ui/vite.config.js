import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// Builds ../dist/smschat.js as a single IIFE. IIFE (not ESM) is load-bearing:
// CiviCRM's AJAX tab pipeline (CRM_Core_Page::addAjaxResources) skips 'esm'
// resources, so a module build would never execute inside the contact tab.
// Custom-element mode compiles every SFC's <style> into strings that the
// root custom element injects into its shadow root.
export default defineConfig({
  plugins: [vue({ customElement: true })],
  define: { 'process.env.NODE_ENV': JSON.stringify('production') },
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    minify: true,
    sourcemap: false,
    lib: {
      entry: 'src/main.js',
      name: 'SmsChat',
      formats: ['iife'],
      fileName: () => 'smschat.js',
    },
    rollupOptions: {
      output: { assetFileNames: 'smschat.[ext]' },
    },
  },
});
