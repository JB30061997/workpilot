
<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const showPassword = ref(false);
const showConfirmation = ref(false);

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
            showPassword.value = false;
            showConfirmation.value = false;
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Créer un compte | WorkPilot" />

        <!-- Card identique à Login -->
        <div
            class="w-full border border-slate-100 bg-white px-7 py-10 shadow-[0_24px_75px_-28px_rgba(15,23,42,.24)] sm:px-12 sm:py-12"
        >
            <!-- En-tête -->
            <div class="auth-stagger">
                <p class="text-sm font-bold text-blue-600">
                    Inscription
                </p>

                <h1
                    class="mt-3 text-3xl font-bold tracking-[-0.045em] text-slate-950"
                >
                    Créer votre espace.
                </h1>

                <p class="mt-2 text-sm text-slate-500">
                    Quelques informations, et vous êtes prêt.
                </p>
            </div>

            <!-- Formulaire -->
            <form
                class="mt-8 grid gap-5"
                @submit.prevent="submit"
            >
                <!-- Nom complet -->
                <div class="auth-stagger">
                    <label
                        for="name"
                        class="mb-2 block text-xs font-bold text-slate-700"
                    >
                        Nom complet
                    </label>

                    <div class="relative">
                        <svg
                            class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-blue-500"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="8" r="4" />
                            <path d="M4 21v-2a8 8 0 0 1 16 0v2" />
                        </svg>

                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            autofocus
                            autocomplete="name"
                            class="auth-input !w-full !pl-12 !pr-4"
                            placeholder="Votre nom complet"
                        />
                    </div>

                    <Transition name="field-error">
                        <p
                            v-if="form.errors.name"
                            class="mt-2 text-xs font-semibold text-rose-600"
                        >
                            {{ form.errors.name }}
                        </p>
                    </Transition>
                </div>

                <!-- Adresse e-mail -->
                <div class="auth-stagger">
                    <label
                        for="email"
                        class="mb-2 block text-xs font-bold text-slate-700"
                    >
                        Adresse e-mail
                    </label>

                    <div class="relative">
                        <svg
                            class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-blue-500"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <rect
                                x="3"
                                y="5"
                                width="18"
                                height="14"
                                rx="2"
                            />
                            <path d="m3 7 9 6 9-6" />
                        </svg>

                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="username"
                            class="auth-input !w-full !pl-12 !pr-4"
                            placeholder="nom@entreprise.com"
                        />
                    </div>

                    <Transition name="field-error">
                        <p
                            v-if="form.errors.email"
                            class="mt-2 text-xs font-semibold text-rose-600"
                        >
                            {{ form.errors.email }}
                        </p>
                    </Transition>
                </div>

                <!-- Mot de passe -->
                <div class="auth-stagger">
                    <label
                        for="password"
                        class="mb-2 block text-xs font-bold text-slate-700"
                    >
                        Mot de passe
                    </label>

                    <div class="relative">
                        <!-- Lock icon -->
                        <svg
                            class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-blue-500"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <rect
                                x="5"
                                y="10"
                                width="14"
                                height="11"
                                rx="2"
                            />
                            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                        </svg>

                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            class="auth-input !w-full !pl-12 !pr-12"
                            placeholder="••••••••"
                        />

                        <!-- Eye / EyeOff -->
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 grid w-12 place-items-center text-slate-400 transition hover:text-blue-600"
                            :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                            :aria-pressed="showPassword"
                            @click="showPassword = !showPassword"
                        >
                            <svg
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path
                                    d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"
                                />
                                <circle cx="12" cy="12" r="2.5" />
                                <path
                                    v-if="showPassword"
                                    d="M3 3l18 18"
                                />
                            </svg>
                        </button>
                    </div>

                    <Transition name="field-error">
                        <p
                            v-if="form.errors.password"
                            class="mt-2 text-xs font-semibold text-rose-600"
                        >
                            {{ form.errors.password }}
                        </p>
                    </Transition>
                </div>

                <!-- Confirmation du mot de passe -->
                <div class="auth-stagger">
                    <label
                        for="password_confirmation"
                        class="mb-2 block text-xs font-bold text-slate-700"
                    >
                        Confirmer le mot de passe
                    </label>

                    <div class="relative">
                        <!-- Lock icon -->
                        <svg
                            class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-blue-500"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <rect
                                x="5"
                                y="10"
                                width="14"
                                height="11"
                                rx="2"
                            />
                            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                        </svg>

                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            :type="showConfirmation ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            class="auth-input !w-full !pl-12 !pr-12"
                            placeholder="••••••••"
                        />

                        <!-- Eye / EyeOff -->
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 grid w-12 place-items-center text-slate-400 transition hover:text-blue-600"
                            :aria-label="showConfirmation ? 'Masquer la confirmation' : 'Afficher la confirmation'"
                            :aria-pressed="showConfirmation"
                            @click="showConfirmation = !showConfirmation"
                        >
                            <svg
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path
                                    d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"
                                />
                                <circle cx="12" cy="12" r="2.5" />
                                <path
                                    v-if="showConfirmation"
                                    d="M3 3l18 18"
                                />
                            </svg>
                        </button>
                    </div>

                    <Transition name="field-error">
                        <p
                            v-if="form.errors.password_confirmation"
                            class="mt-2 text-xs font-semibold text-rose-600"
                        >
                            {{ form.errors.password_confirmation }}
                        </p>
                    </Transition>
                </div>

                <!-- Bouton inscription -->
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="auth-primary auth-stagger group mt-2 flex w-full items-center justify-between disabled:opacity-60"
                >
                    <span>
                        {{ form.processing ? 'Création...' : 'Créer mon compte' }}
                    </span>

                    <span
                        class="transition group-hover:translate-x-1"
                        aria-hidden="true"
                    >
                        →
                    </span>
                </button>

                <!-- Lien connexion -->
                <p
                    class="auth-stagger text-center text-sm text-slate-500"
                >
                    Déjà inscrit ?

                    <Link
                        :href="route('login')"
                        class="font-bold text-blue-600 hover:text-blue-500"
                    >
                        Se connecter
                    </Link>
                </p>
            </form>
        </div>
    </GuestLayout>
</template>
