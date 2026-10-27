<script setup>
import InputError from "@/Components/InputError.vue";
import Modal from "@/Components/Modal.vue";
import { useSweetAlert } from "@/Composables/useSweetAlert";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import {
    Check,
    KeyRound,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    Trash2,
    Users,
    X,
} from "lucide-vue-next";
import { computed, ref } from "vue";

const props = defineProps({
    organization: Object,
    roles: Array,
    permissions: Array,
    departments: Array,
    members: Array,
    canManageAccess: Boolean,
});

const { confirmAction } = useSweetAlert();
const activeTab = ref("roles");
const search = ref("");
const selectedModule = ref("");
const roleModalOpen = ref(false);
const permissionModalOpen = ref(false);
const editingRole = ref(null);
const editingPermission = ref(null);
const assignmentModalOpen = ref(false);
const editingMember = ref(null);

const roleForm = useForm({
    name: "",
    slug: "",
    description: "",
    is_active: true,
    permission_ids: [],
});
const permissionForm = useForm({
    name: "",
    slug: "",
    module: "",
    description: "",
    is_active: true,
});
const assignmentForm = useForm({
    role_id: "",
    department_ids: [],
});

const modules = computed(() => [
    ...new Set(
        props.permissions
            .map((permission) => permission.module)
            .filter(Boolean),
    ),
]);
const filteredRoles = computed(() => {
    const query = search.value.trim().toLocaleLowerCase("fr");
    return props.roles.filter(
        (role) =>
            !query ||
            [role.name, role.slug, role.description].some((value) =>
                value?.toLocaleLowerCase("fr").includes(query),
            ),
    );
});
const filteredPermissions = computed(() => {
    const query = search.value.trim().toLocaleLowerCase("fr");
    return props.permissions.filter(
        (permission) =>
            (!selectedModule.value ||
                permission.module === selectedModule.value) &&
            (!query ||
                [permission.name, permission.slug, permission.module].some(
                    (value) => value?.toLocaleLowerCase("fr").includes(query),
                )),
    );
});
const groupedPermissions = computed(() =>
    filteredPermissions.value.reduce((groups, permission) => {
        const module = permission.module || "Autres";
        groups[module] ||= [];
        groups[module].push(permission);

        return groups;
    }, {}),
);
const activeRolesCount = computed(
    () => props.roles.filter((role) => role.is_active).length,
);
const assignedUsersCount = computed(() =>
    props.roles.reduce(
        (total, role) => total + Number(role.users_count || 0),
        0,
    ),
);

const roleAccent = (slug) =>
    ({
        administrator: "from-slate-800 to-slate-950",
        director: "from-blue-600 to-indigo-700",
        "deputy-director": "from-violet-600 to-purple-700",
        "department-manager": "from-cyan-600 to-blue-700",
        "project-manager": "from-amber-500 to-orange-600",
        collaborator: "from-emerald-500 to-teal-600",
    })[slug] || "from-slate-500 to-slate-700";

const resetRoleForm = () => {
    roleForm.reset();
    roleForm.is_active = true;
    roleForm.permission_ids = [];
    roleForm.clearErrors();
};
const openRoleCreate = () => {
    editingRole.value = null;
    resetRoleForm();
    roleModalOpen.value = true;
};
const openRoleEdit = (role) => {
    editingRole.value = role;
    roleForm.clearErrors();
    Object.assign(roleForm, {
        name: role.name,
        slug: role.slug,
        description: role.description || "",
        is_active: Boolean(role.is_active),
        permission_ids: role.permissions.map((permission) => permission.id),
    });
    roleModalOpen.value = true;
};
const closeRoleModal = () => {
    if (roleForm.processing) return;
    roleModalOpen.value = false;
    editingRole.value = null;
    resetRoleForm();
};
const submitRole = () => {
    if (editingRole.value) {
        const payload = editingRole.value.is_system
            ? {
                  description: roleForm.description,
                  permission_ids: roleForm.permission_ids,
              }
            : roleForm.data();
        router.patch(route("roles.update", editingRole.value.id), payload, {
            preserveScroll: true,
            onSuccess: closeRoleModal,
            onError: (errors) =>
                Object.entries(errors).forEach(([field, message]) =>
                    roleForm.setError(field, message),
                ),
        });
        return;
    }
    roleForm.post(route("roles.store"), {
        preserveScroll: true,
        onSuccess: closeRoleModal,
    });
};

const resetPermissionForm = () => {
    permissionForm.reset();
    permissionForm.is_active = true;
    permissionForm.clearErrors();
};
const openPermissionCreate = () => {
    editingPermission.value = null;
    resetPermissionForm();
    permissionModalOpen.value = true;
};
const openPermissionEdit = (permission) => {
    editingPermission.value = permission;
    permissionForm.clearErrors();
    Object.assign(permissionForm, {
        name: permission.name,
        slug: permission.slug,
        module: permission.module || "",
        description: permission.description || "",
        is_active: Boolean(permission.is_active),
    });
    permissionModalOpen.value = true;
};
const closePermissionModal = () => {
    if (permissionForm.processing) return;
    permissionModalOpen.value = false;
    editingPermission.value = null;
    resetPermissionForm();
};
const submitPermission = () => {
    const options = { preserveScroll: true, onSuccess: closePermissionModal };
    if (editingPermission.value) {
        permissionForm.patch(
            route("permissions.update", editingPermission.value.id),
            options,
        );
        return;
    }
    permissionForm.post(route("permissions.store"), options);
};

const removeRole = async (role) => {
    if (
        await confirmAction({
            title: `Supprimer « ${role.name} » ?`,
            text: "Le rôle doit être retiré de tous les utilisateurs avant sa suppression.",
            confirmButtonText: "Supprimer le rôle",
        })
    ) {
        router.delete(route("roles.destroy", role.id), {
            preserveScroll: true,
        });
    }
};
const removePermission = async (permission) => {
    if (
        await confirmAction({
            title: `Supprimer « ${permission.name} » ?`,
            text: "La permission ne doit être liée à aucun rôle.",
            confirmButtonText: "Supprimer la permission",
        })
    ) {
        router.delete(route("permissions.destroy", permission.id), {
            preserveScroll: true,
        });
    }
};
const openAssignment = (member) => {
    editingMember.value = member;
    assignmentForm.role_id = member.roles?.[0]?.id ?? "";
    assignmentForm.department_ids = (member.departments ?? []).map(
        (department) => department.id,
    );
    assignmentForm.clearErrors();
    assignmentModalOpen.value = true;
};
const closeAssignment = () => {
    if (assignmentForm.processing) return;
    assignmentModalOpen.value = false;
    editingMember.value = null;
    assignmentForm.reset();
};
const submitAssignment = () => {
    assignmentForm.patch(route("users.update", editingMember.value.id), {
        preserveScroll: true,
        onSuccess: closeAssignment,
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Rôles & permissions - WorkPilot" />
        <main
            class="mx-auto grid min-w-0 max-w-[1700px] gap-5 px-3 py-5 sm:px-5 lg:px-7"
        >
            <section class="wp-page-header overflow-hidden px-5 py-6 sm:px-7">
                <div
                    class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.2em] text-blue-600"
                        >
                            Administration / {{ organization.name }}
                        </p>
                        <h2 class="mt-1 text-2xl font-bold text-slate-950">
                            Rôles & permissions
                        </h2>
                        <p class="mt-2 text-sm text-slate-400">
                            Définissez précisément qui peut consulter, créer,
                            modifier et administrer chaque espace.
                        </p>
                    </div>
                    <button
                        v-if="canManageAccess && activeTab !== 'assignments'"
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700"
                        @click="
                            activeTab === 'roles'
                                ? openRoleCreate()
                                : openPermissionCreate()
                        "
                    >
                        <Plus class="h-4 w-4" />{{
                            activeTab === "roles"
                                ? "Nouveau rôle"
                                : "Nouvelle permission"
                        }}
                    </button>
                </div>
            </section>

            <section class="grid gap-3 sm:grid-cols-3">
                <article
                    class="rounded-2xl border border-blue-100 bg-blue-50 p-5"
                >
                    <ShieldCheck class="h-6 w-6 text-blue-600" />
                    <p class="mt-4 text-2xl font-black text-slate-950">
                        {{ activeRolesCount }}
                    </p>
                    <p
                        class="text-xs font-bold uppercase tracking-wide text-slate-400"
                    >
                        Rôles actifs
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-violet-100 bg-violet-50 p-5"
                >
                    <KeyRound class="h-6 w-6 text-violet-600" />
                    <p class="mt-4 text-2xl font-black text-slate-950">
                        {{ permissions.length }}
                    </p>
                    <p
                        class="text-xs font-bold uppercase tracking-wide text-slate-400"
                    >
                        Permissions
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-emerald-100 bg-emerald-50 p-5"
                >
                    <Users class="h-6 w-6 text-emerald-600" />
                    <p class="mt-4 text-2xl font-black text-slate-950">
                        {{ assignedUsersCount }}
                    </p>
                    <p
                        class="text-xs font-bold uppercase tracking-wide text-slate-400"
                    >
                        Affectations utilisateurs
                    </p>
                </article>
            </section>

            <section
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div
                    class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="flex rounded-xl bg-slate-100 p-1">
                        <button
                            v-for="item in [
                                {
                                    key: 'roles',
                                    label: 'Rôles',
                                    count: roles.length,
                                },
                                {
                                    key: 'permissions',
                                    label: 'Permissions',
                                    count: permissions.length,
                                },
                                {
                                    key: 'assignments',
                                    label: 'Affectations',
                                    count: members.length,
                                },
                            ]"
                            :key="item.key"
                            type="button"
                            class="rounded-lg px-4 py-2 text-sm font-bold transition"
                            :class="
                                activeTab === item.key
                                    ? 'bg-white text-blue-700 shadow-sm'
                                    : 'text-slate-500'
                            "
                            @click="
                                activeTab = item.key;
                                search = '';
                            "
                        >
                            {{ item.label }}
                            <span class="ml-1 text-xs text-slate-400">{{
                                item.count
                            }}</span>
                        </button>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <label class="relative min-w-[280px]"
                            ><Search
                                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><input
                                v-model="search"
                                type="search"
                                :placeholder="
                                    activeTab === 'roles'
                                        ? 'Rechercher un rôle...'
                                        : 'Rechercher une permission...'
                                "
                                class="w-full rounded-xl border-slate-200 py-2.5 pl-10 text-sm focus:border-blue-500 focus:ring-blue-500"
                        /></label>
                        <select
                            v-if="activeTab === 'permissions'"
                            v-model="selectedModule"
                            class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="">Tous les modules</option>
                            <option
                                v-for="module in modules"
                                :key="module"
                                :value="module"
                            >
                                {{ module }}
                            </option>
                        </select>
                    </div>
                </div>

                <div
                    v-if="activeTab === 'roles'"
                    class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"
                >
                    <article
                        v-for="role in filteredRoles"
                        :key="role.id"
                        class="group overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-slate-200/60"
                    >
                        <div
                            class="h-1.5 bg-gradient-to-r"
                            :class="roleAccent(role.slug)"
                        />
                        <div class="p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span
                                        class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br text-sm font-black text-white"
                                        :class="roleAccent(role.slug)"
                                        >{{
                                            role.name
                                                .split(" ")
                                                .filter(Boolean)
                                                .slice(0, 2)
                                                .map((word) => word[0])
                                                .join("")
                                        }}</span
                                    >
                                    <div class="min-w-0">
                                        <h3
                                            class="truncate font-black text-slate-900"
                                        >
                                            {{ role.name }}
                                        </h3>
                                        <p
                                            class="truncate text-xs text-slate-400"
                                        >
                                            {{ role.slug }}
                                        </p>
                                    </div>
                                </div>
                                <span
                                    v-if="role.is_system"
                                    class="rounded-full bg-blue-50 px-2 py-1 text-[9px] font-black uppercase text-blue-700"
                                    >Système</span
                                >
                            </div>
                            <p
                                class="mt-4 min-h-10 text-sm leading-5 text-slate-500"
                            >
                                {{ role.description || "Aucune description." }}
                            </p>
                            <div class="mt-4 flex flex-wrap gap-1.5">
                                <span
                                    v-for="permission in role.permissions.slice(
                                        0,
                                        4,
                                    )"
                                    :key="permission.id"
                                    class="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600"
                                    >{{ permission.name }}</span
                                ><span
                                    v-if="role.permissions.length > 4"
                                    class="rounded-lg bg-blue-50 px-2 py-1 text-[10px] font-bold text-blue-700"
                                    >+{{ role.permissions.length - 4 }}</span
                                >
                            </div>
                            <div
                                class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4"
                            >
                                <div
                                    class="flex gap-4 text-xs font-semibold text-slate-400"
                                >
                                    <span
                                        >{{
                                            role.permissions_count
                                        }}
                                        droits</span
                                    ><span
                                        >{{
                                            role.users_count
                                        }}
                                        utilisateurs</span
                                    >
                                </div>
                                <div v-if="canManageAccess" class="flex gap-1">
                                    <button
                                        type="button"
                                        class="grid h-9 w-9 place-items-center rounded-lg text-blue-600 transition hover:bg-blue-50"
                                        title="Modifier"
                                        @click="openRoleEdit(role)"
                                    >
                                        <Pencil class="h-4 w-4" /></button
                                    ><button
                                        v-if="!role.is_system"
                                        type="button"
                                        class="grid h-9 w-9 place-items-center rounded-lg text-rose-600 transition hover:bg-rose-50"
                                        title="Supprimer"
                                        @click="removeRole(role)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </article>
                    <p
                        v-if="!filteredRoles.length"
                        class="p-12 text-center text-sm text-slate-400 md:col-span-2 xl:col-span-3"
                    >
                        Aucun rôle trouvé.
                    </p>
                </div>

                <div
                    v-else-if="activeTab === 'permissions'"
                    class="divide-y divide-slate-100"
                >
                    <section
                        v-for="(
                            modulePermissions, module
                        ) in groupedPermissions"
                        :key="module"
                        class="p-5"
                    >
                        <div class="mb-3 flex items-center gap-2">
                            <span
                                class="grid h-8 w-8 place-items-center rounded-lg bg-violet-50 text-violet-600"
                                ><KeyRound class="h-4 w-4"
                            /></span>
                            <h3 class="font-black capitalize text-slate-900">
                                {{ module.replaceAll("_", " ") }}
                            </h3>
                            <span
                                class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500"
                                >{{ modulePermissions.length }}</span
                            >
                        </div>
                        <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                            <article
                                v-for="permission in modulePermissions"
                                :key="permission.id"
                                class="flex items-center gap-3 rounded-xl border border-slate-200 p-3"
                            >
                                <span
                                    class="grid h-8 w-8 shrink-0 place-items-center rounded-lg"
                                    :class="
                                        permission.is_active
                                            ? 'bg-emerald-50 text-emerald-600'
                                            : 'bg-slate-100 text-slate-400'
                                    "
                                    ><Check
                                        v-if="permission.is_active"
                                        class="h-4 w-4" /><X
                                        v-else
                                        class="h-4 w-4"
                                /></span>
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-sm font-bold text-slate-800"
                                    >
                                        {{ permission.name }}
                                    </p>
                                    <p
                                        class="truncate text-[10px] text-slate-400"
                                    >
                                        {{ permission.slug }} ·
                                        {{ permission.roles_count }} rôle(s)
                                    </p>
                                </div>
                                <div v-if="canManageAccess" class="flex">
                                    <button
                                        type="button"
                                        class="p-2 text-blue-600"
                                        title="Modifier"
                                        @click="openPermissionEdit(permission)"
                                    >
                                        <Pencil class="h-4 w-4" /></button
                                    ><button
                                        type="button"
                                        class="p-2 text-rose-600"
                                        title="Supprimer"
                                        @click="removePermission(permission)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>
                    <p
                        v-if="!filteredPermissions.length"
                        class="p-12 text-center text-sm text-slate-400"
                    >
                        Aucune permission trouvée.
                    </p>
                </div>
                <div v-else class="grid gap-3 p-4 lg:grid-cols-2">
                    <article
                        v-for="member in members"
                        :key="member.id"
                        class="flex items-start justify-between gap-4 rounded-2xl border border-slate-200 p-4"
                    >
                        <div class="min-w-0">
                            <p class="font-black text-slate-900">{{ member.name }}</p>
                            <p class="text-xs text-slate-400">{{ member.email }}</p>
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <span
                                    v-for="role in member.roles"
                                    :key="role.id"
                                    class="rounded-full bg-blue-50 px-2.5 py-1 text-[10px] font-bold text-blue-700"
                                >{{ role.name }}</span>
                                <span
                                    v-for="item in member.departments"
                                    :key="`department-${item.id}`"
                                    class="rounded-full bg-cyan-50 px-2.5 py-1 text-[10px] font-bold text-cyan-700"
                                >{{ item.name }}</span>
                            </div>
                        </div>
                        <button
                            v-if="canManageAccess"
                            type="button"
                            class="shrink-0 rounded-xl border border-blue-200 px-3 py-2 text-xs font-bold text-blue-700 hover:bg-blue-50"
                            @click="openAssignment(member)"
                        >
                            Affecter
                        </button>
                    </article>
                </div>
            </section>
        </main>

        <Modal :show="roleModalOpen" max-width="screen" @close="closeRoleModal">
            <form
                class="flex h-[85dvh] max-h-[85dvh] min-h-0 flex-col overflow-hidden"
                @submit.prevent="submitRole"
            >
                <header
                    class="shrink-0 border-b border-slate-200 bg-white px-6 py-5 sm:px-8 lg:px-10"
                >
                    <p
                        class="text-[10px] font-black uppercase tracking-[0.18em] text-blue-600"
                    >
                        Contrôle d’accès
                    </p>
                    <h2 class="mt-1 text-xl font-black text-slate-900">
                        {{ editingRole ? "Modifier le rôle" : "Créer un rôle" }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Configurez son identité et les permissions auxquelles il
                        donne accès.
                    </p>
                </header>

                <div
                    class="min-h-0 flex-1 overflow-y-auto overscroll-contain space-y-5 px-6 py-6 sm:px-8 lg:px-10"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label
                            class="grid gap-1.5 text-sm font-bold text-slate-700"
                            >Nom<input
                                v-model="roleForm.name"
                                required
                                :disabled="editingRole?.is_system"
                                class="rounded-xl border-slate-200 disabled:bg-slate-100" /><InputError
                                :message="roleForm.errors.name" /></label
                        ><label
                            class="grid gap-1.5 text-sm font-bold text-slate-700"
                            >Slug<input
                                v-model="roleForm.slug"
                                :disabled="editingRole?.is_system"
                                placeholder="Généré automatiquement"
                                class="rounded-xl border-slate-200 disabled:bg-slate-100" /><InputError
                                :message="roleForm.errors.slug"
                        /></label>
                    </div>
                    <label class="grid gap-1.5 text-sm font-bold text-slate-700"
                        >Description<textarea
                            v-model="roleForm.description"
                            rows="2"
                            class="rounded-xl border-slate-200" /><InputError
                            :message="roleForm.errors.description"
                    /></label>
                    <div>
                        <div class="mb-3 flex items-center justify-between">
                            <p class="text-sm font-black text-slate-800">
                                Permissions accordées
                            </p>
                            <p class="text-xs font-bold text-blue-600">
                                {{ roleForm.permission_ids.length }}
                                sélectionnée(s)
                            </p>
                        </div>
                        <div
                            class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4"
                        >
                            <section v-for="module in modules" :key="module">
                                <p
                                    class="mb-2 text-[10px] font-black uppercase tracking-wider text-slate-400"
                                >
                                    {{ module.replaceAll("_", " ") }}
                                </p>
                                <div
                                    class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                                >
                                    <label
                                        v-for="permission in permissions.filter(
                                            (item) => item.module === module,
                                        )"
                                        :key="permission.id"
                                        class="flex cursor-pointer items-center gap-3 rounded-xl border bg-white p-3 text-xs font-bold transition"
                                        :class="
                                            roleForm.permission_ids.includes(
                                                permission.id,
                                            )
                                                ? 'border-blue-300 text-blue-700 shadow-sm'
                                                : 'border-slate-200 text-slate-600'
                                        "
                                        ><input
                                            v-model="roleForm.permission_ids"
                                            type="checkbox"
                                            :value="permission.id"
                                            class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                        />{{ permission.name }}</label
                                    >
                                </div>
                            </section>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="
                                roleForm.errors.permission_ids ||
                                roleForm.errors.role
                            "
                        />
                    </div>
                    <label
                        v-if="!editingRole?.is_system"
                        class="flex items-center gap-2 text-sm font-bold text-slate-700"
                        ><input
                            v-model="roleForm.is_active"
                            type="checkbox"
                            class="rounded text-blue-600"
                        />Rôle actif</label
                    >
                </div>
                <footer
                    class="flex shrink-0 justify-end gap-2 border-t border-slate-200 bg-white px-6 py-4 sm:px-8 lg:px-10"
                >
                    <button
                        type="button"
                        class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600"
                        @click="closeRoleModal"
                    >
                        Annuler</button
                    ><button
                        :disabled="roleForm.processing"
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50"
                    >
                        {{
                            roleForm.processing
                                ? "Enregistrement..."
                                : "Enregistrer"
                        }}
                    </button>
                </footer>
            </form>
        </Modal>

        <Modal
            :show="permissionModalOpen"
            max-width="2xl"
            @close="closePermissionModal"
        >
            <form
                class="grid gap-5 p-6 sm:p-8"
                @submit.prevent="submitPermission"
            >
                <div>
                    <p
                        class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600"
                    >
                        Permission
                    </p>
                    <h2 class="mt-1 text-xl font-black text-slate-900">
                        {{
                            editingPermission
                                ? "Modifier la permission"
                                : "Nouvelle permission"
                        }}
                    </h2>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1.5 text-sm font-bold text-slate-700"
                        >Nom<input
                            v-model="permissionForm.name"
                            required
                            class="rounded-xl border-slate-200" /><InputError
                            :message="permissionForm.errors.name" /></label
                    ><label
                        class="grid gap-1.5 text-sm font-bold text-slate-700"
                        >Module<input
                            v-model="permissionForm.module"
                            required
                            list="permission-modules"
                            class="rounded-xl border-slate-200" /><datalist
                            id="permission-modules"
                        >
                            <option
                                v-for="module in modules"
                                :key="module"
                                :value="module"
                            /></datalist
                        ><InputError :message="permissionForm.errors.module"
                    /></label>
                </div>
                <label class="grid gap-1.5 text-sm font-bold text-slate-700"
                    >Slug<input
                        v-model="permissionForm.slug"
                        :required="Boolean(editingPermission)"
                        placeholder="Généré automatiquement"
                        class="rounded-xl border-slate-200" /><InputError
                        :message="permissionForm.errors.slug"
                /></label>
                <label class="grid gap-1.5 text-sm font-bold text-slate-700"
                    >Description<textarea
                        v-model="permissionForm.description"
                        rows="3"
                        class="rounded-xl border-slate-200" /><InputError
                        :message="permissionForm.errors.description"
                /></label>
                <label
                    class="flex items-center gap-2 text-sm font-bold text-slate-700"
                    ><input
                        v-model="permissionForm.is_active"
                        type="checkbox"
                        class="rounded text-blue-600"
                    />Permission active</label
                ><InputError :message="permissionForm.errors.permission" />
                <div
                    class="flex justify-end gap-2 border-t border-slate-100 pt-4"
                >
                    <button
                        type="button"
                        class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600"
                        @click="closePermissionModal"
                    >
                        Annuler</button
                    ><button
                        :disabled="permissionForm.processing"
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50"
                    >
                        {{
                            permissionForm.processing
                                ? "Enregistrement..."
                                : "Enregistrer"
                        }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal :show="assignmentModalOpen" max-width="2xl" @close="closeAssignment">
            <form class="grid gap-5 p-6 sm:p-8" @submit.prevent="submitAssignment">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-blue-600">
                        {{ organization.name }}
                    </p>
                    <h2 class="mt-1 text-xl font-black text-slate-900">
                        Affectation de {{ editingMember?.name }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Choisissez son rôle et les départements qu’il peut gérer.
                    </p>
                </div>
                <label class="grid gap-1.5 text-sm font-bold text-slate-700">
                    Rôle
                    <select v-model="assignmentForm.role_id" class="rounded-xl border-slate-200">
                        <option value="">Sans rôle</option>
                        <option v-for="role in roles" :key="role.id" :value="role.id">
                            {{ role.name }}
                        </option>
                    </select>
                    <InputError :message="assignmentForm.errors.role_id" />
                </label>
                <fieldset class="grid gap-3 rounded-2xl border border-slate-200 p-4">
                    <legend class="px-1 text-sm font-black text-slate-800">Départements</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="item in departments"
                            :key="item.id"
                            class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm font-bold"
                            :class="assignmentForm.department_ids.includes(item.id) ? 'border-blue-300 bg-blue-50 text-blue-800' : 'border-slate-200 text-slate-600'"
                        >
                            <input
                                v-model="assignmentForm.department_ids"
                                type="checkbox"
                                :value="item.id"
                                class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                            />
                            {{ item.name }}
                        </label>
                    </div>
                    <InputError :message="assignmentForm.errors.department_ids" />
                </fieldset>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600" @click="closeAssignment">
                        Annuler
                    </button>
                    <button :disabled="assignmentForm.processing" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50">
                        Enregistrer l’affectation
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
