// Ambient type declarations for globals this app sets up itself, not
// part of any library - lets tsc/checkJs (see tsconfig.json,
// `npm run typecheck`) know about them instead of reporting "Property
// does not exist on Window" everywhere they're read. Never loaded by the
// browser (a .d.ts file has no runtime output at all) - type-checking only.

interface CsmBootstrap {
  session?: string;
  csrfToken?: string;
  newestLine?: number | null;
  claudeSessionId?: string | null;
  jumpLine?: number | null;
  workdir?: string | null;
  agent?: string;
  agentLabel?: string;
  agentReachable?: boolean;
}

interface CsmArchivedBootstrap {
  claudeSessionId?: string | null;
  jumpLine?: number | null;
}

interface Window {
  SESSIONEER_BOOTSTRAP: CsmBootstrap;
  SESSIONEER_ARCHIVED_BOOTSTRAP: CsmArchivedBootstrap;
  openFullscreenTextModal: (text: string, html?: string | null) => void;
}

declare function openFullscreenTextModal(text: string, html?: string | null): void;

// getElementById() cannot infer an element subtype from an arbitrary string.
// These overloads document the stable IDs emitted by Sessioneer's server-rendered
// templates so checkJs retains real form-control APIs without casting every
// use to `any` or pretending every HTMLElement has `.value`/`.disabled`.
interface Document {
  getElementById(elementId:
    | 'archived-load-more-btn' | 'archived-search-clear-btn'
    | 'compose-attach-btn' | 'compose-send-btn' | 'compose-textarea-clear-btn'
    | 'dashboard-search-input-clear-btn' | 'delete-all-uploads-btn'
    | 'fullscreen-edit-modal-close' | 'fullscreen-text-modal-close'
    | 'fullscreen-text-modal-wrap-toggle' | 'go-to-bottom-btn' | 'go-to-top-btn'
    | 'jump-to-new-btn' | 'load-more-btn' | 'load-until-user-btn'
    | 'new-folder-btn' | 'new-session-submit' | 'new-session-summary'
    | 'push-notify-btn' | 'quota-toggle-btn' | 'session-search-input-clear-btn'
    | 'show-archived-btn' | 'sidebar-close-btn' | 'sidebar-toggle-btn'
    | 'todo-file-link'): HTMLButtonElement | null;
  getElementById(elementId:
    | 'archived-search' | 'compose-file-input' | 'confirm-before-answer-toggle'
    | 'dashboard-search-input' | 'new-session-model-provider'
    | 'session-search-input' | 'session-search-scope-global'
    | 'show-subagent-toggle' | 'show-worker-sessions-toggle' | 'workdir_value'): HTMLInputElement | null;
  getElementById(elementId:
    | 'compose-textarea' | 'fullscreen-edit-modal-textarea'): HTMLTextAreaElement | null;
  getElementById(elementId:
    | 'antigravity-model-select' | 'codex-effort-select' | 'codex-model-select'
    | 'effort-select' | 'mode-select' | 'model-select' | 'new-session-agent'
    | 'new-session-model' | 'opencode-model-select'
    | 'poll-interval-select'): HTMLSelectElement | null;
  getElementById(elementId: 'new-session-details'): HTMLDetailsElement | null;
}
