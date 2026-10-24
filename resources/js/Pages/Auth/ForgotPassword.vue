<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
defineProps({ status: { type: String, default: null } });
const form = useForm({ email: '' });
const submit = () => form.post(route('password.email'));
</script>
<template><GuestLayout><Head title="Mot de passe oublié | WorkPilot" /><div class="auth-stagger"><p class="text-sm font-bold text-blue-600">Récupération</p><h1 class="mt-3 text-3xl font-bold tracking-[-0.045em]">Mot de passe oublié ?</h1><p class="mt-2 text-sm leading-6 text-slate-500">Recevez un lien sécurisé par e-mail.</p></div><div v-if="status" class="auth-stagger mt-5 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ status }}</div><form class="mt-7 grid gap-5" @submit.prevent="submit"><div class="auth-stagger"><label for="email" class="mb-2 block text-xs font-bold text-slate-700">Adresse e-mail</label><input id="email" v-model="form.email" type="email" required autofocus autocomplete="username" class="auth-input" /><Transition name="field-error"><p v-if="form.errors.email" class="mt-2 text-xs font-semibold text-rose-600">{{ form.errors.email }}</p></Transition></div><button type="submit" :disabled="form.processing" class="auth-primary auth-stagger"><span>{{ form.processing ? 'Envoi...' : 'Envoyer le lien' }}</span><span>→</span></button><Link :href="route('login')" class="auth-stagger text-center text-sm font-semibold text-slate-500 hover:text-blue-600">Retour à la connexion</Link></form></GuestLayout></template>
