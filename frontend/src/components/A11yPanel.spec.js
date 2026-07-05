import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import A11yPanel from './A11yPanel.vue';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';

function mountPanel() {
    setActivePinia(createPinia());
    useI18nStore().strings = {
        a11y: {
            panel_title: 'Accessibility results',
            running: 'Running check…',
            no_violations: 'No issues found',
            impact_critical: 'Critical',
            impact_serious: 'Serious',
            impact_moderate: 'Moderate',
            impact_minor: 'Minor',
        },
    };
    return mount(A11yPanel);
}

const results = (byImpact) => ({
    byImpact: { critical: [], serious: [], moderate: [], minor: [], ...byImpact },
    total: Object.values({ critical: [], serious: [], moderate: [], minor: [], ...byImpact }).flat().length,
});

describe('A11yPanel', () => {
    it('shows the running state while a check is in flight', async () => {
        const wrapper = mountPanel();
        useUiStore().a11yRunning = true;
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Running check…');
    });

    it('shows the no-violations state for a clean result', async () => {
        const wrapper = mountPanel();
        useUiStore().a11yResults = results({});
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('No issues found');
    });

    it('groups violations under their impact heading, in critical→minor order', async () => {
        const wrapper = mountPanel();
        useUiStore().a11yResults = results({
            critical: [{ id: 'image-alt', help: 'Images must have alternate text', nodes: [{ target: ['img'] }] }],
            moderate: [{ id: 'region', help: 'All page content must be contained by landmarks', nodes: [{ target: ['div'] }] }],
        });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="a11y-impact-critical"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="a11y-impact-moderate"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="a11y-impact-serious"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="a11y-impact-minor"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Images must have alternate text');

        const headings = wrapper.findAll('h4').map((h) => h.attributes('data-testid'));
        expect(headings).toEqual(['a11y-impact-critical', 'a11y-impact-moderate']);
    });

    it('renders violation help text and targets via interpolation, never v-html', async () => {
        const wrapper = mountPanel();
        useUiStore().a11yResults = results({
            critical: [{ id: 'image-alt', help: '<script>evil()</script>', nodes: [{ target: ['img.hero'] }] }],
        });
        await wrapper.vm.$nextTick();
        // If this were rendered via v-html the tag would be parsed into a
        // real (inert, since innerHTML never executes injected <script>)
        // element instead of surviving as literal text.
        expect(wrapper.html()).toContain('&lt;script&gt;evil()&lt;/script&gt;');
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.text()).toContain('img.hero');
    });
});
