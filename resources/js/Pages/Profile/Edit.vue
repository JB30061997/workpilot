<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Profil - WorkPilot" />

    <AuthenticatedLayout>
        <template #header>
            <div><p class="text-[10px] font-black uppercase tracking-[0.18em] text-blue-600">Paramètres du compte</p><h2 class="mt-1 text-xl font-black tracking-tight text-slate-950">Mon profil</h2></div>
        </template>

        <div class="px-3 py-5 sm:px-5 lg:px-7">
            <div class="mx-auto grid max-w-[1700px] gap-4 lg:grid-cols-2">
                <div
                    class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    class="rounded-3xl border border-rose-100 bg-white p-5 shadow-sm sm:p-8 lg:col-span-2"
                >
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
