<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({ canResetPassword: { type: Boolean, default: false }, status: { type: String, default: null } });
const form = useForm({ email: '', password: '', remember: false });
const showPassword = ref(false);
const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <GuestLayout>
        <Head title="Connexion | WorkPilot" />
        <div class="w-full border border-slate-100 bg-white px-7 py-10 shadow-[0_24px_75px_-28px_rgba(15,23,42,.24)] sm:px-12 sm:py-12">
            <div class="auth-stagger">
                <p class="text-sm font-bold text-blue-600">Connexion</p>
                <h1 class="mt-3 text-3xl font-bold tracking-[-0.045em] text-slate-950">Bon retour.</h1>
                <p class="mt-2 text-sm text-slate-500">Connectez-vous à votre espace WorkPilot.</p>
            </div>
            <div v-if="status" class="auth-stagger mt-5 border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">{{ status }}</div>
            <form class="mt-8 grid gap-5" @submit.prevent="submit">
                <div class="auth-stagger">
                    <label for="email" class="mb-2 block text-xs font-bold text-slate-700">Adresse e-mail</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                        <input id="email" v-model="form.email" type="email" required autofocus autocomplete="username" class="auth-input !w-full !pl-12 !pr-4" placeholder="nom@entreprise.com" />
                    </div>
                    <Transition name="field-error"><p v-if="form.errors.email" class="mt-2 text-xs font-semibold text-rose-600">{{ form.errors.email }}</p></Transition>
                </div>
                <div class="auth-stagger">
                    <div class="mb-2 flex items-center justify-between gap-4"><label for="password" class="text-xs font-bold text-slate-700">Mot de passe</label><Link v-if="canResetPassword" :href="route('password.request')" class="text-xs font-semibold text-slate-500 transition hover:text-blue-600">Mot de passe oublié ?</Link></div>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                        <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" required autocomplete="current-password" class="auth-input !w-full !pl-12 !pr-12" placeholder="••••••••" />
                        <button type="button" class="absolute inset-y-0 right-0 grid w-12 place-items-center text-slate-400 transition hover:text-blue-600" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/><path v-if="showPassword" d="M3 3l18 18"/></svg></button>
                    </div>
                    <Transition name="field-error"><p v-if="form.errors.password" class="mt-2 text-xs font-semibold text-rose-600">{{ form.errors.password }}</p></Transition>
                </div>
                <label class="auth-stagger flex w-fit cursor-pointer items-center gap-2.5 text-sm text-slate-600"><input v-model="form.remember" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" />Se souvenir de moi</label>
                <button type="submit" :disabled="form.processing" class="auth-primary auth-stagger group flex w-full items-center justify-between disabled:opacity-60"><span>{{ form.processing ? 'Connexion...' : 'Se connecter' }}</span><span class="transition group-hover:translate-x-1">→</span></button>
                <p class="auth-stagger text-center text-sm text-slate-500">Nouveau sur WorkPilot ? <Link :href="route('register')" class="font-bold text-blue-600 hover:text-blue-500">Créer un compte</Link></p>
            </form>
        </div>
    </GuestLayout>
</template>
