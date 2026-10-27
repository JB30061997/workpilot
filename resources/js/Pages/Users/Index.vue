<script setup>
import InputError from "@/Components/InputError.vue";
import Modal from "@/Components/Modal.vue";
import { useSweetAlert } from "@/Composables/useSweetAlert";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const props = defineProps({
    organization: Object,
    department: Object,
    users: Object,
    departments: Array,
    roles: Array,
    filters: Object,
    canManageUsers: Boolean,
});

const { confirmAction } = useSweetAlert();

const modalOpen = ref(false);
const editingUser = ref(null);
const filterForm = useForm({
    search: props.filters?.search ?? "",
    role_id: props.filters?.role_id ?? "",
});
const userForm = useForm({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
    department_ids: props.department?.id ? [props.department.id] : [],
    role_id: "",
    job_title: "",
});

const initials = (name) =>
    name
        ?.split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join("")
        .toUpperCase() || "U";
const openCreate = () => {
    editingUser.value = null;
    userForm.reset();
    userForm.department_ids = props.department?.id ? [props.department.id] : [];
    userForm.clearErrors();
    modalOpen.value = true;
};
const openEdit = (user) => {
    editingUser.value = user;
    userForm.clearErrors();
    userForm.name = user.name;
    userForm.email = user.email;
    userForm.password = "";
    userForm.password_confirmation = "";
    userForm.department_ids = (user.departments ?? []).map(
        (department) => department.id,
    );
    userForm.role_id = user.roles?.[0]?.id ?? "";
    userForm.job_title = user.organizations?.[0]?.pivot?.job_title ?? "";
    modalOpen.value = true;
};
const closeModal = () => {
    if (userForm.processing) return;
    modalOpen.value = false;
    editingUser.value = null;
    userForm.reset();
    userForm.clearErrors();
};
const submitUser = () => {
    if (editingUser.value) {
        userForm
            .transform(({ password, password_confirmation, ...data }) => ({
                ...data,
                ...(password ? { password, password_confirmation } : {}),
            }))
            .patch(route("users.update", editingUser.value.id), {
                preserveScroll: true,
                onSuccess: closeModal,
            });
        return;
    }
    userForm
        .transform((data) => data)
        .post(route("users.store"), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
};
const applyFilters = () =>
    router.get(route("users.index"), filterForm.data(), {
        preserveState: true,
        replace: true,
    });
const resetFilters = () => {
    filterForm.reset();
    router.get(route("users.index"), {}, { replace: true });
};
const removeUser = async (user) => {
    if (await confirmAction({ title: `Supprimer « ${user.name} » ?`, text: 'Cet utilisateur perdra son accès à WorkPilot.', confirmButtonText: 'Supprimer l’utilisateur' }))
        router.delete(route("users.destroy", user.id), {
            preserveScroll: true,
        });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Utilisateurs - WorkPilot" />
        <div class="mx-auto grid min-w-0 max-w-[1700px] gap-4 px-3 py-5 sm:px-5 lg:px-7">
            <section
                class="wp-page-header overflow-hidden px-5 py-5 sm:px-7"
            >
                <div
                    class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.2em] text-blue-600"
                        >
                            Administration / {{ organization.name }}
                        </p>
                        <h2 class="mt-1 text-2xl font-bold">
                            Gestion des utilisateurs
                        </h2>
                        <p class="mt-2 text-sm text-slate-400">
                            Comptes, affectations au service et rôles d’accès.
                        </p>
                    </div>
                    <button
                        v-if="canManageUsers"
                        type="button"
                        class="rounded-[4px] bg-blue-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-700"
                        @click="openCreate"
                    >
                        + Ajouter un utilisateur
                    </button>
                </div>
            </section>

            <form
                class="mt-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[1fr_240px_auto_auto]"
                @submit.prevent="applyFilters"
            >
                <input
                    v-model="filterForm.search"
                    type="search"
                    placeholder="Rechercher par nom ou e-mail..."
                    class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"
                />
                <select
                    v-model="filterForm.role_id"
                    class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"
                >
                    <option value="">Tous les rôles</option>
                    <option
                        v-for="role in roles"
                        :key="role.id"
                        :value="role.id"
                    >
                        {{ role.name }}
                    </option>
                </select>
                <button
                    class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700"
                >
                    Filtrer
                </button>
                <button
                    type="button"
                    class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-bold text-slate-600"
                    @click="resetFilters"
                >
                    Effacer
                </button>
            </form>

            <section
                class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div
                    class="flex items-center justify-between border-b border-slate-100 px-5 py-4"
                >
                    <div>
                        <h3 class="font-black text-slate-900">
                            Équipe — {{ department.name }}
                        </h3>
                        <p class="text-xs text-slate-400">
                            {{ users.total }} utilisateur(s)
                        </p>
                    </div>
                </div>
                <div v-if="users.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[800px]">
                        <thead>
                            <tr
                                class="bg-slate-50 text-left text-[10px] font-black uppercase tracking-wider text-slate-400"
                            >
                                <th class="px-5 py-3">Utilisateur</th>
                                <th class="px-4 py-3">Rôles</th>
                                <th class="px-4 py-3">Départements</th>
                                <th class="px-4 py-3">Activité</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="user in users.data"
                                :key="user.id"
                                class="border-t border-slate-100 hover:bg-slate-50/70"
                            >
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 text-xs font-black text-white"
                                            >{{ initials(user.name) }}</span
                                        >
                                        <div>
                                            <p class="font-bold text-slate-900">
                                                {{ user.name }}
                                            </p>
                                            <p class="text-xs text-slate-400">
                                                {{ user.email }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="role in user.roles"
                                            :key="role.id"
                                            class="rounded-full bg-blue-50 px-2.5 py-1 text-[10px] font-bold text-blue-700"
                                            >{{ role.name }}</span
                                        ><span
                                            v-if="!user.roles.length"
                                            class="text-xs text-slate-400"
                                            >Sans rôle</span
                                        >
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex max-w-sm flex-wrap gap-1">
                                        <span
                                            v-for="item in user.departments"
                                            :key="item.id"
                                            class="rounded-full bg-cyan-50 px-2.5 py-1 text-[10px] font-bold text-cyan-700"
                                        >{{ item.name }}</span>
                                        <span
                                            v-if="!user.departments.length"
                                            class="text-xs text-slate-400"
                                        >Sans département</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-xs text-slate-500">
                                    {{ user.assigned_tasks_count }} tâches ·
                                    {{ user.projects_count }} projets
                                </td>
                                <td class="px-5 py-4">
                                    <div
                                        v-if="canManageUsers"
                                        class="flex justify-end gap-2"
                                    >
                                        <button
                                            class="text-xs font-bold text-blue-600"
                                            @click="openEdit(user)"
                                        >
                                            Modifier</button
                                        ><button
                                            class="text-xs font-bold text-rose-600"
                                            @click="removeUser(user)"
                                        >
                                            Supprimer
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="p-12 text-center text-sm text-slate-400">
                    Aucun utilisateur trouvé.
                </div>
                <div
                    v-if="users.last_page > 1"
                    class="flex flex-wrap justify-between gap-3 border-t border-slate-100 px-5 py-4"
                >
                    <p class="text-xs text-slate-400">
                        {{ users.from }}–{{ users.to }} sur {{ users.total }}
                    </p>
                    <div class="flex gap-1">
                        <Link
                            v-for="link in users.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            preserve-scroll
                            class="rounded-lg border px-3 py-1.5 text-xs"
                            :class="[
                                link.active
                                    ? 'border-blue-600 bg-blue-600 text-white'
                                    : 'border-slate-200 text-slate-600',
                                !link.url
                                    ? 'pointer-events-none opacity-40'
                                    : '',
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </section>
        </div>

        <Modal :show="modalOpen" max-width="2xl" @close="closeModal">
            <form class="grid gap-5 p-6 sm:p-8" @submit.prevent="submitUser">
                <div>
                    <h2 class="text-xl font-black text-slate-900">
                        {{
                            editingUser
                                ? "Modifier l’utilisateur"
                                : "Nouvel utilisateur"
                        }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{
                            editingUser
                                ? "Mettez à jour son identité et éventuellement son mot de passe."
                                : "Créez le compte et affectez-le directement."
                        }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                        >Nom complet<input
                            v-model="userForm.name"
                            required
                            class="w-full min-w-0 rounded-xl border-slate-200" /><InputError
                            :message="userForm.errors.name" /></label
                    ><label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                        >E-mail<input
                            v-model="userForm.email"
                            required
                            type="email"
                            class="w-full min-w-0 rounded-xl border-slate-200" /><InputError
                            :message="userForm.errors.email"
                    /></label>
                </div>
                <div class="grid min-w-0 gap-4 md:grid-cols-2">
                    <label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                        >Rôle<select
                            v-model="userForm.role_id"
                            class="w-full min-w-0 rounded-xl border-slate-200"
                        >
                            <option value="">Sans rôle</option>
                            <option
                                v-for="role in roles"
                                :key="role.id"
                                :value="role.id"
                            >
                                {{ role.name }}
                            </option></select
                        ><InputError
                            :message="userForm.errors.role_id" /></label
                    ><label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                        >Fonction<input
                            v-model="userForm.job_title"
                            class="w-full min-w-0 rounded-xl border-slate-200" /><InputError
                            :message="userForm.errors.job_title"
                    /></label>
                </div>
                <fieldset class="grid gap-3 rounded-xl border border-slate-200 p-4">
                    <legend class="px-1 text-sm font-bold text-slate-700">
                        Départements gérés / accessibles
                    </legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="item in departments"
                            :key="item.id"
                            class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm font-semibold transition"
                            :class="userForm.department_ids.includes(item.id) ? 'border-blue-300 bg-blue-50 text-blue-800' : 'border-slate-200 text-slate-600'"
                        >
                            <input
                                v-model="userForm.department_ids"
                                type="checkbox"
                                :value="item.id"
                                class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                            />
                            {{ item.name }}
                        </label>
                    </div>
                    <InputError :message="userForm.errors.department_ids" />
                </fieldset>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                        >Mot de passe {{ editingUser ? "(optionnel)" : ""
                        }}<input
                            v-model="userForm.password"
                            :required="!editingUser"
                            type="password"
                            class="w-full min-w-0 rounded-xl border-slate-200" /><InputError
                            :message="userForm.errors.password" /></label
                    ><label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                        >Confirmation<input
                            v-model="userForm.password_confirmation"
                            :required="
                                !editingUser || Boolean(userForm.password)
                            "
                            type="password"
                            class="w-full min-w-0 rounded-xl border-slate-200"
                    /></label>
                </div>
                <div
                    class="flex justify-end gap-2 border-t border-slate-100 pt-4"
                >
                    <button
                        type="button"
                        class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600"
                        @click="closeModal"
                    >
                        Annuler</button
                    ><button
                        :disabled="userForm.processing"
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50"
                    >
                        {{
                            userForm.processing
                                ? "Enregistrement..."
                                : "Enregistrer"
                        }}
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
