import '../css/app.css';
import 'sweetalert2/dist/sweetalert2.min.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, Fragment, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import WorkPilotLoader from './Components/WorkPilotLoader.vue';
import { useGlobalLoader } from './Composables/useGlobalLoader';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const { startLoading, stopLoading } = useGlobalLoader();
const inertiaOperations = new WeakMap();

router.on('start', (event) => {
    const visit = event.detail.visit;
    const method = String(visit.method ?? 'get').toLowerCase();
    const loadingMessage = method === 'get'
        ? 'Chargement de la page…'
        : 'Traitement de votre demande…';

    inertiaOperations.set(visit, startLoading(loadingMessage));
});

router.on('finish', (event) => {
    const operation = inertiaOperations.get(event.detail.visit);

    if (operation) {
        stopLoading(operation);
        inertiaOperations.delete(event.detail.visit);
    }
});

if (window.axios) {
    window.axios.interceptors.request.use((config) => {
        const method = String(config.method ?? 'get').toLowerCase();

        if (method !== 'get' && config.showGlobalLoader !== false) {
            config.globalLoaderOperation = startLoading('Enregistrement en cours…');
        }

        return config;
    });

    const finishAxiosOperation = (responseOrError) => {
        const config = responseOrError?.config;

        if (config?.globalLoaderOperation) {
            stopLoading(config.globalLoaderOperation);
        }

        return responseOrError;
    };

    window.axios.interceptors.response.use(
        (response) => finishAxiosOperation(response),
        (error) => Promise.reject(finishAxiosOperation(error)),
    );
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({
            render: () => h(Fragment, null, [
                h(App, props),
                h(WorkPilotLoader),
            ]),
        })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
