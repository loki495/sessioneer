# Frontend Build and Type-Checking

This document covers the frontend tooling: CSS (Tailwind), JavaScript transpilation, and type-checking setup.

## CSS Build (Tailwind)

Sessioneer uses Tailwind CSS v4 with a native CSS engine build (not PostCSS). The build is configured in `tailwind.config.js` and runs via npm:

```bash
npm run build       # Production build (minified, optimized)
npm run dev         # Watch mode (rebuilds on file change)
```

**Important:** Tailwind's native engine requires Node 20+. Check your installed version with `node --version`.

The output CSS is written to `public/styles.css`, which is referenced in `resources/views/app.blade.php`. This build step is part of the Docker setup and runs automatically when the container starts.

### Why Plain CSS in JS

The frontend JavaScript (`public/js/*.js`) is deliberately **plain ES5** — no `const`/`let`/arrow functions/template literals. Mobile Safari compatibility is the reason, since this is a PWA meant to be added to an iOS/Android home screen.

## Type-Checking Frontend JavaScript (JSDoc + TypeScript Compiler)

The frontend is type-checked without a transpiler using JSDoc annotations + the TypeScript compiler in "check JS only" mode:

```bash
npm run type-check
```

This validates type consistency in `public/js/*.js` against JSDoc type comments, catching common mistakes early without needing to build/transpile. Configuration lives in `tsconfig.json` with `allowJs: true` and `checkJs: true`.

**Example JSDoc type annotation:**
```javascript
/**
 * Fetch the current session list
 * @param {string} sessionName - The session identifier
 * @returns {Promise<Session[]>} Array of session objects
 */
async function fetchSessions(sessionName) { ... }
```

This approach keeps the runtime code vanilla ES5 (for mobile Safari) while still catching type mistakes before they reach users.

## Frontend Testing

Frontend tests are integration tests that exercise the full stack (container + host agent). See [CONTRIBUTING.md](../CONTRIBUTING.md#running-tests) for test commands.
