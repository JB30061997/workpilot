<script setup>
import AppIcon from "@/Components/AppIcon.vue";
import { MessageCircleMore } from "lucide-vue-next";
import Dropdown from "@/Components/Dropdown.vue";
import DropdownLink from "@/Components/DropdownLink.vue";
import { useSweetAlert } from "@/Composables/useSweetAlert";
import { Link, useForm, usePage } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted, ref, watch } from "vue";

const page = usePage();
const { notify } = useSweetAlert();
const sidebarOpen = ref(false);
const sidebarCollapsed = ref(false);
const notificationsOpen = ref(false);
const workContext = computed(() => page.props.workContext ?? {});
const organizations = computed(() => workContext.value.organizations ?? []);
const departments = computed(() => workContext.value.departments ?? []);
const user = computed(() => page.props.auth.user);
const userInitials = computed(() =>
    user.value.name
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join("")
        .toUpperCase(),
);
const currentOrganization = computed(() =>
    organizations.value.find(
        (item) => Number(item.id) === Number(workContext.value.organization_id),
    ),
);
const currentDepartment = computed(() =>
    departments.value.find(
        (item) => Number(item.id) === Number(workContext.value.department_id),
    ),
);

const navigationGroups = [
    {
        label: "Vue d’ensemble",
        items: [
            {
                label: "Tableau de bord",
                shortLabel: "Accueil",
                route: "dashboard",
                pattern: "dashboard",
                icon: "dashboard",
            },
        ],
    },
    {
        label: "Pilotage",
        items: [
            {
                label: "Plans d’action",
                shortLabel: "Plans",
                route: "action-plans.index",
                pattern: "action-plans.*",
                icon: "plans",
            },
            {
                label: "Projets",
                shortLabel: "Projets",
                route: "projects.index",
                pattern: "projects.*",
                icon: "projects",
            },
            {
                label: "Processus",
                shortLabel: "Workflows",
                route: "workflows.library",
                pattern: "workflows.*",
                icon: "workflow",
            },
            {
                label: "Chat",
                shortLabel: "Chat",
                route: "project-chat.index",
                pattern: "project-chat.*",
                icon: "message-circle",
            },
        ],
    },
    {
        label: "Exécution",
        items: [
            {
                label: "Tâches",
                shortLabel: "Tâches",
                route: "tasks.index",
                pattern: "tasks.*",
                icon: "tasks",
            },
            {
                label: "Planning",
                shortLabel: "Planning",
                route: "planning.index",
                pattern: "planning.*",
                icon: "calendar",
            },
            {
                label: "Sessions de travail",
                shortLabel: "Sessions",
                route: "work-sessions.index",
                pattern: "work-sessions.*",
                icon: "sessions",
            },
            {
                label: "Suivi GLPI",
                shortLabel: "GLPI",
                route: "glpi.index",
                pattern: "glpi.*",
                icon: "ticket",
            },
        ],
    },
    {
        label: "Administration",
        items: [
            {
                label: "Utilisateurs",
                shortLabel: "Équipe",
                route: "users.index",
                pattern: "users.*",
                icon: "users",
            },
            {
                label: "Rôles & permissions",
                shortLabel: "Accès",
                route: "access-control.index",
                pattern: "access-control.*",
                icon: "shield",
            },
            {
                label: "Référentiel",
                shortLabel: "Réglages",
                route: "referential.index",
                pattern: "referential.*",
                icon: "referential",
            },
        ],
    },
];

const navigation = navigationGroups.flatMap((group) => group.items);
const currentItem = computed(() =>
    navigation.find((item) => route().current(item.pattern)),
);
const currentPage = computed(() => currentItem.value?.label ?? "WorkPilot");
const currentSection = computed(
    () =>
        navigationGroups.find((group) =>
            group.items.some((item) => item.route === currentItem.value?.route),
        )?.label ?? "Espace de travail",
);
const sharedNotifications = computed(() => page.props.notifications ?? []);
const notificationCount = computed(
    () =>
        sharedNotifications.value.filter(
            (notification) => !notification.read_at,
        ).length,
);
const organizationForm = useForm({
    organization_id: workContext.value.organization_id ?? "",
});
const departmentForm = useForm({
    organization_id: workContext.value.organization_id ?? "",
    department_id: workContext.value.department_id ?? "",
});

watch(
    () => workContext.value.organization_id,
    (id) => {
        organizationForm.organization_id = id ?? "";
        departmentForm.organization_id = id ?? "";
    },
);
watch(
    () => workContext.value.department_id,
    (id) => {
        departmentForm.department_id = id ?? "";
    },
);
watch(
    () => page.url,
    () => {
        sidebarOpen.value = false;
        notificationsOpen.value = false;
    },
);
watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) notify(message, "success");
    },
    { immediate: true },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) notify(message, "error");
    },
    { immediate: true },
);

const updateOrganization = (
    organizationId = organizationForm.organization_id,
) => {
    organizationForm.organization_id = organizationId;
    organizationForm.patch(route("context.update"), { preserveScroll: true });
};
const updateDepartment = (departmentId = departmentForm.department_id) => {
    departmentForm.department_id = departmentId;
    departmentForm.patch(route("context.update"), { preserveScroll: true });
};
const closeOverlays = () => {
    sidebarOpen.value = false;
    notificationsOpen.value = false;
};
const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    window.localStorage.setItem(
        "workpilot.sidebar-collapsed",
        String(sidebarCollapsed.value),
    );
};
const handleEscape = (event) => {
    if (event.key === "Escape") closeOverlays();
};

onMounted(() => {
    sidebarCollapsed.value =
        window.localStorage.getItem("workpilot.sidebar-collapsed") === "true";
    window.addEventListener("keydown", handleEscape);
});
onUnmounted(() => window.removeEventListener("keydown", handleEscape));
</script>

<template>
    <div class="wp-shell min-h-screen bg-[#f8fafc] text-slate-900">
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
        >
            <button
                v-if="sidebarOpen"
                class="fixed inset-0 z-40 bg-slate-950/30 backdrop-blur-sm lg:hidden"
                aria-label="Fermer le menu"
                @click="sidebarOpen = false"
            />
        </Transition>

        <aside
            class="wp-sidebar fixed inset-y-0 left-0 z-50 flex h-screen h-[100dvh] flex-col overflow-x-hidden border-r border-[#27272a] bg-[#09090b] text-white transition-all duration-300 ease-out"
            :class="[
                sidebarOpen
                    ? 'translate-x-0'
                    : '-translate-x-full lg:translate-x-0',
                sidebarCollapsed ? 'w-[5.25rem]' : 'w-[17.5rem]',
            ]"
        >
            <div
                class="flex h-[76px] shrink-0 items-center border-b border-[#27272a] px-4"
                :class="sidebarCollapsed ? 'justify-center' : 'justify-between'"
            >
                <Link
                    :href="route('dashboard')"
                    class="group flex min-w-0 items-center gap-3"
                    @click="sidebarOpen = false"
                >
                    <img
                        src="../images/logo/logo_workpilot.png"
                        alt="WorkPilot"
                        class="block shrink-0 object-contain transition duration-200 group-hover:scale-[1.03]"
                        :class="
                            sidebarCollapsed
                                ? 'h-14 w-14'
                                : 'h-[90px] w-[265px] max-w-full'
                        "
                    />
                </Link>
                <button
                    v-if="!sidebarCollapsed"
                    class="grid h-9 w-9 place-items-center rounded-xl text-zinc-400 transition hover:bg-zinc-800 hover:text-white lg:hidden"
                    aria-label="Fermer le menu"
                    @click="sidebarOpen = false"
                >
                    <AppIcon name="close" class="h-4 w-4" />
                </button>
            </div>

            <div
                class="wp-sidebar-scroll flex min-h-0 flex-1 flex-col overflow-y-auto px-3 py-4"
            >
                <nav class="grid gap-4" aria-label="Navigation principale">
                    <section
                        v-for="group in navigationGroups"
                        :key="group.label"
                    >
                        <h2
                            v-if="!sidebarCollapsed"
                            class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-500"
                        >
                            {{ group.label }}
                        </h2>
                        <div class="grid gap-1">
                            <Link
                                v-for="item in group.items"
                                :key="item.route"
                                :href="route(item.route)"
                                :title="
                                    sidebarCollapsed ? item.label : undefined
                                "
                                class="group relative flex h-11 items-center rounded-xl outline-none transition duration-200 focus-visible:ring-2 focus-visible:ring-blue-400/70"
                                :class="[
                                    sidebarCollapsed
                                        ? 'justify-center px-0'
                                        : 'gap-3 px-2.5',
                                    route().current(item.pattern)
                                        ? 'bg-[#27272a] text-white'
                                        : 'text-zinc-200 hover:bg-[#18181b] hover:text-white',
                                ]"
                                @click="sidebarOpen = false"
                            >
                                <span
                                    v-if="route().current(item.pattern)"
                                    class="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-blue-500"
                                ></span>
                                <span
                                    class="grid h-9 w-9 shrink-0 place-items-center rounded-xl transition duration-200"
                                    :class="
                                        route().current(item.pattern)
                                            ? 'bg-[#3f3f46] text-white'
                                            : 'text-zinc-300 group-hover:bg-[#27272a] group-hover:text-white'
                                    "
                                >
                                    <MessageCircleMore
                                        v-if="
                                            item.route === 'project-chat.index'
                                        "
                                        class="h-[18px] w-[18px]"
                                        :stroke-width="2"
                                    />
                                    <AppIcon
                                        v-else
                                        :name="item.icon"
                                        class="h-[18px] w-[18px]"
                                        :stroke-width="2"
                                    />
                                </span>
                                <span
                                    v-if="!sidebarCollapsed"
                                    class="min-w-0 flex-1 truncate text-[13px] font-semibold"
                                    :class="
                                        route().current(item.pattern) &&
                                        'font-bold'
                                    "
                                    >{{ item.label }}</span
                                >
                                <AppIcon
                                    v-if="
                                        !sidebarCollapsed &&
                                        route().current(item.pattern)
                                    "
                                    name="chevron-right"
                                    class="h-3.5 w-3.5 text-blue-300"
                                />
                            </Link>
                        </div>
                    </section>
                </nav>

                <div
                    v-if="!sidebarCollapsed && organizations.length"
                    class="mt-6 grid gap-3 rounded-2xl border border-zinc-800 bg-zinc-900 p-3 lg:hidden"
                >
                    <label
                        class="grid gap-1.5 text-[9px] font-black uppercase tracking-[0.14em] text-zinc-400"
                        >Société<select
                            v-model="organizationForm.organization_id"
                            class="wp-context-mobile wp-context-mobile-dark"
                            :disabled="organizationForm.processing"
                            @change="updateOrganization()"
                        >
                            <option
                                v-for="organization in organizations"
                                :key="organization.id"
                                :value="organization.id"
                            >
                                {{ organization.name }}
                            </option>
                        </select></label
                    >
                    <label
                        class="grid gap-1.5 text-[9px] font-black uppercase tracking-[0.14em] text-zinc-400"
                        >Département<select
                            v-model="departmentForm.department_id"
                            class="wp-context-mobile wp-context-mobile-dark"
                            :disabled="departmentForm.processing"
                            @change="updateDepartment()"
                        >
                            <option
                                v-for="department in departments"
                                :key="department.id"
                                :value="department.id"
                            >
                                {{ department.name }}
                            </option>
                        </select></label
                    >
                </div>
            </div>

            <div class="border-t border-[#27272a] bg-[#09090b] p-3">
                <button
                    class="hidden h-10 w-full items-center rounded-xl text-zinc-400 outline-none transition hover:bg-zinc-800 hover:text-white focus-visible:ring-2 focus-visible:ring-blue-400/70 lg:flex"
                    :class="sidebarCollapsed ? 'justify-center' : 'gap-3 px-3'"
                    :title="
                        sidebarCollapsed ? 'Agrandir la navigation' : undefined
                    "
                    :aria-label="
                        sidebarCollapsed
                            ? 'Agrandir la navigation'
                            : 'Réduire la navigation'
                    "
                    @click="toggleSidebar"
                >
                    <AppIcon
                        name="panel-left"
                        class="h-4 w-4 transition"
                        :class="sidebarCollapsed && 'rotate-180'"
                    />
                    <span v-if="!sidebarCollapsed" class="text-xs font-bold"
                        >Réduire la navigation</span
                    >
                </button>
            </div>
        </aside>

        <div
            class="relative min-h-screen transition-[padding] duration-300"
            :class="sidebarCollapsed ? 'lg:pl-[5.25rem]' : 'lg:pl-[17.5rem]'"
        >
            <header
                class="wp-topbar sticky top-0 z-30 border-b border-[#27272a] bg-[#09090b] text-zinc-100"
            >
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                    <button
                        class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-zinc-700 bg-zinc-900 text-zinc-300 transition hover:border-blue-400/50 hover:bg-zinc-800 hover:text-blue-300 lg:hidden"
                        aria-label="Ouvrir le menu"
                        @click="sidebarOpen = true"
                    >
                        <AppIcon name="menu" class="h-[18px] w-[18px]" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex items-center gap-1.5 text-[9px] font-semibold text-zinc-500"
                        >
                            <span>WorkPilot</span
                            ><AppIcon
                                name="chevron-right"
                                class="h-3 w-3"
                            /><span class="truncate text-blue-300">{{
                                currentSection
                            }}</span>
                        </div>
                        <h1
                            class="mt-0.5 truncate text-[15px] font-bold tracking-[-0.02em] text-white"
                        >
                            {{ currentPage }}
                        </h1>
                    </div>

                    <div
                        v-if="organizations.length"
                        class="hidden items-center gap-2 xl:flex"
                    >
                        <Dropdown
                            align="right"
                            width="56"
                            content-classes="overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 p-1.5 shadow-2xl shadow-slate-950/40"
                        >
                            <template #trigger
                                ><button
                                    type="button"
                                    class="wp-context-trigger"
                                    :disabled="organizationForm.processing"
                                >
                                    <span class="wp-context-trigger-icon"
                                        ><AppIcon
                                            name="building"
                                            class="h-4 w-4" /></span
                                    ><span class="max-w-28 truncate">{{
                                        currentOrganization?.name ?? "Société"
                                    }}</span
                                    ><AppIcon
                                        name="chevron-down"
                                        class="h-3.5 w-3.5 text-slate-500 transition group-hover:text-blue-300"
                                    /></button
                            ></template>
                            <template #content
                                ><div
                                    class="px-2 pb-1.5 pt-1 text-[9px] font-black uppercase tracking-[0.16em] text-slate-500"
                                >
                                    Société active
                                </div>
                                <button
                                    v-for="organization in organizations"
                                    :key="organization.id"
                                    type="button"
                                    class="group/item flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-xs font-bold transition hover:bg-slate-800"
                                    :class="
                                        Number(organization.id) ===
                                        Number(workContext.organization_id)
                                            ? 'bg-blue-500/15 text-blue-200'
                                            : 'text-slate-300'
                                    "
                                    @click="updateOrganization(organization.id)"
                                >
                                    <span
                                        class="grid h-7 w-7 shrink-0 place-items-center rounded-lg"
                                        :class="
                                            Number(organization.id) ===
                                            Number(workContext.organization_id)
                                                ? 'bg-blue-500/20 text-blue-300'
                                                : 'bg-slate-800 text-slate-400 group-hover/item:text-blue-300'
                                        "
                                        ><AppIcon
                                            name="building"
                                            class="h-3.5 w-3.5" /></span
                                    ><span class="min-w-0 flex-1 truncate">{{
                                        organization.name
                                    }}</span
                                    ><AppIcon
                                        v-if="
                                            Number(organization.id) ===
                                            Number(workContext.organization_id)
                                        "
                                        name="check"
                                        class="h-4 w-4 text-blue-400"
                                    /></button
                            ></template>
                        </Dropdown>
                        <Dropdown
                            align="right"
                            width="56"
                            content-classes="overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 p-1.5 shadow-2xl shadow-slate-950/40"
                        >
                            <template #trigger
                                ><button
                                    type="button"
                                    class="wp-context-trigger"
                                    :disabled="departmentForm.processing"
                                >
                                    <span class="wp-context-trigger-icon"
                                        ><AppIcon
                                            name="department"
                                            class="h-4 w-4" /></span
                                    ><span class="max-w-32 truncate">{{
                                        currentDepartment?.name ?? "Département"
                                    }}</span
                                    ><AppIcon
                                        name="chevron-down"
                                        class="h-3.5 w-3.5 text-slate-500 transition group-hover:text-blue-300"
                                    /></button
                            ></template>
                            <template #content
                                ><div
                                    class="px-2 pb-1.5 pt-1 text-[9px] font-black uppercase tracking-[0.16em] text-slate-500"
                                >
                                    Département actif
                                </div>
                                <button
                                    v-for="department in departments"
                                    :key="department.id"
                                    type="button"
                                    class="group/item flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-xs font-bold transition hover:bg-slate-800"
                                    :class="
                                        Number(department.id) ===
                                        Number(workContext.department_id)
                                            ? 'bg-blue-500/15 text-blue-200'
                                            : 'text-slate-300'
                                    "
                                    @click="updateDepartment(department.id)"
                                >
                                    <span
                                        class="grid h-7 w-7 shrink-0 place-items-center rounded-lg"
                                        :class="
                                            Number(department.id) ===
                                            Number(workContext.department_id)
                                                ? 'bg-blue-500/20 text-blue-300'
                                                : 'bg-slate-800 text-slate-400 group-hover/item:text-blue-300'
                                        "
                                        ><AppIcon
                                            name="department"
                                            class="h-3.5 w-3.5" /></span
                                    ><span class="min-w-0 flex-1 truncate">{{
                                        department.name
                                    }}</span
                                    ><AppIcon
                                        v-if="
                                            Number(department.id) ===
                                            Number(workContext.department_id)
                                        "
                                        name="check"
                                        class="h-4 w-4 text-blue-400"
                                    /></button
                            ></template>
                        </Dropdown>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <button
                                class="wp-icon-button relative"
                                :class="notificationsOpen && 'is-active'"
                                :aria-expanded="notificationsOpen"
                                aria-label="Notifications"
                                @click="notificationsOpen = !notificationsOpen"
                            >
                                <AppIcon
                                    name="bell"
                                    class="h-[18px] w-[18px]"
                                /><span
                                    v-if="notificationCount"
                                    class="absolute -right-1 -top-1 grid min-h-[18px] min-w-[18px] place-items-center rounded-full bg-blue-500 px-1 text-[8px] font-black text-white ring-2 ring-slate-900"
                                    >{{
                                        notificationCount > 9
                                            ? "9+"
                                            : notificationCount
                                    }}</span
                                >
                            </button>
                            <button
                                v-if="notificationsOpen"
                                class="fixed inset-0 z-40 cursor-default"
                                aria-label="Fermer les notifications"
                                @click="notificationsOpen = false"
                            />
                            <Transition
                                enter-active-class="transition duration-200 ease-out"
                                enter-from-class="translate-y-2 scale-95 opacity-0"
                                leave-active-class="transition duration-150 ease-in"
                                leave-to-class="translate-y-2 scale-95 opacity-0"
                            >
                                <section
                                    v-if="notificationsOpen"
                                    class="absolute right-0 z-50 mt-3 w-[calc(100vw-2rem)] max-w-[380px] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-950/15"
                                >
                                    <div
                                        class="flex items-center justify-between border-b border-slate-100 p-5"
                                    >
                                        <div>
                                            <p
                                                class="text-[10px] font-black uppercase tracking-[0.16em] text-blue-600"
                                            >
                                                Notifications
                                            </p>
                                            <h2
                                                class="mt-1 text-base font-black text-slate-950"
                                            >
                                                Centre d’activité
                                            </h2>
                                        </div>
                                        <span
                                            class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600"
                                            ><AppIcon
                                                name="bell"
                                                class="h-5 w-5"
                                        /></span>
                                    </div>
                                    <div
                                        v-if="sharedNotifications.length"
                                        class="max-h-80 divide-y divide-slate-100 overflow-y-auto"
                                    >
                                        <article
                                            v-for="notification in sharedNotifications"
                                            :key="notification.id"
                                            class="flex gap-3 p-4 transition hover:bg-blue-50/40"
                                        >
                                            <span
                                                class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600"
                                                ><AppIcon
                                                    :name="
                                                        notification.icon ??
                                                        'info'
                                                    "
                                                    class="h-4 w-4"
                                            /></span>
                                            <div class="min-w-0">
                                                <p
                                                    class="text-sm font-bold text-slate-900"
                                                >
                                                    {{ notification.title }}
                                                </p>
                                                <p
                                                    class="mt-1 text-xs leading-5 text-slate-500"
                                                >
                                                    {{ notification.message }}
                                                </p>
                                                <p
                                                    class="mt-2 text-[9px] font-bold uppercase tracking-wide text-slate-400"
                                                >
                                                    {{ notification.time }}
                                                </p>
                                            </div>
                                            <i
                                                v-if="!notification.read_at"
                                                class="mt-2 h-2 w-2 shrink-0 rounded-full bg-blue-500"
                                            />
                                        </article>
                                    </div>
                                    <div
                                        v-else
                                        class="grid place-items-center px-6 py-10 text-center"
                                    >
                                        <span
                                            class="grid h-12 w-12 place-items-center rounded-2xl bg-blue-50 text-blue-600"
                                            ><AppIcon
                                                name="check"
                                                class="h-6 w-6"
                                        /></span>
                                        <h3
                                            class="mt-3 text-sm font-black text-slate-900"
                                        >
                                            Vous êtes à jour
                                        </h3>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Aucune nouvelle notification.
                                        </p>
                                    </div>
                                </section>
                            </Transition>
                        </div>

                        <Dropdown
                            align="right"
                            width="72"
                            content-classes="overflow-hidden rounded-2xl border border-slate-200 bg-white py-1 shadow-xl"
                            ><template #trigger
                                ><button
                                    type="button"
                                    class="group flex items-center gap-2 rounded-lg border border-transparent p-1 transition hover:border-zinc-700 hover:bg-zinc-900"
                                >
                                    <span class="wp-avatar">{{
                                        userInitials
                                    }}</span
                                    ><span
                                        class="hidden max-w-32 text-left md:block"
                                        ><span
                                            class="block truncate text-xs font-extrabold text-slate-100"
                                            >{{ user.name }}</span
                                        ><span
                                            class="mt-0.5 block truncate text-[10px] text-slate-400"
                                            >{{
                                                currentDepartment?.name ??
                                                currentOrganization?.name ??
                                                user.email
                                            }}</span
                                        ></span
                                    ><AppIcon
                                        name="chevron-down"
                                        class="hidden h-3.5 w-3.5 text-slate-500 transition group-hover:text-blue-300 md:block"
                                    /></button></template
                            ><template #content
                                ><div
                                    class="border-b border-slate-100 px-4 py-3"
                                >
                                    <p
                                        class="truncate text-sm font-bold text-slate-900"
                                    >
                                        {{ user.name }}
                                    </p>
                                    <p class="truncate text-xs text-slate-500">
                                        {{ user.email }}
                                    </p>
                                </div>
                                <DropdownLink :href="route('profile.edit')"
                                    >Mon profil</DropdownLink
                                ><DropdownLink
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                    >Se déconnecter</DropdownLink
                                ></template
                            ></Dropdown
                        >
                    </div>
                </div>
            </header>

            <header v-if="$slots.header" class="px-4 py-5 sm:px-6 lg:px-8">
                <div
                    class="wp-page-header mx-auto max-w-[1680px] rounded-3xl px-5 py-5 sm:px-7"
                >
                    <slot name="header" />
                </div>
            </header>
            <main class="relative pb-20 lg:pb-0"><slot /></main>
            <nav
                class="fixed inset-x-3 bottom-3 z-30 grid grid-cols-5 rounded-2xl border border-slate-200 bg-white/95 p-1.5 shadow-2xl shadow-slate-950/15 backdrop-blur-xl lg:hidden"
                aria-label="Navigation mobile"
            >
                <Link
                    v-for="item in navigation.slice(0, 5)"
                    :key="item.route"
                    :href="route(item.route)"
                    class="flex min-w-0 flex-col items-center gap-1 rounded-xl px-1 py-2 text-[9px] font-bold transition"
                    :class="
                        route().current(item.pattern)
                            ? 'bg-blue-50 text-blue-700'
                            : 'text-zinc-400'
                    "
                    @click="sidebarOpen = false"
                    ><MessageCircleMore
                        v-if="item.route === 'project-chat.index'"
                        class="h-[18px] w-[18px]"
                        :stroke-width="2"
                    /><AppIcon
                        v-else
                        :name="item.icon"
                        class="h-[18px] w-[18px]"
                    /><span class="truncate">{{ item.shortLabel }}</span></Link
                >
            </nav>
        </div>
    </div>
</template>
