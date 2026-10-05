// The look of a pill button in the overview grid's toolbar and the board's
// controls, in one place so the two cannot drift apart.
//
// A hover state names its dark twin: `hover:text-zinc-900` alone also wins in
// the dark theme (a variant with a pseudo-class outranks `dark:text-*`), and
// dark text on a dark pill disappears.
export const PILL_BASE = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-full border px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600';

const PILL_ACTIVE = 'bg-zinc-900 text-white border-zinc-900 dark:bg-zinc-100 dark:text-zinc-900 dark:border-zinc-100';
const PILL_IDLE = 'bg-white text-zinc-600 border-zinc-300 hover:border-zinc-400 hover:text-zinc-900 '
    + 'dark:bg-zinc-900 dark:text-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-500 dark:hover:text-zinc-100';

// The colours of a pill, pressed or idle.
export function pillState(active) {
    return active ? PILL_ACTIVE : PILL_IDLE;
}

// A pill that is never pressed: a zoom step, "fit all".
export const PILL_BUTTON = `${PILL_BASE} min-w-8 ${PILL_IDLE}`;
