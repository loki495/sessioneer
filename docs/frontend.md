# Frontend: CSS build and JS type-checking

Nothing needs building to run the app. `public/js/*.js` is plain, unbundled ES5,
and `public/css/tailwind.css` is a committed, precompiled file. `npm` is a
dev-only tool for whoever changes markup, classes or JS.

```bash
npm install          # once
npm run build:css    # regenerate public/css/tailwind.css after changing utility classes
npm run typecheck    # JSDoc type-check of public/js/*.js (tsc --noEmit)
```

## JavaScript is plain ES5

`public/js/*.js` uses `var` and `function`: no `const`/`let`, arrow functions,
`Set` or template literals. There's no transpiler, and mobile Safari
compatibility (this is a home-screen PWA) has repeatedly been the reason.

## CSS (Tailwind v4)

`resources/tailwind.css` is the source: `@import "tailwindcss"` plus `@source`
globs for `src/partials/**/*.php`, `src/lib/Views/**/*.php` and
`public/js/**/*.js`. Much of the markup is built as HTML strings inside the JS,
so the class scanner has to read those files too. `npm run build:css` writes the
minified result to `public/css/tailwind.css`; commit it with the change that
needed it. There is no `tailwind.config.js` or custom theme.

The CSS used to come from Tailwind's CDN script. It was replaced because a page
fetching a script from a third-party host at runtime is an external dependency
for an app whose point is that everything runs locally.

## Type-checking the JS (JSDoc + tsc)

`npm run typecheck` runs `tsc --noEmit` over the plain `.js` files (see
`tsconfig.json`): no build step and no transpilation. `// @ts-check` at the top
of each file only signals the editor and the CLI. `public/js/types.d.ts`
declares the one global the app adds itself, `window.SESSIONEER_BOOTSTRAP`.
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
`/** @type {HTMLInputElement} */` at each call site, applied incrementally. Two
are known gaps in the DOM typings, left as they are: `resolve()` called with no
argument in `common.js`, and `new URLSearchParams(new FormData(form))` in
`index.js`. New code shouldn't add errors.

## Testing

The UI is tested end to end through the real front controller: see "Running
tests" in [CONTRIBUTING.md](../CONTRIBUTING.md#running-tests).
