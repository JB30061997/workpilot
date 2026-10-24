<script setup>
import InputError from "@/Components/InputError.vue";
import Modal from "@/Components/Modal.vue";
import { useSweetAlert } from "@/Composables/useSweetAlert";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    organization: Object,
    organizations: Array,
    departments: Array,
    projectCategories: Array,
    organizationUsers: Array,
    taskStatuses: Array,
    roles: Array,
    permissions: Array,
    activeTab: String,
    canManageAccess: Boolean,
});
const { confirmAction } = useSweetAlert();
const tab = ref(props.activeTab || "statuses");
const modalOpen = ref(false);
const editingItem = ref(null);

const tabs = [
    {
        key: "statuses",
        label: "Statuts des tâches",
        count: props.taskStatuses.length,
    },
    {
        key: "organizations",
        label: "Sociétés",
        count: props.organizations.length,
    },
    {
        key: "departments",
        label: "Départements",
        count: props.departments.length,
    },
    {
        key: "project-categories",
        label: "Catégories de projet",
        count: props.projectCategories.length,
    },
    { key: "roles", label: "Rôles", count: props.roles.length },
    {
        key: "permissions",
        label: "Permissions",
        count: props.permissions.length,
    },
];
const titles = {
    statuses: "statut",
    organizations: "société",
    departments: "département",
    "project-categories": "catégorie de projet",
    roles: "rôle",
    permissions: "permission",
};

const statusForm = useForm({
    name: "",
    slug: "",
    color: "#3b82f6",
    position: 0,
    is_default: false,
    is_closed: false,
    is_active: true,
});
const organizationForm = useForm({
    name: "",
    legal_name: "",
    email: "",
    phone: "",
    country: "",
    city: "",
    is_active: true,
});
const departmentForm = useForm({
    name: "",
    code: "",
    description: "",
    parent_id: "",
    is_active: true,
});
const categoryForm = useForm({
    name: "",
    manager_id: "",
    description: "",
    is_active: true,
});
const roleForm = useForm({
    name: "",
    slug: "",
    description: "",
    permission_ids: [],
    is_active: true,
});
const permissionForm = useForm({
    name: "",
    slug: "",
    module: "",
    description: "",
    is_active: true,
});
const currentForm = computed(
    () =>
        ({
            statuses: statusForm,
            organizations: organizationForm,
            departments: departmentForm,
            "project-categories": categoryForm,
            roles: roleForm,
            permissions: permissionForm,
        })[tab.value],
);

const resetForms = () => {
    statusForm.reset();
    organizationForm.reset();
    departmentForm.reset();
    categoryForm.reset();
    roleForm.reset();
    permissionForm.reset();
    statusForm.color = "#3b82f6";
    statusForm.is_active = true;
    organizationForm.is_active = true;
    departmentForm.is_active = true;
    categoryForm.is_active = true;
    roleForm.is_active = true;
    permissionForm.is_active = true;
    [
        statusForm,
        organizationForm,
        departmentForm,
        categoryForm,
        roleForm,
        permissionForm,
    ].forEach((form) => form.clearErrors());
};
const openCreate = () => {
    editingItem.value = null;
    resetForms();
    modalOpen.value = true;
};
const openEdit = (item) => {
    editingItem.value = item;
    resetForms();
    if (tab.value === "statuses")
        Object.assign(statusForm, {
            name: item.name,
            slug: item.slug,
            color: item.color || "#3b82f6",
            position: item.position,
            is_default: Boolean(item.is_default),
            is_closed: Boolean(item.is_closed),
            is_active: Boolean(item.is_active),
        });
    if (tab.value === "organizations")
        Object.assign(organizationForm, {
            name: item.name,
            legal_name: item.legal_name || "",
            email: item.email || "",
            phone: item.phone || "",
            country: item.country || "",
            city: item.city || "",
            is_active: Boolean(item.is_active),
        });
    if (tab.value === "departments")
        Object.assign(departmentForm, {
            name: item.name,
            code: item.code || "",
            description: item.description || "",
            parent_id: item.parent_id || "",
            is_active: Boolean(item.is_active),
        });
    if (tab.value === "project-categories")
        Object.assign(categoryForm, {
            name: item.name,
            manager_id: item.manager_id,
            description: item.description || "",
            is_active: Boolean(item.is_active),
        });
    if (tab.value === "roles")
        Object.assign(roleForm, {
            name: item.name,
            slug: item.slug,
            description: item.description || "",
            permission_ids: item.permissions.map((permission) => permission.id),
            is_active: Boolean(item.is_active),
        });
    if (tab.value === "permissions")
        Object.assign(permissionForm, {
            name: item.name,
            slug: item.slug,
            module: item.module,
            description: item.description || "",
            is_active: Boolean(item.is_active),
        });
    modalOpen.value = true;
};
const closeModal = () => {
    if (currentForm.value.processing) return;
    modalOpen.value = false;
    editingItem.value = null;
    resetForms();
};
const resource = computed(
    () =>
        ({
            statuses: "task-statuses",
            organizations: "organizations",
            departments: "departments",
            "project-categories": "project-categories",
            roles: "roles",
            permissions: "permissions",
        })[tab.value],
);
const submit = () => {
    const options = { preserveScroll: true, onSuccess: closeModal };
    if (editingItem.value) {
        currentForm.value.patch(
            route(`${resource.value}.update`, editingItem.value.id),
            options,
        );
        return;
    }
    currentForm.value.post(route(`${resource.value}.store`), options);
};
const remove = async (item) => {
    if (await confirmAction({ title: `Supprimer « ${item.name} » ?`, text: 'Cet élément sera retiré du référentiel.', confirmButtonText: 'Supprimer' }))
        router.delete(route(`${resource.value}.destroy`, item.id), {
            preserveScroll: true,
        });
};
const switchTab = (key) => {
    tab.value = key;
    editingItem.value = null;
    modalOpen.value = false;
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Référentiel - WorkPilot" />
        <div class="mx-auto grid min-w-0 max-w-[1700px] gap-4 px-3 py-5 sm:px-5 lg:px-7">
            <section
                class="wp-page-header px-5 py-5 sm:px-7"
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
                        <h2 class="mt-1 text-2xl font-bold">Référentiel</h2>
                        <p class="mt-2 text-sm text-slate-400">
                            Configurez les données communes utilisées par
                            WorkPilot.
                        </p>
                    </div>
                    <button
                        v-if="!['roles', 'permissions', 'project-categories'].includes(tab) || canManageAccess"
                        class="rounded-[4px] bg-blue-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-700"
                        @click="openCreate"
                    >
                        + Ajouter {{ titles[tab] }}
                    </button>
                </div>
            </section>

            <div
                class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white px-3 shadow-sm"
            >
                <nav class="flex min-w-max gap-1">
                    <button
                        v-for="item in tabs"
                        :key="item.key"
                        class="border-b-2 px-4 py-4 text-sm font-bold transition"
                        :class="
                            tab === item.key
                                ? 'border-blue-600 text-blue-600'
                                : 'border-transparent text-slate-400 hover:text-slate-700'
                        "
                        @click="switchTab(item.key)"
                    >
                        {{ item.label }}
                        <span
                            class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px]"
                            >{{ item.count }}</span
                        >
                    </button>
                </nav>
            </div>

            <section
                class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div
                    v-if="tab === 'statuses'"
                    class="divide-y divide-slate-100"
                >
                    <div
                        v-for="item in taskStatuses"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-4 px-5 py-4 hover:bg-slate-50"
                    >
                        <span
                            class="h-4 w-4 rounded-full"
                            :style="{
                                backgroundColor: item.color || '#94a3b8',
                            }"
                        />
                        <div class="min-w-48 flex-1">
                            <p class="font-bold text-slate-900">
                                {{ item.name }}
                            </p>
                            <p class="text-xs text-slate-400">
                                {{ item.slug }} · Position {{ item.position }}
                            </p>
                        </div>
                        <div class="flex gap-2 text-[10px] font-bold">
                            <span
                                v-if="item.is_default"
                                class="rounded-full bg-blue-50 px-2 py-1 text-blue-700"
                                >Par défaut</span
                            ><span
                                v-if="item.is_closed"
                                class="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700"
                                >Fermé</span
                            ><span
                                :class="
                                    item.is_active
                                        ? 'bg-emerald-50 text-emerald-700'
                                        : 'bg-slate-100 text-slate-500'
                                "
                                class="rounded-full px-2 py-1"
                                >{{
                                    item.is_active ? "Actif" : "Inactif"
                                }}</span
                            >
                        </div>
                        <p class="w-24 text-right text-xs text-slate-400">
                            {{ item.tasks_count }} tâches
                        </p>
                        <div class="flex gap-3">
                            <button
                                v-if="item.can_update"
                                class="text-xs font-bold text-blue-600"
                                @click="openEdit(item)"
                            >
                                Modifier</button
                            ><button
                                v-if="item.can_delete"
                                class="text-xs font-bold text-rose-600"
                                @click="remove(item)"
                            >
                                Supprimer
                            </button>
                        </div>
                    </div>
                    <p
                        v-if="!taskStatuses.length"
                        class="p-12 text-center text-sm text-slate-400"
                    >
                        Aucun statut.
                    </p>
                </div>

                <div
                    v-else-if="tab === 'organizations'"
                    class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3"
                >
                    <article
                        v-for="item in organizations"
                        :key="item.id"
                        class="rounded-2xl border p-5"
                        :class="
                            Number(item.id) === Number(organization.id)
                                ? 'border-blue-300 bg-blue-50/40'
                                : 'border-slate-200'
                        "
                    >
                        <div class="flex justify-between gap-3">
                            <div>
                                <p class="font-black text-slate-900">
                                    {{ item.name }}
                                </p>
                                <p class="mt-1 text-xs text-slate-400">
                                    {{
                                        item.city ||
                                        item.country ||
                                        "Localisation non définie"
                                    }}
                                </p>
                            </div>
                            <span
                                class="rounded-full px-2 py-1 text-[10px] font-bold"
                                :class="
                                    item.is_active
                                        ? 'bg-emerald-50 text-emerald-700'
                                        : 'bg-slate-100 text-slate-500'
                                "
                                >{{
                                    item.is_active ? "Active" : "Inactive"
                                }}</span
                            >
                        </div>
                        <p class="mt-4 text-xs text-slate-500">
                            {{ item.departments_count }} départements ·
                            {{ item.users_count }} utilisateurs
                        </p>
                        <div class="mt-4 flex justify-end gap-3">
                            <button
                                class="text-xs font-bold text-blue-600"
                                @click="openEdit(item)"
                            >
                                Modifier</button
                            ><button
                                class="text-xs font-bold text-rose-600"
                                @click="remove(item)"
                            >
                                Supprimer
                            </button>
                        </div>
                    </article>
                </div>

                <div
                    v-else-if="tab === 'departments'"
                    class="divide-y divide-slate-100"
                >
                    <div
                        v-for="item in departments"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-4 px-5 py-4"
                    >
                        <div class="flex-1">
                            <p class="font-bold text-slate-900">
                                {{ item.name }}
                                <span
                                    class="ml-2 text-xs font-medium text-slate-400"
                                    >{{ item.code }}</span
                                >
                            </p>
                            <p class="text-xs text-slate-400">
                                Parent : {{ item.parent?.name || "Aucun" }}
                            </p>
                        </div>
                        <p class="text-xs text-slate-500">
                            {{ item.users_count }} utilisateurs ·
                            {{ item.projects_count }} projets
                        </p>
                        <span
                            class="rounded-full px-2 py-1 text-[10px] font-bold"
                            :class="
                                item.is_active
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : 'bg-slate-100 text-slate-500'
                            "
                            >{{ item.is_active ? "Actif" : "Inactif" }}</span
                        >
                        <div class="flex gap-3">
                            <button
                                class="text-xs font-bold text-blue-600"
                                @click="openEdit(item)"
                            >
                                Modifier</button
                            ><button
                                class="text-xs font-bold text-rose-600"
                                @click="remove(item)"
                            >
                                Supprimer
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="tab === 'project-categories'"
                    class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3"
                >
                    <article
                        v-for="item in projectCategories"
                        :key="item.id"
                        class="rounded-2xl border border-slate-200 p-5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-black text-slate-900">{{ item.name }}</p>
                                <p class="mt-1 text-xs text-slate-400">{{ item.slug }}</p>
                            </div>
                            <span
                                class="rounded-full px-2 py-1 text-[10px] font-bold"
                                :class="item.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                            >{{ item.is_active ? "Active" : "Inactive" }}</span>
                        </div>
                        <p class="mt-4 text-sm font-semibold text-blue-700">
                            Chef de projet : {{ item.manager?.name || "Non assigné" }}
                        </p>
                        <p class="mt-2 text-xs text-slate-500">
                            {{ item.description || "Aucune description" }}
                        </p>
                        <div class="mt-4 flex items-center justify-between">
                            <p class="text-xs text-slate-400">{{ item.projects_count }} projet(s)</p>
                            <div v-if="canManageAccess" class="flex gap-3">
                                <button class="text-xs font-bold text-blue-600" @click="openEdit(item)">Modifier</button>
                                <button class="text-xs font-bold text-rose-600" @click="remove(item)">Supprimer</button>
                            </div>
                        </div>
                    </article>
                    <p v-if="!projectCategories.length" class="p-8 text-sm text-slate-400">Aucune catégorie de projet.</p>
                </div>

                <div
                    v-else-if="tab === 'roles'"
                    class="grid gap-4 p-5 lg:grid-cols-2"
                >
                    <article
                        v-for="item in roles"
                        :key="item.id"
                        class="rounded-2xl border border-slate-200 p-5"
                    >
                        <div class="flex justify-between">
                            <div>
                                <p class="font-black text-slate-900">
                                    {{ item.name }}
                                </p>
                                <p class="text-xs text-slate-400">
                                    {{ item.slug }}
                                </p>
                            </div>
                            <span
                                v-if="item.is_system"
                                class="rounded-full bg-violet-50 px-2 py-1 text-[10px] font-bold text-violet-700"
                                >Système</span
                            >
                        </div>
                        <p class="mt-3 text-sm text-slate-500">
                            {{ item.description || "Aucune description" }}
                        </p>
                        <div class="mt-3 flex flex-wrap gap-1">
                            <span
                                v-for="permission in item.permissions.slice(
                                    0,
                                    5,
                                )"
                                :key="permission.id"
                                class="rounded bg-slate-100 px-2 py-1 text-[9px] font-bold text-slate-600"
                                >{{ permission.name }}</span
                            ><span
                                v-if="item.permissions.length > 5"
                                class="text-xs text-slate-400"
                                >+{{ item.permissions.length - 5 }}</span
                            >
                        </div>
                        <div class="mt-4 flex items-center justify-between">
                            <p class="text-xs text-slate-400">
                                {{ item.users_count }} utilisateur(s)
                            </p>
                            <div
                                v-if="canManageAccess && !item.is_system"
                                class="flex gap-3"
                            >
                                <button
                                    class="text-xs font-bold text-blue-600"
                                    @click="openEdit(item)"
                                >
                                    Modifier</button
                                ><button
                                    class="text-xs font-bold text-rose-600"
                                    @click="remove(item)"
                                >
                                    Supprimer
                                </button>
                            </div>
                        </div>
                    </article>
                </div>

                <div v-else class="divide-y divide-slate-100">
                    <div
                        v-for="item in permissions"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-4 px-5 py-4"
                    >
                        <div class="flex-1">
                            <p class="font-bold text-slate-900">
                                {{ item.name }}
                            </p>
                            <p class="text-xs text-slate-400">
                                {{ item.module }} · {{ item.slug }}
                            </p>
                        </div>
                        <p class="text-xs text-slate-500">
                            {{ item.roles_count }} rôle(s)
                        </p>
                        <span
                            class="rounded-full px-2 py-1 text-[10px] font-bold"
                            :class="
                                item.is_active
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : 'bg-slate-100 text-slate-500'
                            "
                            >{{ item.is_active ? "Active" : "Inactive" }}</span
                        >
                        <div v-if="canManageAccess" class="flex gap-3">
                            <button
                                class="text-xs font-bold text-blue-600"
                                @click="openEdit(item)"
                            >
                                Modifier</button
                            ><button
                                class="text-xs font-bold text-rose-600"
                                @click="remove(item)"
                            >
                                Supprimer
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <Modal :show="modalOpen" max-width="2xl" @close="closeModal">
            <form class="grid gap-5 p-6 sm:p-8" @submit.prevent="submit">
                <div>
                    <h2 class="text-xl font-black text-slate-900">
                        {{ editingItem ? "Modifier" : "Ajouter" }}
                        {{ titles[tab] }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Renseignez les informations puis enregistrez.
                    </p>
                </div>
                <template v-if="tab === 'statuses'"
                    ><div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm font-bold"
                            >Nom<input
                                v-model="statusForm.name"
                                required
                                class="rounded-xl border-slate-200" /><InputError
                                :message="statusForm.errors.name" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Slug<input
                                v-model="statusForm.slug"
                                class="rounded-xl border-slate-200" /><InputError
                                :message="statusForm.errors.slug" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Couleur<input
                                v-model="statusForm.color"
                                type="color"
                                class="h-11 w-full rounded-xl border-slate-200 p-1" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Position<input
                                v-model.number="statusForm.position"
                                type="number"
                                min="0"
                                class="rounded-xl border-slate-200"
                        /></label>
                    </div>
                    <div class="flex flex-wrap gap-5 text-sm">
                        <label
                            ><input
                                v-model="statusForm.is_default"
                                type="checkbox"
                                class="mr-2 rounded"
                            />Par défaut</label
                        ><label
                            ><input
                                v-model="statusForm.is_closed"
                                type="checkbox"
                                class="mr-2 rounded"
                            />Statut fermé</label
                        ><label
                            ><input
                                v-model="statusForm.is_active"
                                type="checkbox"
                                class="mr-2 rounded"
                            />Actif</label
                        >
                    </div></template
                >
                <template v-else-if="tab === 'organizations'"
                    ><div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm font-bold"
                            >Nom<input
                                v-model="organizationForm.name"
                                required
                                class="rounded-xl border-slate-200" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Raison sociale<input
                                v-model="organizationForm.legal_name"
                                class="rounded-xl border-slate-200" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >E-mail<input
                                v-model="organizationForm.email"
                                type="email"
                                class="rounded-xl border-slate-200" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Téléphone<input
                                v-model="organizationForm.phone"
                                class="rounded-xl border-slate-200" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Pays<input
                                v-model="organizationForm.country"
                                class="rounded-xl border-slate-200" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Ville<input
                                v-model="organizationForm.city"
                                class="rounded-xl border-slate-200"
                        /></label>
                    </div>
                    <label class="text-sm"
                        ><input
                            v-model="organizationForm.is_active"
                            type="checkbox"
                            class="mr-2 rounded"
                        />Société active</label
                    ></template
                >
                <template v-else-if="tab === 'departments'"
                    ><div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm font-bold"
                            >Nom<input
                                v-model="departmentForm.name"
                                required
                                class="rounded-xl border-slate-200" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Code<input
                                v-model="departmentForm.code"
                                class="rounded-xl border-slate-200" /></label
                        ><label
                            class="grid gap-1 text-sm font-bold sm:col-span-2"
                            >Département parent<select
                                v-model="departmentForm.parent_id"
                                class="rounded-xl border-slate-200"
                            >
                                <option value="">Aucun</option>
                                <option
                                    v-for="item in departments.filter(
                                        (department) =>
                                            department.id !== editingItem?.id,
                                    )"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ item.name }}
                                </option>
                            </select></label
                        ><label
                            class="grid gap-1 text-sm font-bold sm:col-span-2"
                            >Description<textarea
                                v-model="departmentForm.description"
                                rows="3"
                                class="rounded-xl border-slate-200"
                            />
                        </label>
                    </div>
                    <label class="text-sm"
                        ><input
                            v-model="departmentForm.is_active"
                            type="checkbox"
                            class="mr-2 rounded"
                        />Département actif</label
                    ></template
                >
                <template v-else-if="tab === 'project-categories'">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm font-bold">
                            Nom
                            <input v-model="categoryForm.name" required class="rounded-xl border-slate-200" />
                            <InputError :message="categoryForm.errors.name" />
                        </label>
                        <label class="grid gap-1 text-sm font-bold">
                            Chef de projet
                            <select v-model="categoryForm.manager_id" required class="rounded-xl border-slate-200">
                                <option value="" disabled>Choisir un responsable</option>
                                <option v-for="user in organizationUsers" :key="user.id" :value="user.id">
                                    {{ user.name }} · {{ user.email }}
                                </option>
                            </select>
                            <InputError :message="categoryForm.errors.manager_id" />
                        </label>
                        <label class="grid gap-1 text-sm font-bold sm:col-span-2">
                            Description
                            <textarea v-model="categoryForm.description" rows="3" class="rounded-xl border-slate-200" />
                            <InputError :message="categoryForm.errors.description" />
                        </label>
                    </div>
                    <label class="text-sm">
                        <input v-model="categoryForm.is_active" type="checkbox" class="mr-2 rounded" />Catégorie active
                    </label>
                </template>
                <template v-else-if="tab === 'roles'"
                    ><div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm font-bold"
                            >Nom<input
                                v-model="roleForm.name"
                                required
                                class="rounded-xl border-slate-200" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Slug<input
                                v-model="roleForm.slug"
                                class="rounded-xl border-slate-200" /></label
                        ><label
                            class="grid gap-1 text-sm font-bold sm:col-span-2"
                            >Description<textarea
                                v-model="roleForm.description"
                                rows="2"
                                class="rounded-xl border-slate-200"
                            />
                        </label>
                    </div>
                    <div>
                        <p class="mb-2 text-sm font-bold">Permissions</p>
                        <div
                            class="grid max-h-52 gap-2 overflow-y-auto rounded-xl border border-slate-200 p-3 sm:grid-cols-2"
                        >
                            <label
                                v-for="permission in permissions"
                                :key="permission.id"
                                class="text-xs text-slate-600"
                                ><input
                                    v-model="roleForm.permission_ids"
                                    type="checkbox"
                                    :value="permission.id"
                                    class="mr-2 rounded"
                                />{{ permission.name }}</label
                            >
                        </div>
                    </div></template
                >
                <template v-else
                    ><div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm font-bold"
                            >Nom<input
                                v-model="permissionForm.name"
                                required
                                class="rounded-xl border-slate-200" /></label
                        ><label class="grid gap-1 text-sm font-bold"
                            >Module<input
                                v-model="permissionForm.module"
                                required
                                class="rounded-xl border-slate-200" /></label
                        ><label
                            class="grid gap-1 text-sm font-bold sm:col-span-2"
                            >Slug<input
                                v-model="permissionForm.slug"
                                class="rounded-xl border-slate-200" /></label
                        ><label
                            class="grid gap-1 text-sm font-bold sm:col-span-2"
                            >Description<textarea
                                v-model="permissionForm.description"
                                rows="3"
                                class="rounded-xl border-slate-200"
                            />
                        </label></div
                ></template>
                <InputError
                    :message="
                        currentForm.errors.name ||
                        currentForm.errors.role ||
                        currentForm.errors.permission
                    "
                />
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
                        :disabled="currentForm.processing"
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50"
                    >
                        {{
                            currentForm.processing
                                ? "Enregistrement..."
                                : "Enregistrer"
                        }}
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
