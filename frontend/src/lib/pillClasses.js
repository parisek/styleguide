// The look of a pill button in the overview grid's toolbar and the board's
// controls, in one place so the two cannot drift apart.
// Theme-aware semantic values keep hover states readable in both themes.
export const PILL_BASE = 'inline-flex h-ui-control items-center justify-center gap-1.5 rounded-ui-pill border px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ui-focus';

const PILL_ACTIVE = 'bg-ui-active-surface text-ui-active-text border-ui-active-surface';
const PILL_IDLE = 'bg-ui-surface text-ui-muted border-ui-control-border hover:border-ui-control-hover-border hover:text-ui-text';

// The colours of a pill, pressed or idle.
export function pillState(active) {
    return active ? PILL_ACTIVE : PILL_IDLE;
}

// A pill that is never pressed: a zoom step, "fit all".
export const PILL_BUTTON = `${PILL_BASE} min-w-ui-control ${PILL_IDLE}`;
