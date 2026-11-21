import '../css/app.css';
import { createApp, h, provide, computed, type DefineComponent } from 'vue';
import { createInertiaApp, usePage } from '@inertiajs/vue3';
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
        createApp({
            setup() {
                const page = usePage();
                provide(
                    'workspaceExperience',
                    computed(() => page.props.workspaceExperience ?? 'partner'),
                );
                provide(
                    'professionalSubscription',
                    computed(() => page.props.professionalSubscription),
                );
                return () => [h(App, props), h(ConfirmationDialog)];
            },
        })
            .use(plugin)
            .use(Vue3Toastify, {
                autoClose: 3500,
                position: 'top-right',
                theme: 'auto',
                newestOnTop: true,
                hideProgressBar: false,
                pauseOnHover: true,
            } as ToastContainerOptions)
            .mount(el);
        registerFlashToasts(props.initialPage.props);
    },
    progress: { color: '#8e1650' },
});
