# Frontend: JS type-checking

Nothing needs building to run the app. `public/js/*.js` is plain, unbundled ES5
and the CSS is hand-written (`public/css/*.css`). `npm` is a dev-only tool for
whoever changes JS.

```bash
npm install          # once
npm run typecheck    # JSDoc type-check of public/js/*.js (tsc --noEmit)
```

## JavaScript is plain ES5

`public/js/*.js` uses `var` and `function`: no `const`/`let`, arrow functions,
`Set` or template literals. There's no transpiler, and mobile Safari
compatibility (this is a home-screen PWA) has repeatedly been the reason.

## CSS

Hand-written around custom properties (design tokens at the top of `public/css/v2.css`); there is no build step and no CSS framework.

## Type-checking the JS (JSDoc + tsc)

`npm run typecheck` runs `tsc --noEmit` over the plain `.js` files (see
`tsconfig.json`): no build step and no transpilation. `// @ts-check` at the top
of each file only signals the editor and the CLI. `public/js/types.d.ts`
declares the few globals the app adds itself.
`public/sw.js` is excluded: a service worker runs in a different global scope
and would need its own tsconfig with the `webworker` lib.

```javascript
/**
 * @param {string} id
 * @returns {string}
 */
function inputValue(id) {
    var input = /** @type {HTMLInputElement} */ (document.getElementById(id));
    return input.value;
}
```

The check doesn't report zero errors yet, and that's expected. Most findings
are DOM lookups typed too loosely (`getElementById()` returning `HTMLElement`,
`event.target` typed as `EventTarget`); the fix is a JSDoc cast such as
`/** @type {HTMLInputElement} */` at each call site, applied incrementally. New code
shouldn't add errors.

## Testing

The UI is tested end to end through the real front controller: see "Running
tests" in [CONTRIBUTING.md](../CONTRIBUTING.md#running-tests).
