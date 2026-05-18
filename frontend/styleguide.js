import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import persist from '@alpinejs/persist';

import './styleguide.css';

import './stores/i18n.js';
import './stores/ui.js';
import './stores/components.js';

import './router.js';

import './components/sidebar.js';
import './components/search.js';
import './components/preview.js';
import './components/usage.js';
import './components/languageSwitcher.js';

Alpine.plugin(collapse);
Alpine.plugin(persist);
window.Alpine = Alpine;
Alpine.start();
