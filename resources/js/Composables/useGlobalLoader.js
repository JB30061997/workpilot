import { computed, ref } from 'vue';

const DEFAULT_MESSAGE = 'Chargement en cours…';
const MINIMUM_VISIBLE_DURATION = 420;

const activeOperations = new Map();
const isVisible = ref(false);
const message = ref(DEFAULT_MESSAGE);
let visibleSince = 0;
let hideTimer;

const refreshMessage = () => {
    const messages = Array.from(activeOperations.values());
    message.value = messages.at(-1) ?? DEFAULT_MESSAGE;
};

const startLoading = (loadingMessage = DEFAULT_MESSAGE) => {
    const operation = Symbol('global-loader-operation');

    clearTimeout(hideTimer);
    activeOperations.set(operation, loadingMessage);
    refreshMessage();

    if (!isVisible.value) {
        visibleSince = Date.now();
        isVisible.value = true;
    }

    return operation;
};

const stopLoading = (operation) => {
    if (!activeOperations.delete(operation)) {
        return;
    }

    refreshMessage();

    if (activeOperations.size > 0) {
        return;
    }

    const remainingDuration = Math.max(
        0,
        MINIMUM_VISIBLE_DURATION - (Date.now() - visibleSince),
    );

    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => {
        if (activeOperations.size === 0) {
            isVisible.value = false;
            message.value = DEFAULT_MESSAGE;
        }
    }, remainingDuration);
};

const pulseLoading = (loadingMessage, duration = MINIMUM_VISIBLE_DURATION) => {
    const operation = startLoading(loadingMessage);
    setTimeout(() => stopLoading(operation), duration);

    return operation;
};

export const useGlobalLoader = () => ({
    isLoading: computed(() => isVisible.value),
    loaderMessage: computed(() => message.value),
    pulseLoading,
    startLoading,
    stopLoading,
});

