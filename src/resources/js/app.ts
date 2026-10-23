import '../css/app.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import ConfirmationDialog from './components/ConfirmationDialog.vue';

const pages = import.meta.glob<DefineComponent>('./pages/*.vue', { import: 'default' });

void createInertiaApp({
    title: (title) => (title ? `${title} · WeavDash` : 'WeavDash'),
    resolve: (name) => {
        const page = pages[`./pages/${name}.vue`];
        if (!page) throw new Error(`Unknown page: ${name}`);
        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => [h(App, props), h(ConfirmationDialog)] })
            .use(plugin)
            .mount(el);
    },
    progress: { color: '#8e1650' },
});
