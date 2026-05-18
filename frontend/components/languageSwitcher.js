import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    Alpine.data('languageSwitcher', () => ({
        get current() { return Alpine.store('i18n').locale; },
        get supported() { return ['cs', 'en']; },

        setLocale(locale) {
            Alpine.store('i18n').load(locale);
        },

        isActive(locale) {
            return this.current === locale;
        },
    }));
});
