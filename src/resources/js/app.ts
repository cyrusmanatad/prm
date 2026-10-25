import '../css/app.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import Vue3Toastify, { type ToastContainerOptions } from 'vue3-toastify';
import 'vue3-toastify/dist/index.css';
import '../css/toasts.css';
import ConfirmationDialog from './components/ConfirmationDialog.vue';
import { registerFlashToasts } from './composables/useFlashToast';

const pages = import.meta.glob<DefineComponent>('./pages/*.vue', { import: 'default' });

void createInertiaApp({
    title: (title) => (title ? `${title} · WeavDash` : 'WeavDash'),
    resolve: (name) => {
        const page = pages[`./pages/${name}.vue`];
        if (!page) throw new Error(`Unknown page: ${name}`);
        return page();
    },
    setup({ el, App, props, plugin }) {
        registerFlashToasts(props.initialPage.props);
        createApp({ render: () => [h(App, props), h(ConfirmationDialog)] })
            .use(plugin)
            .use(Vue3Toastify, {
                autoClose: 3500,
                position: 'top-right',
                theme: 'auto',
                limit: 3,
                newestOnTop: true,
                hideProgressBar: false,
                pauseOnHover: true,
            } as ToastContainerOptions)
            .mount(el);
    },
    progress: { color: '#8e1650' },
});
