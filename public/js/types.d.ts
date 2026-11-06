// Ambient declarations for globals this app sets up itself; type-checking only
// (see tsconfig.json, `npm run typecheck`), never loaded by the browser.

// Defined by v2-session.js and v2.js before markdown.js is called.
declare function escapeHtml(s: string): string;

interface Document {
  getElementById(elementId: 'push-notify-btn'): HTMLButtonElement | null;
}
