<script setup>
import { useGlobalLoader } from '@/Composables/useGlobalLoader';

const { isLoading, loaderMessage } = useGlobalLoader();
</script>

<template>
    <Teleport to="body">
        <Transition name="workpilot-loader">
            <div
                v-if="isLoading"
                class="workpilot-loader-overlay"
                role="status"
                aria-live="polite"
                :aria-label="loaderMessage"
            >
                <div class="workpilot-loader-glow" aria-hidden="true" />

                <div class="workpilot-loader-card">
                    <div class="workpilot-loader-logo" aria-hidden="true">
                        <img
                            class="workpilot-loader-logo-muted"
                            src="/images/logo/logo_workpilot.png"
                            alt=""
                        />
                        <div class="workpilot-loader-reveal">
                            <img
                                src="/images/logo/logo_workpilot.png"
                                alt=""
                            />
                        </div>
                        <span class="workpilot-loader-spark" />
                    </div>

                    <div class="workpilot-loader-track" aria-hidden="true">
                        <span />
                    </div>

                    <p class="workpilot-loader-message">{{ loaderMessage }}</p>
                    <p class="workpilot-loader-tagline">PLAN SMART. WORK BETTER.</p>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.workpilot-loader-overlay {
    position: fixed;
    inset: 0;
    z-index: 2147483646;
    display: grid;
    place-items: center;
    padding: 1.5rem;
    overflow: hidden;
    background: rgb(5 12 28 / 0.64);
    backdrop-filter: blur(7px) saturate(115%);
}

.workpilot-loader-glow {
    position: absolute;
    width: min(34rem, 82vw);
    aspect-ratio: 1;
    border-radius: 9999px;
    background: radial-gradient(circle, rgb(0 174 255 / 0.22), transparent 68%);
    animation: workpilot-loader-breathe 2.4s ease-in-out infinite;
}

.workpilot-loader-card {
    position: relative;
    width: min(31rem, calc(100vw - 2rem));
    padding: 0.5rem;
}

.workpilot-loader-logo {
    position: relative;
    width: 100%;
    aspect-ratio: 3 / 1;
}

.workpilot-loader-logo img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.workpilot-loader-logo-muted {
    opacity: 0.16;
    filter: grayscale(1) brightness(1.25);
}

.workpilot-loader-reveal {
    position: absolute;
    inset: 0;
    overflow: hidden;
    animation: workpilot-loader-reveal 2.6s cubic-bezier(.45, 0, .25, 1) infinite;
}

.workpilot-loader-spark {
    position: absolute;
    top: 48%;
    left: 0;
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 9999px;
    background: #a5f3fc;
    box-shadow: 0 0 8px #22d3ee, 0 0 20px #2563eb;
    animation: workpilot-loader-spark 2.6s cubic-bezier(.45, 0, .25, 1) infinite;
}

.workpilot-loader-track {
    height: 0.2rem;
    margin: 0.45rem auto 1rem;
    overflow: hidden;
    border-radius: 9999px;
    background: rgb(148 163 184 / 0.14);
}

.workpilot-loader-track span {
    display: block;
    width: 42%;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #2563eb, #22d3ee, #60a5fa);
    box-shadow: 0 0 14px rgb(34 211 238 / 0.75);
    animation: workpilot-loader-track 1.25s ease-in-out infinite;
}

.workpilot-loader-message {
    margin: 0;
    color: #e2e8f0;
    font-size: 0.875rem;
    font-weight: 700;
    text-align: center;
}

.workpilot-loader-tagline {
    margin: 0.45rem 0 0;
    color: #7dd3fc;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.28em;
    text-align: center;
}

.workpilot-loader-enter-active,
.workpilot-loader-leave-active {
    transition: opacity 180ms ease;
}

.workpilot-loader-enter-active .workpilot-loader-card,
.workpilot-loader-leave-active .workpilot-loader-card {
    transition: transform 220ms cubic-bezier(.22, 1, .36, 1), opacity 180ms ease;
}

.workpilot-loader-enter-from,
.workpilot-loader-leave-to {
    opacity: 0;
}

.workpilot-loader-enter-from .workpilot-loader-card,
.workpilot-loader-leave-to .workpilot-loader-card {
    opacity: 0;
    transform: translateY(8px) scale(0.975);
}

@keyframes workpilot-loader-reveal {
    0%, 8% { clip-path: inset(0 100% 0 0); }
    72%, 90% { clip-path: inset(0 0 0 0); }
    100% { clip-path: inset(0 0 0 100%); }
}

@keyframes workpilot-loader-spark {
    0%, 8% { left: 0; opacity: 0; }
    12% { opacity: 1; }
    72% { left: calc(100% - 0.55rem); opacity: 1; }
    82%, 100% { left: calc(100% - 0.55rem); opacity: 0; }
}

@keyframes workpilot-loader-track {
    0% { transform: translateX(-115%); }
    100% { transform: translateX(340%); }
}

@keyframes workpilot-loader-breathe {
    50% { transform: scale(1.08); opacity: 0.7; }
}

@media (max-width: 640px) {
    .workpilot-loader-card {
        padding: 0.25rem;
    }

    .workpilot-loader-message {
        font-size: 0.8125rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .workpilot-loader-glow,
    .workpilot-loader-reveal,
    .workpilot-loader-spark,
    .workpilot-loader-track span {
        animation: none;
    }

    .workpilot-loader-reveal {
        clip-path: none;
    }

    .workpilot-loader-spark {
        display: none;
    }
}
</style>

