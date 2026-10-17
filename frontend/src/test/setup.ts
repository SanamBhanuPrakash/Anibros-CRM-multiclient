/**
 * Vitest setup. Intentionally minimal: server suites bring their own disposable databases (tests/support/test-db.ts);
 * component tests import jest-dom matchers themselves.
 */
if (typeof window !== 'undefined') {
  window.alert = () => {};
}
