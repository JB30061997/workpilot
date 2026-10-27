<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useGlobalLoader } from '@/Composables/useGlobalLoader';

const props = defineProps({
    show: { type: Boolean, default: false },
    maxWidth: { type: String, default: '2xl' },
    closeable: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);
const { pulseLoading } = useGlobalLoader();
const showSlot = ref(props.show);
let closeTimeout;

watch(
    () => props.show,
    (visible) => {
        clearTimeout(closeTimeout);
        if (visible) {
            pulseLoading('Préparation de la fenêtre…');
            document.body.style.overflow = 'hidden';
            showSlot.value = true;
        } else {
            document.body.style.overflow = '';
            closeTimeout = setTimeout(() => {
                showSlot.value = false;
            }, 200);
        }
    },
);

const close = () => {
    if (props.closeable) emit('close');
};

const closeOnEscape = (event) => {
    if (event.key === 'Escape' && props.show) {
        event.preventDefault();
        close();
    }
};

onMounted(() => {
    document.addEventListener('keydown', closeOnEscape);
    if (props.show) {
        document.body.style.overflow = 'hidden';
        showSlot.value = true;
    }
});

onUnmounted(() => {
    clearTimeout(closeTimeout);
    document.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = '';
});

const maxWidthClass = computed(() => ({
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
    '3xl': 'sm:max-w-3xl',
    '5xl': 'sm:max-w-5xl',
    '6xl': 'sm:max-w-6xl',
    screen: 'sm:max-w-[calc(100vw-3rem)] xl:max-w-[1500px]',
    full: 'max-w-[1440px]',
}[props.maxWidth] ?? 'sm:max-w-2xl'));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="showSlot"
            class="fixed inset-0 z-[1000] flex items-center justify-center overflow-y-auto px-3 py-4 sm:px-6 sm:py-8"
            role="dialog"
            aria-modal="true"
            scroll-region
        >
            <Transition
                enter-active-class="ease-out duration-300 motion-reduce:duration-0"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200 motion-reduce:duration-0"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="show"
                    class="fixed inset-0 transform transition-all"
                    @click="close"
                >
                    <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-[3px]" />
                </div>
            </Transition>

            <Transition
                enter-active-class="ease-out duration-300 motion-reduce:duration-0"
                enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                leave-active-class="ease-in duration-200 motion-reduce:duration-0"
                leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <div
                    v-show="show"
                    class="relative transform overflow-hidden rounded-[3px] border border-slate-300 bg-[#F8FAFC] shadow-xl transition-all sm:mx-auto sm:w-full"
                    :class="[
                        maxWidthClass,
                        maxWidth === 'full'
                            ? 'h-[min(94dvh,1100px)] w-[98vw] max-sm:h-[96dvh] max-sm:w-[98vw]'
                            : 'w-full max-h-[94dvh]',
                    ]"
                >
                    <slot v-if="showSlot" />
                </div>
            </Transition>
        </div>
    </Teleport>
</template>
