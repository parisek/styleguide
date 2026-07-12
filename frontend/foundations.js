/**
 * Package-shipped interactivity for templates/foundations.twig — the
 * foundations iframe must not depend on the consumer bundling any JS
 * framework (see issue #79). Bound via the data-* contract documented in
 * docs/superpowers/plans/2026-07-12-foundations-vanilla-js.md.
 */
const COPY_RESET_MS = 2000;

function initPalette(root, card) {
	const hero = card.querySelector('[data-hero]');
	const heroText = card.querySelector('[data-hero-text]');
	const slots = {
		label: card.querySelector('[data-hero-label]'),
		hex: card.querySelector('[data-hero-hex]'),
		oklch: card.querySelector('[data-hero-oklch]'),
		contrast: card.querySelector('[data-hero-contrast]'),
	};
	const feedback = card.querySelector('[data-copy-feedback]');
	const overlay = card.querySelector('[data-copy-overlay]');
	const labels = { copied: root.dataset.labelCopied, copy: root.dataset.labelCopy };
	let active = null;
	let resetTimer = null;

	const contrastLine = (s) =>
		`W ${s.contrast_white}${s.aa_white ? ' AA' : ''} · B ${s.contrast_black}${s.aa_black ? ' AA' : ''}`;

	const copy = (swatch) => {
		navigator.clipboard?.writeText(swatch.hex);
		if (!feedback) return;
		feedback.textContent = labels.copied;
		overlay?.classList.add('opacity-100');
		clearTimeout(resetTimer);
		resetTimer = setTimeout(() => {
			feedback.textContent = labels.copy;
			overlay?.classList.remove('opacity-100');
		}, COPY_RESET_MS);
	};

	const render = (swatch, button) => {
		active = swatch;
		if (hero) hero.style.backgroundColor = swatch.bg;
		if (heroText) {
			heroText.classList.toggle('text-zinc-900', swatch.light);
			heroText.classList.toggle('text-white', !swatch.light);
		}
		if (slots.label) slots.label.textContent = swatch.label;
		if (slots.hex) slots.hex.textContent = swatch.hex;
		if (slots.oklch) slots.oklch.textContent = swatch.oklch;
		if (slots.contrast) slots.contrast.textContent = contrastLine(swatch);
		for (const b of card.querySelectorAll('[data-swatch]')) {
			const isActive = b === button;
			b.querySelector('[data-swatch-tile]')?.classList.toggle('ring-2', isActive);
			b.querySelector('[data-swatch-tile]')?.classList.toggle('ring-zinc-950', isActive);
			b.querySelector('[data-swatch-key]')?.classList.toggle('font-bold', isActive);
			b.querySelector('[data-swatch-key]')?.classList.toggle('text-zinc-900', isActive);
		}
	};

	for (const button of card.querySelectorAll('[data-swatch]')) {
		let swatch;
		try { swatch = JSON.parse(button.dataset.swatch); } catch { continue; }
		if (button.hasAttribute('data-swatch-default')) active = swatch;
		button.addEventListener('click', () => { render(swatch, button); copy(swatch); });
	}
	hero?.addEventListener('click', () => { if (active) copy(active); });
}

function init() {
	const root = document.querySelector('[data-sg-colors]');
	if (!root) return;
	for (const card of root.querySelectorAll('[data-palette]')) initPalette(root, card);

	const toggle = root.querySelector('[data-matrix-toggle]');
	const matrix = root.querySelector('[data-matrix]');
	toggle?.addEventListener('click', () => {
		const open = matrix.toggleAttribute('hidden') === false;
		toggle.querySelector('svg')?.classList.toggle('rotate-90', open);
	});
}

document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
