import { resolve } from 'node:path'
import { defineConfig } from 'vitest/config'

// Test config for the Pageflow UI. Deps (vitest, jsdom) are installed by the
// project scaffold command at ship time; run with `npx vitest`.
export default defineConfig({
  resolve: {
    alias: {
      '@pageflow/core': resolve(__dirname, 'core'),
    },
  },
  test: {
    // jsdom gives us window/document/history for the DOM-touching helpers.
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    globals: true,
  },
})
