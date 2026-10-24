<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
const props = defineProps({ status: { type: String, default: null } });
const form = useForm({});
const submit = () => form.post(route('verification.send'));
const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>
<template><GuestLayout><Head title="Vérifier votre e-mail | WorkPilot" /><div class="auth-stagger"><span class="grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span><h1 class="mt-5 text-3xl font-bold tracking-[-0.045em]">Vérifiez votre e-mail.</h1><p class="mt-2 text-sm leading-6 text-slate-500">Utilisez le lien reçu pour activer votre espace.</p></div><div v-if="verificationLinkSent" class="auth-stagger mt-5 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">Un nouveau lien vient d’être envoyé.</div><form class="mt-7 grid gap-4" @submit.prevent="submit"><button type="submit" :disabled="form.processing" class="auth-primary auth-stagger"><span>{{ form.processing ? 'Envoi...' : 'Renvoyer le lien' }}</span><span>→</span></button><Link :href="route('logout')" method="post" as="button" class="auth-stagger h-11 text-sm font-semibold text-slate-500 hover:text-blue-600">Se déconnecter</Link></form></GuestLayout></template>
