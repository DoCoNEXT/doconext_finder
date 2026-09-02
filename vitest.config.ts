import { defineConfig } from 'vitest/config'

/**
 * Frontend unit tests — pure utils/composable functions only (no component
 * rendering yet). Two @nextcloud/* modules are stubbed because they read the
 * page's initial state / l10n registry at import time, which doesn't exist in
 * a node test run; the functions under test don't depend on their real values.
 */
export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/frontend/**/*.test.ts'],
    alias: {
      '@nextcloud/initial-state': new URL('./tests/frontend/stubs/initial-state.ts', import.meta.url).pathname,
      '@nextcloud/l10n': new URL('./tests/frontend/stubs/l10n.ts', import.meta.url).pathname,
    },
  },
})
