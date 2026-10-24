<script setup>
import AnimatedWaveGrid from "@/Components/AnimatedWaveGrid.vue";
import { Head, Link } from "@inertiajs/vue3";

defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <Head title="Bienvenue | WorkPilot" />

    <main class="welcome-stage">
        <!-- Animation existante -->
        <AnimatedWaveGrid />

        <div class="welcome-container">
            <!-- Logo WorkPilot -->
            <div class="welcome-brand">
                <div class="welcome-brand-icon">
                    <svg
                        width="25"
                        height="25"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M2 12h5l3-7 4 14 3-7h5" />
                    </svg>
                </div>

                <span>WorkPilot</span>
            </div>

            <!-- Card principale -->
            <section class="welcome-center-card">
                <div class="welcome-content">
                    <p class="welcome-eyebrow">BIENVENUE SUR WORKPILOT</p>

                    <h1 class="welcome-title">Votre espace de travail.</h1>

                    <p class="welcome-description">
                        Pilotez vos projets, organisez vos équipes et suivez vos
                        activités depuis un seul espace.
                    </p>

                    <!-- Séparateur -->
                    <div class="welcome-divider"></div>

                    <!-- Actions -->
                    <div class="welcome-actions">
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="welcome-action welcome-action-primary"
                        >
                            <span class="action-content">
                                <svg
                                    width="19"
                                    height="19"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="M10 17l5-5-5-5" />
                                    <path d="M15 12H3" />
                                    <path
                                        d="M12 3h6a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-6"
                                    />
                                </svg>

                                Se connecter
                            </span>

                            <svg
                                class="action-arrow"
                                width="19"
                                height="19"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M5 12h14" />
                                <path d="m12 5 7 7-7 7" />
                            </svg>
                        </Link>

                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="welcome-action welcome-action-secondary"
                        >
                            <span class="action-content">
                                <svg
                                    width="19"
                                    height="19"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <circle cx="9" cy="8" r="4" />
                                    <path d="M2 21v-2a7 7 0 0 1 14 0v2" />
                                    <path d="M19 8v8" />
                                    <path d="M15 12h8" />
                                </svg>

                                Créer un compte
                            </span>

                            <svg
                                class="action-arrow"
                                width="19"
                                height="19"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M5 12h14" />
                                <path d="m12 5 7 7-7 7" />
                            </svg>
                        </Link>
                    </div>
                </div>
            </section>

            <!-- Footer -->
            <p class="welcome-footer">WorkPilot · Management Platform</p>
        </div>
    </main>
</template>

<style scoped>
/* ==============================
   PAGE BACKGROUND
================================ */

.welcome-stage {
    position: relative;
    display: flex;
    width: 100%;
    min-height: 100vh;
    min-height: 100dvh;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 40px 20px;
    background: #f8fafc;
}

/* ==============================
   CONTAINER
================================ */

.welcome-container {
    position: relative;
    z-index: 1;
    display: flex;
    width: 100%;
    max-width: 540px;
    flex-direction: column;
    align-items: center;
}

/* ==============================
   BRAND
================================ */

.welcome-brand {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 11px;
    margin-bottom: 28px;
    color: #0f172a;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.04em;
}

.welcome-brand-icon {
    display: flex;
    width: 38px;
    height: 38px;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: #2563eb;
    color: white;
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.22);
}

/* ==============================
   WHITE CARD
================================ */

.welcome-center-card {
    position: relative;
    box-sizing: border-box;
    width: 100%;
    padding: 48px;
    border: 1px solid #f1f5f9;
    border-radius: 0;
    background: #ffffff;
    box-shadow: 0 24px 75px -28px rgba(15, 23, 42, 0.24);
    animation: welcome-card-in 650ms cubic-bezier(0.22, 1, 0.36, 1) both;
}

.welcome-content {
    width: 100%;
    min-width: 0;
}

/* ==============================
   TYPOGRAPHY
================================ */

.welcome-eyebrow {
    margin: 0;
    color: #2563eb;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.12em;
}

.welcome-title {
    margin-top: 15px;
    color: #0f172a;
    font-size: clamp(27px, 4vw, 34px);
    font-weight: 800;
    letter-spacing: -0.045em;
    line-height: 1.2;
}

.welcome-description {
    margin-top: 15px;
    color: #64748b;
    font-size: 14px;
    line-height: 1.85;
}

/* ==============================
   DIVIDER
================================ */

.welcome-divider {
    width: 100%;
    height: 1px;
    margin: 28px 0;
    background: #e9eef5;
}

/* ==============================
   BUTTONS
================================ */

.welcome-actions {
    display: grid;
    gap: 13px;
}

.welcome-action {
    display: flex;
    width: 100%;
    min-height: 54px;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 0 20px;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    transition:
        background 250ms ease,
        border-color 250ms ease,
        color 250ms ease,
        transform 250ms ease,
        box-shadow 250ms ease;
}

.action-content {
    display: inline-flex;
    align-items: center;
    gap: 12px;
}

.action-arrow {
    flex-shrink: 0;
    transition: transform 250ms ease;
}

.welcome-action:hover .action-arrow {
    transform: translateX(4px);
}

/* Primary button */

.welcome-action-primary {
    border: 1px solid #2563eb;
    background: #2563eb;
    color: #ffffff;
    box-shadow: 0 12px 24px rgba(37, 99, 235, 0.17);
}

.welcome-action-primary:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
    transform: translateY(-2px);
    box-shadow: 0 15px 28px rgba(37, 99, 235, 0.23);
}

/* Secondary button */

.welcome-action-secondary {
    border: 1px solid #dbe5f2;
    background: #f8fafc;
    color: #2563eb;
}

.welcome-action-secondary:hover {
    border-color: #93c5fd;
    background: #eff6ff;
    transform: translateY(-2px);
}

/* ==============================
   FOOTER
================================ */

.welcome-footer {
    margin-top: 26px;
    color: #64748b;
    font-size: 12px;
    font-weight: 500;
    letter-spacing: 0.025em;
    text-align: center;
}

/* ==============================
   ANIMATIONS
================================ */

@keyframes welcome-card-in {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.985);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* ==============================
   RESPONSIVE
================================ */

@media (max-width: 639px) {
    .welcome-stage {
        padding: 24px 16px;
    }

    .welcome-container {
        max-width: 100%;
    }

    .welcome-center-card {
        padding: 34px 26px;
    }

    .welcome-title {
        font-size: 28px;
    }

    .welcome-description {
        font-size: 13px;
    }

    .welcome-action {
        min-height: 52px;
        padding: 0 16px;
    }
}

/* ==============================
   ACCESSIBILITY
================================ */

@media (prefers-reduced-motion: reduce) {
    .welcome-center-card {
        animation: none;
    }

    .welcome-action,
    .action-arrow {
        transition: none;
    }
}
</style>
