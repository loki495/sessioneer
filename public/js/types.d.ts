// Ambient declarations for globals this app sets up itself; type-checking only
// (see tsconfig.json, `npm run typecheck`), never loaded by the browser.

// Defined by ui-session.js and ui.js before markdown.js is called.
declare function escapeHtml(s: string): string;

interface Document {
  getElementById(elementId: 'push-notify-btn'): HTMLButtonElement | null;
}
