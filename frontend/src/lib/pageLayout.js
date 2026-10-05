// The frame every page of the catalogue's own screens shares (the overview,
// the grid, the fields), so the space between the top bar and the title, and
// the title itself, do not jump from one screen to the next. A view that
// shows its content in a fixed-height area (the grid's board) keeps the same
// top and side padding and drops only the bottom one.
export const PAGE_PAD_TOP = 'pt-8 lg:pt-10';
export const PAGE_PAD = `px-6 lg:px-10 ${PAGE_PAD_TOP}`;
export const PAGE_PAD_BOTTOM = 'pb-8 lg:pb-10';
export const PAGE_TITLE = 'font-bold text-2xl sm:text-3xl tracking-tight text-zinc-900 dark:text-zinc-100';
