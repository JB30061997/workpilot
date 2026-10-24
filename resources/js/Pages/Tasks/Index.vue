<script setup>
import InputError from "@/Components/InputError.vue";
import Modal from "@/Components/Modal.vue";
import { useSweetAlert } from "@/Composables/useSweetAlert";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

const props = defineProps({
    organization: Object,
    department: Object,
    tasks: Object,
    filters: Object,
    statuses: Array,
    projects: Array,
    actions: Array,
    members: Array,
    priorities: Array,
});

const { confirmAction } = useSweetAlert();

const isFormOpen = ref(false);
const editingTask = ref(null);
const showAdvancedOptions = ref(false);
const activeStatusTab = ref("all");
const pendingTaskId = ref(null);
const viewMode = ref(
    typeof window !== "undefined"
        ? (window.localStorage.getItem("tasks-view-mode") ?? "list")
        : "list",
);

const filterForm = useForm({
    search: props.filters?.search ?? "",
    status_id: props.filters?.status_id ?? "",
    project_id: props.filters?.project_id ?? "",
    priority: props.filters?.priority ?? "",
    overdue: props.filters?.overdue ? 1 : "",
});

const taskForm = useForm({
    title: "",
    description: "",
    project_id: "",
    action_id: "",
    status_id: "",
    assignee_ids: [],
    priority: "medium",
    start_at: "",
    due_at: "",
    estimated_minutes: "",
    actual_minutes: 0,
    progress: 0,
    is_private: false,
});

const priorityLabels = {
    low: "Faible",
    medium: "Moyenne",
    high: "Haute",
    critical: "Critique",
};

const priorityClasses = {
    low: "bg-slate-100 text-slate-700",
    medium: "bg-blue-50 text-blue-700",
    high: "bg-amber-50 text-amber-700",
    critical: "bg-rose-50 text-rose-700",
};

const tasksByStatus = computed(() => {
    return props.statuses.map((status) => ({
        ...status,
        tasks: props.tasks.data.filter((task) => task.status_id === status.id),
    }));
});

const visibleTasksByStatus = computed(() => {
    if (activeStatusTab.value === "all") {
        return tasksByStatus.value;
    }

    return tasksByStatus.value.filter(
        (status) => Number(status.id) === Number(activeStatusTab.value),
    );
});

const visibleTasks = computed(() => {
    if (activeStatusTab.value === "all") {
        return props.tasks.data;
    }

    return props.tasks.data.filter(
        (task) => Number(task.status_id) === Number(activeStatusTab.value),
    );
});

const taskStatus = (task) =>
    props.statuses.find(
        (status) => Number(status.id) === Number(task.status_id),
    );

const setViewMode = (mode) => {
    viewMode.value = mode;

    if (typeof window !== "undefined") {
        window.localStorage.setItem("tasks-view-mode", mode);
    }
};

const availableActions = computed(() => {
    if (!taskForm.project_id) {
        return [];
    }

    return props.actions.filter(
        (action) => Number(action.project_id) === Number(taskForm.project_id),
    );
});

const selectedProject = computed(
    () =>
        props.projects.find(
            (project) => Number(project.id) === Number(taskForm.project_id),
        ) ?? null,
);

const selectedAction = computed(
    () =>
        availableActions.value.find(
            (action) => Number(action.id) === Number(taskForm.action_id),
        ) ?? null,
);

watch(
    () => taskForm.project_id,
    () => {
        if (
            !availableActions.value.some(
                (action) => Number(action.id) === Number(taskForm.action_id),
            )
        ) {
            taskForm.action_id = "";
        }
    },
);

const defaultStatus = computed(() => {
    return (
        props.statuses.find((status) => status.is_default) ?? props.statuses[0]
    );
});

const formatDate = (value) => {
    if (!value) {
        return "Sans échéance";
    }

    return new Intl.DateTimeFormat("fr-FR", {
        day: "2-digit",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
    }).format(new Date(value));
};

const toDateTimeInput = (value) => {
    if (!value) {
        return "";
    }

    const date = new Date(value);
    const offset = date.getTimezoneOffset();

    return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 16);
};

const initials = (name) =>
    name
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join("");

const resetTaskForm = () => {
    taskForm.reset();
    taskForm.clearErrors();
    taskForm.status_id = defaultStatus.value?.id ?? "";
    taskForm.priority = "medium";
    taskForm.actual_minutes = 0;
    taskForm.progress = 0;
    taskForm.is_private = false;
    taskForm.assignee_ids = [];
};

const openCreateForm = (statusId = null) => {
    editingTask.value = null;
    showAdvancedOptions.value = false;
    resetTaskForm();
    taskForm.status_id = statusId ?? defaultStatus.value?.id ?? "";
    isFormOpen.value = true;
};

const openEditForm = (task) => {
    editingTask.value = task;
    showAdvancedOptions.value = true;
    taskForm.clearErrors();
    taskForm.title = task.title ?? "";
    taskForm.description = task.description ?? "";
    taskForm.project_id = task.project_id ?? "";
    taskForm.action_id = task.action_id ?? "";
    taskForm.status_id = task.status_id;
    taskForm.assignee_ids = task.assignees?.map((member) => member.id) ?? [];
    taskForm.priority = task.priority;
    taskForm.start_at = toDateTimeInput(task.start_at);
    taskForm.due_at = toDateTimeInput(task.due_at);
    taskForm.estimated_minutes = task.estimated_minutes ?? "";
    taskForm.actual_minutes = task.actual_minutes ?? 0;
    taskForm.progress = task.progress ?? 0;
    taskForm.is_private = Boolean(task.is_private);
    isFormOpen.value = true;
};

const closeForm = () => {
    if (taskForm.processing) {
        return;
    }

    isFormOpen.value = false;
    editingTask.value = null;
    resetTaskForm();
};

const submitTask = () => {
    const options = {
        preserveScroll: true,
        onSuccess: closeForm,
    };

    if (editingTask.value) {
        taskForm.patch(route("tasks.update", editingTask.value.id), options);
        return;
    }

    taskForm.post(route("tasks.store"), options);
};

const applyFilters = () => {
    router.get(route("tasks.index"), filterForm.data(), {
        preserveState: true,
        replace: true,
    });
};

const resetFilters = () => {
    filterForm.reset();
    router.get(route("tasks.index"), {}, { replace: true });
};

const updateTaskStatus = (task, statusId) => {
    pendingTaskId.value = task.id;

    router.patch(
        route("tasks.status.update", task.id),
        { status_id: statusId },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                pendingTaskId.value = null;
            },
        },
    );
};

const deleteTask = async (task) => {
    if (
        !(await confirmAction({
            title: `Supprimer « ${task.title} » ?`,
            text: "La tâche sera supprimée définitivement.",
            confirmButtonText: "Supprimer la tâche",
        }))
    ) {
        return;
    }

    router.delete(route("tasks.destroy", task.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Tâches - WorkPilot" />

    <AuthenticatedLayout>
        <div class="min-w-0 max-w-full bg-[#f1f3f6]">
            <div
                class="mx-auto grid max-w-[1680px] gap-4 px-4 py-5 sm:px-6 lg:px-8 lg:py-6"
            >
                <section
                    class="overflow-hidden rounded-md border border-[#d5dae3] bg-[#f8f9fb] px-5 py-5 sm:px-7"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-4"
                    >
                        <div>
                            <p
                                class="text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700"
                            >
                                {{ organization?.name }} /
                                {{ department?.name }}
                            </p>
                            <h1
                                class="mt-2 text-2xl font-bold tracking-tight text-slate-950"
                            >
                                Gestion des tâches
                            </h1>
                            <p class="mt-1 text-sm text-slate-500">
                                Suivi des tâches, des responsables et des
                                échéances de votre équipe.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-md bg-blue-600 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-blue-700"
                            @click="openCreateForm()"
                        >
                            + Nouvelle tâche
                        </button>
                    </div>
                </section>

                <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article
                        v-for="item in [
                            {
                                label: 'Total des tâches',
                                value: tasks.total ?? tasks.data.length,
                                tone: 'text-blue-700',
                            },
                            {
                                label: 'En cours',
                                value: tasksByStatus
                                    .filter((s) => /en cours/i.test(s.name))
                                    .reduce((n, s) => n + s.tasks.length, 0),
                                tone: 'text-amber-600',
                            },
                            {
                                label: 'Terminées',
                                value: tasksByStatus
                                    .filter((s) =>
                                        /finalis|termin|achev/i.test(s.name),
                                    )
                                    .reduce((n, s) => n + s.tasks.length, 0),
                                tone: 'text-emerald-700',
                            },
                            {
                                label: 'À valider',
                                value: tasksByStatus
                                    .filter((s) => /valid/i.test(s.name))
                                    .reduce((n, s) => n + s.tasks.length, 0),
                                tone: 'text-violet-700',
                            },
                        ]"
                        :key="item.label"
                        class="rounded-md border border-[#d5dae3] bg-[#f8f9fb] p-4"
                    >
                        <p
                            class="text-[11px] font-bold uppercase tracking-wide text-slate-500"
                        >
                            {{ item.label }}
                        </p>
                        <p
                            class="mt-2 text-3xl font-bold tabular-nums"
                            :class="item.tone"
                        >
                            {{ item.value }}
                        </p>
                    </article>
                </section>

                <section
                    class="overflow-hidden rounded-md border border-[#d5dae3] bg-[#f8f9fb]"
                >
                    <header class="border-b border-[#d5dae3] px-5 py-4 sm:px-6">
                        <p
                            class="text-[10px] font-bold uppercase tracking-[0.14em] text-blue-700"
                        >
                            Recherche
                        </p>
                        <h2 class="mt-1 text-lg font-semibold text-slate-900">
                            Filtres des tâches
                        </h2>
                    </header>
                    <form
                        class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-6 sm:p-5"
                        @submit.prevent="applyFilters"
                    >
                        <input
                            v-model="filterForm.search"
                            type="search"
                            placeholder="Rechercher une tâche..."
                            class="min-w-0 rounded-md border-[#d5dae3] bg-white text-xs focus:border-blue-500 focus:ring-blue-500 xl:col-span-2"
                        />
                        <select
                            v-model="filterForm.status_id"
                            class="min-w-0 rounded-md border-[#d5dae3] bg-white text-xs"
                        >
                            <option value="">Tous les statuts</option>
                            <option
                                v-for="status in statuses"
                                :key="status.id"
                                :value="status.id"
                            >
                                {{ status.name }}
                            </option>
                        </select>
                        <select
                            v-model="filterForm.project_id"
                            class="min-w-0 rounded-md border-[#d5dae3] bg-white text-xs"
                        >
                            <option value="">Tous les projets</option>
                            <option
                                v-for="project in projects"
                                :key="project.id"
                                :value="project.id"
                            >
                                {{ project.name }}
                            </option>
                        </select>
                        <select
                            v-model="filterForm.priority"
                            class="min-w-0 rounded-md border-[#d5dae3] bg-white text-xs"
                        >
                            <option value="">Toutes les priorités</option>
                            <option
                                v-for="priority in priorities"
                                :key="priority"
                                :value="priority"
                            >
                                {{ priorityLabels[priority] }}
                            </option>
                        </select>
                        <div class="flex gap-2">
                            <button
                                type="submit"
                                :disabled="filterForm.processing"
                                class="flex-1 rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                            >
                                Filtrer</button
                            ><button
                                type="button"
                                class="rounded-md border border-[#d5dae3] px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100"
                                @click="resetFilters"
                            >
                                Effacer
                            </button>
                        </div>
                    </form>
                </section>

                <section
                    class="overflow-hidden rounded-md border border-[#d5dae3] bg-[#f8f9fb]"
                >
                    <header
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-[#d5dae3] px-5 py-4 sm:px-6"
                    >
                        <div>
                            <!-- <p
                                class="text-[10px] font-bold uppercase tracking-[0.14em] text-blue-700"
                            >
                                Exécution
                            </p> -->
                            <h2
                                class="mt-1 text-lg font-semibold text-slate-900"
                            >
                                Suivi des tâches
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ visibleTasks.length }} tâches affichées sur
                                cette page
                            </p>
                        </div>
                        <div
                            class="flex rounded-md border border-[#d5dae3] bg-[#e9edf3] p-1"
                        >
                            <button
                                type="button"
                                class="rounded px-4 py-2 text-xs font-semibold"
                                :class="
                                    viewMode === 'list'
                                        ? 'bg-white text-blue-700 shadow-sm'
                                        : 'text-slate-600'
                                "
                                @click="setViewMode('list')"
                            >
                                Liste
                            </button>
                            <button
                                type="button"
                                class="rounded px-4 py-2 text-xs font-semibold"
                                :class="
                                    viewMode === 'board'
                                        ? 'bg-white text-blue-700 shadow-sm'
                                        : 'text-slate-600'
                                "
                                @click="setViewMode('board')"
                            >
                                Kanban
                            </button>
                        </div>
                    </header>
                    <nav
                        v-if="statuses.length"
                        class="flex gap-1 overflow-x-auto border-b border-[#d5dae3] px-4 pt-2 sm:px-6"
                        aria-label="Filtrer par statut"
                    >
                        <button
                            type="button"
                            class="shrink-0 border-b-2 px-3 py-3 text-xs font-semibold"
                            :class="
                                activeStatusTab === 'all'
                                    ? 'border-blue-600 text-blue-700'
                                    : 'border-transparent text-slate-500 hover:text-slate-900'
                            "
                            @click="activeStatusTab = 'all'"
                        >
                            Tous
                            <span
                                class="ml-1 rounded bg-[#e9edf3] px-1.5 py-0.5"
                                >{{ tasks.data.length }}</span
                            >
                        </button>
                        <button
                            v-for="status in tasksByStatus"
                            :key="status.id"
                            type="button"
                            class="shrink-0 border-b-2 px-3 py-3 text-xs font-semibold"
                            :class="
                                Number(activeStatusTab) === Number(status.id)
                                    ? 'border-blue-600 text-blue-700'
                                    : 'border-transparent text-slate-500 hover:text-slate-900'
                            "
                            @click="activeStatusTab = status.id"
                        >
                            <span
                                class="mr-1 inline-block h-2 w-2 rounded-full"
                                :style="{
                                    backgroundColor: status.color || '#64748b',
                                }"
                            />{{ status.name }}
                            <span
                                class="ml-1 rounded bg-[#e9edf3] px-1.5 py-0.5"
                                >{{ status.tasks.length }}</span
                            >
                        </button>
                    </nav>
                    <div
                        v-if="!statuses.length"
                        class="p-8 text-center text-sm text-slate-500"
                    >
                        Aucun statut actif n’est configuré.
                    </div>
                    <div
                        v-else-if="viewMode === 'list'"
                        class="overflow-x-auto"
                    >
                        <table
                            class="w-full min-w-[1050px] border-collapse text-left"
                        >
                            <thead
                                class="bg-[#e9edf3] text-[11px] font-semibold uppercase tracking-wide text-slate-600"
                            >
                                <tr>
                                    <th class="px-5 py-3">Tâche</th>
                                    <th class="px-4 py-3">Projet</th>
                                    <th class="px-4 py-3">Responsables</th>
                                    <th class="px-4 py-3">Priorité</th>
                                    <th class="px-4 py-3">Statut</th>
                                    <th class="w-[16%] px-4 py-3">
                                        Progression
                                    </th>
                                    <th class="px-4 py-3">Échéance</th>
                                    <th class="px-5 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#dfe4ec]">
                                <tr
                                    v-for="task in visibleTasks"
                                    :key="task.id"
                                    class="transition hover:bg-[#eef2f8]"
                                    :class="
                                        pendingTaskId === task.id
                                            ? 'opacity-60'
                                            : ''
                                    "
                                >
                                    <td class="max-w-[230px] px-5 py-4">
                                        <button
                                            type="button"
                                            class="block max-w-[220px] truncate text-left text-sm font-semibold text-slate-900 hover:text-blue-700"
                                            :title="task.title"
                                            @click="openEditForm(task)"
                                        >
                                            {{ task.title }}
                                        </button>
                                        <p
                                            v-if="task.description"
                                            class="mt-1 max-w-[220px] truncate text-xs text-slate-500"
                                        >
                                            {{ task.description }}
                                        </p>
                                    </td>
                                    <td
                                        class="max-w-[190px] px-4 py-4 text-xs text-slate-600"
                                    >
                                        <span
                                            class="block truncate"
                                            :title="task.project?.name"
                                            >{{
                                                task.project?.name ||
                                                "Sans projet"
                                            }}</span
                                        >
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex -space-x-2">
                                            <span
                                                v-for="assignee in (
                                                    task.assignees ?? []
                                                ).slice(0, 3)"
                                                :key="assignee.id"
                                                :title="assignee.name"
                                                class="grid h-8 w-8 place-items-center rounded-full border-2 border-white bg-slate-800 text-[10px] font-bold text-white"
                                                >{{
                                                    initials(assignee.name)
                                                }}</span
                                            ><span
                                                v-if="
                                                    !(task.assignees ?? [])
                                                        .length
                                                "
                                                class="text-xs text-slate-400"
                                                >Non assignée</span
                                            >
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span
                                            class="inline-flex rounded-md px-2.5 py-1 text-[11px] font-semibold"
                                            :class="
                                                priorityClasses[task.priority]
                                            "
                                            >{{
                                                priorityLabels[task.priority] ??
                                                task.priority
                                            }}</span
                                        >
                                    </td>
                                    <td class="px-4 py-4">
                                        <select
                                            :value="task.status_id"
                                            :disabled="
                                                pendingTaskId === task.id
                                            "
                                            class="max-w-[150px] rounded-md border-[#d5dae3] bg-white py-1.5 text-xs font-medium"
                                            @change="
                                                updateTaskStatus(
                                                    task,
                                                    Number($event.target.value),
                                                )
                                            "
                                        >
                                            <option
                                                v-for="status in statuses"
                                                :key="status.id"
                                                :value="status.id"
                                            >
                                                {{ status.name }}
                                            </option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-[#dce4f0]"
                                            >
                                                <div
                                                    class="h-full rounded-full bg-blue-600"
                                                    :style="{
                                                        width: `${Math.min(100, Math.max(0, Number(task.progress ?? 0)))}%`,
                                                    }"
                                                />
                                            </div>
                                            <strong
                                                class="w-10 text-right text-xs tabular-nums text-slate-900"
                                                >{{
                                                    task.progress ?? 0
                                                }}%</strong
                                            >
                                        </div>
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-4 py-4 text-xs text-slate-600"
                                    >
                                        {{ formatDate(task.due_at) }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex justify-end gap-2">
                                            <button
                                                type="button"
                                                class="rounded-md border border-[#b9c9e1] bg-[#edf3ff] px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-[#dbe8ff]"
                                                @click="openEditForm(task)"
                                            >
                                                Modifier</button
                                            ><button
                                                type="button"
                                                class="rounded-md border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50"
                                                @click="deleteTask(task)"
                                            >
                                                Suppr.
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!visibleTasks.length">
                                    <td
                                        colspan="8"
                                        class="px-5 py-12 text-center text-sm text-slate-500"
                                    >
                                        Aucune tâche dans cette vue.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="flex gap-3 overflow-x-auto p-4">
                        <section
                            v-for="column in visibleTasksByStatus"
                            :key="column.id"
                            class="w-[300px] shrink-0 rounded-md border border-[#d5dae3] bg-[#eef2f8] p-3"
                        >
                            <header
                                class="mb-3 flex items-center justify-between"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-2.5 w-2.5 rounded-full"
                                        :style="{
                                            backgroundColor:
                                                column.color || '#64748b',
                                        }"
                                    />
                                    <h3
                                        class="text-xs font-semibold text-slate-800"
                                    >
                                        {{ column.name }}
                                    </h3>
                                    <span
                                        class="rounded bg-white px-2 py-0.5 text-xs text-slate-600"
                                        >{{ column.tasks.length }}</span
                                    >
                                </div>
                                <button
                                    type="button"
                                    class="rounded px-2 text-blue-700 hover:bg-white"
                                    @click="openCreateForm(column.id)"
                                >
                                    +
                                </button>
                            </header>
                            <div class="grid gap-2">
                                <article
                                    v-for="task in column.tasks"
                                    :key="task.id"
                                    class="rounded-md border border-[#d5dae3] bg-white p-3"
                                >
                                    <div
                                        class="flex items-start justify-between gap-2"
                                    >
                                        <button
                                            type="button"
                                            class="text-left text-sm font-semibold text-slate-900 hover:text-blue-700"
                                            @click="openEditForm(task)"
                                        >
                                            {{ task.title }}</button
                                        ><span
                                            class="rounded px-2 py-1 text-[10px] font-semibold"
                                            :class="
                                                priorityClasses[task.priority]
                                            "
                                            >{{
                                                priorityLabels[task.priority]
                                            }}</span
                                        >
                                    </div>
                                    <p
                                        v-if="task.description"
                                        class="mt-2 line-clamp-2 text-xs text-slate-500"
                                    >
                                        {{ task.description }}
                                    </p>
                                    <p
                                        class="mt-3 truncate text-xs text-slate-500"
                                    >
                                        {{
                                            task.project?.name || "Sans projet"
                                        }}
                                    </p>
                                    <div class="mt-2 flex items-center gap-2">
                                        <div
                                            class="h-1.5 flex-1 overflow-hidden rounded-full bg-[#dce4f0]"
                                        >
                                            <div
                                                class="h-full rounded-full bg-blue-600"
                                                :style="{
                                                    width: `${Math.min(100, Math.max(0, Number(task.progress ?? 0)))}%`,
                                                }"
                                            />
                                        </div>
                                        <span class="text-xs font-semibold"
                                            >{{ task.progress ?? 0 }}%</span
                                        >
                                    </div>
                                    <div
                                        class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3"
                                    >
                                        <span
                                            class="text-[11px] text-slate-500"
                                            >{{ formatDate(task.due_at) }}</span
                                        ><button
                                            type="button"
                                            class="text-xs font-semibold text-blue-700"
                                            @click="openEditForm(task)"
                                        >
                                            Modifier
                                        </button>
                                    </div>
                                    <div class="mt-2 flex gap-2">
                                        <select
                                            :value="task.status_id"
                                            :disabled="
                                                pendingTaskId === task.id
                                            "
                                            class="min-w-0 flex-1 rounded-md border-[#d5dae3] py-1 text-xs"
                                            @change="
                                                updateTaskStatus(
                                                    task,
                                                    Number($event.target.value),
                                                )
                                            "
                                        >
                                            <option
                                                v-for="status in statuses"
                                                :key="status.id"
                                                :value="status.id"
                                            >
                                                {{ status.name }}
                                            </option></select
                                        ><button
                                            type="button"
                                            class="rounded-md border border-rose-200 px-2 text-xs text-rose-600"
                                            @click="deleteTask(task)"
                                        >
                                            Suppr.
                                        </button>
                                    </div>
                                </article>
                                <button
                                    v-if="!column.tasks.length"
                                    type="button"
                                    class="rounded-md border border-dashed border-slate-300 p-5 text-xs text-slate-500 hover:bg-white"
                                    @click="openCreateForm(column.id)"
                                >
                                    + Ajouter une tâche
                                </button>
                            </div>
                        </section>
                    </div>
                </section>
                <div
                    v-if="tasks.links?.length > 3"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-[#d5dae3] bg-[#f8f9fb] px-5 py-4"
                >
                    <p class="text-xs text-slate-500">
                        {{ tasks.from }}–{{ tasks.to }} sur {{ tasks.total }}
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <Link
                            v-for="link in tasks.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            preserve-scroll
                            class="rounded-md border px-3 py-1.5 text-xs"
                            :class="[
                                link.active
                                    ? 'border-blue-600 bg-blue-600 text-white'
                                    : 'border-[#d5dae3] text-slate-600',
                                !link.url
                                    ? 'pointer-events-none opacity-40'
                                    : 'hover:bg-slate-50',
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="isFormOpen" max-width="6xl" @close="closeForm">
            <form
                class="flex max-h-[calc(100dvh-2rem)] flex-col overflow-hidden bg-white"
                @submit.prevent="submitTask"
            >
                <div
                    class="relative flex shrink-0 items-center justify-between gap-4 overflow-hidden border-b border-slate-200 bg-white px-5 py-4 sm:px-6"
                >
                    <div class="relative flex min-w-0 items-center gap-3">
                        <span
                            class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-blue-100"
                        >
                            <svg
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"
                                />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <h2
                                class="truncate text-xl font-extrabold tracking-tight text-slate-950 sm:text-2xl"
                            >
                                {{
                                    editingTask
                                        ? "Modifier la tâche"
                                        : "Nouvelle tâche"
                                }}
                            </h2>
                            <p class="mt-0.5 truncate text-sm text-slate-500">
                                Définissez le travail, les responsables et
                                l’échéance.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
                        aria-label="Fermer"
                        @click="closeForm"
                    >
                        <span class="text-xl leading-none">×</span>
                    </button>
                </div>

                <div
                    class="grid min-h-0 flex-1 gap-x-4 gap-y-3 overflow-y-auto bg-slate-50/60 p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-4"
                >
                    <label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700 sm:col-span-2 lg:col-span-4"
                    >
                        <span class="flex items-center gap-2"
                            ><span
                                class="h-2 w-2 rounded-full bg-blue-600"
                            />Titre de la tâche
                            <span class="text-rose-500">*</span></span
                        >
                        <input
                            v-model="taskForm.title"
                            required
                            placeholder="Ex. Préparer le rapport mensuel"
                            class="h-11 w-full min-w-0 rounded-xl border-slate-200 bg-white px-4 font-semibold text-slate-900 shadow-sm placeholder:font-normal placeholder:text-slate-400 focus:border-blue-500 focus:ring-blue-500"
                        />
                        <InputError :message="taskForm.errors.title" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700 sm:col-span-2"
                    >
                        <span class="flex items-center gap-2"
                            ><span
                                class="grid h-6 w-6 place-items-center rounded-lg bg-blue-100 text-[10px] font-black text-blue-700"
                                >1</span
                            >
                            Projet</span
                        >
                        <select
                            v-model="taskForm.project_id"
                            class="h-11 w-full min-w-0 max-w-full truncate rounded-xl border-slate-200 bg-white px-3 font-semibold text-slate-800 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="">Sans projet</option>
                            <option
                                v-for="project in projects"
                                :key="project.id"
                                :value="project.id"
                            >
                                {{ project.code ? `${project.code} · ` : ""
                                }}{{ project.name }}
                            </option>
                        </select>
                        <InputError :message="taskForm.errors.project_id" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700 sm:col-span-2"
                    >
                        <span class="flex items-center gap-2"
                            ><span
                                class="grid h-6 w-6 place-items-center rounded-lg bg-violet-100 text-[10px] font-black text-violet-700"
                                >2</span
                            >
                            Action</span
                        >
                        <select
                            v-model="taskForm.action_id"
                            class="h-11 w-full min-w-0 max-w-full truncate rounded-xl border-slate-200 bg-white px-3 font-semibold text-slate-800 shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                            :disabled="
                                !taskForm.project_id || !availableActions.length
                            "
                        >
                            <option value="">
                                {{
                                    taskForm.project_id &&
                                    !availableActions.length
                                        ? "Aucune action pour ce projet"
                                        : "Sans action"
                                }}
                            </option>
                            <option
                                v-for="action in availableActions"
                                :key="action.id"
                                :value="action.id"
                            >
                                {{ action.title }}
                            </option>
                        </select>
                        <InputError :message="taskForm.errors.action_id" />
                    </label>
                    <div
                        v-if="selectedProject"
                        class="flex flex-wrap items-center gap-1.5 text-xs font-medium text-slate-500 sm:col-span-2 lg:col-span-4"
                    >
                        <span class="font-bold text-slate-700"
                            >Rattachement :</span
                        >
                        <span
                            class="rounded-md bg-blue-50 px-2 py-1 text-blue-700"
                            >Plan</span
                        >
                        <span>{{
                            selectedProject?.action_plan?.name ?? "Non rattaché"
                        }}</span>
                        <span class="text-slate-300">→</span>
                        <span
                            class="rounded-md bg-violet-50 px-2 py-1 text-violet-700"
                            >Axe</span
                        >
                        <span>{{
                            selectedProject?.axis?.name ?? "Non rattaché"
                        }}</span>
                        <span class="text-slate-300">→</span>
                        <span
                            class="rounded-md bg-slate-200 px-2 py-1 text-slate-700"
                            >Projet</span
                        >
                        <span>{{
                            selectedProject?.name ?? "Sans projet"
                        }}</span>
                        <template v-if="selectedAction">
                            <span class="text-slate-300">→</span>
                            <span
                                class="rounded-md bg-emerald-50 px-2 py-1 text-emerald-700"
                                >Action</span
                            >
                            <span>{{ selectedAction.title }}</span>
                        </template>
                    </div>
                    <label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                    >
                        Statut
                        <select
                            v-model="taskForm.status_id"
                            required
                            class="h-11 w-full min-w-0 rounded-xl border-slate-200 bg-slate-50/70 font-semibold focus:border-blue-500 focus:bg-white focus:ring-blue-500"
                        >
                            <option
                                v-for="status in statuses"
                                :key="status.id"
                                :value="status.id"
                            >
                                {{ status.name }}
                            </option>
                        </select>
                        <InputError :message="taskForm.errors.status_id" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                    >
                        Priorité
                        <select
                            v-model="taskForm.priority"
                            class="h-11 w-full min-w-0 rounded-xl border-slate-200 bg-slate-50/70 font-semibold focus:border-blue-500 focus:bg-white focus:ring-blue-500"
                        >
                            <option
                                v-for="priority in priorities"
                                :key="priority"
                                :value="priority"
                            >
                                {{ priorityLabels[priority] }}
                            </option>
                        </select>
                        <InputError :message="taskForm.errors.priority" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                    >
                        Début
                        <input
                            v-model="taskForm.start_at"
                            type="datetime-local"
                            class="h-11 w-full min-w-0 rounded-xl border-slate-200 bg-slate-50/70 font-medium focus:border-blue-500 focus:bg-white focus:ring-blue-500"
                        />
                        <InputError :message="taskForm.errors.start_at" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                    >
                        Échéance
                        <input
                            v-model="taskForm.due_at"
                            type="datetime-local"
                            class="h-11 w-full min-w-0 rounded-xl border-slate-200 bg-slate-50/70 font-medium focus:border-blue-500 focus:bg-white focus:ring-blue-500"
                        />
                        <InputError :message="taskForm.errors.due_at" />
                    </label>
                    <button
                        type="button"
                        class="group flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-left text-sm font-bold text-slate-700 shadow-sm transition hover:border-blue-200 sm:col-span-2 lg:col-span-4"
                        :aria-expanded="showAdvancedOptions"
                        @click="showAdvancedOptions = !showAdvancedOptions"
                    >
                        <span
                            ><span class="text-blue-600">Options avancées</span
                            ><span
                                class="ml-2 text-xs font-medium text-slate-400"
                                >durées, progression, description et
                                confidentialité</span
                            ></span
                        >
                        <span
                            class="grid h-7 w-7 place-items-center rounded-lg bg-slate-100 text-base text-slate-500 transition group-hover:bg-blue-100 group-hover:text-blue-700"
                            >{{ showAdvancedOptions ? "−" : "+" }}</span
                        >
                    </button>
                    <label
                        v-if="showAdvancedOptions"
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                    >
                        Durée estimée (minutes)
                        <input
                            v-model.number="taskForm.estimated_minutes"
                            type="number"
                            min="0"
                            class="w-full min-w-0 rounded-xl border-slate-200 bg-white focus:border-blue-500 focus:ring-blue-500"
                        />
                        <InputError
                            :message="taskForm.errors.estimated_minutes"
                        />
                    </label>
                    <label
                        v-if="showAdvancedOptions"
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700"
                    >
                        Durée réelle (minutes)
                        <input
                            v-model.number="taskForm.actual_minutes"
                            type="number"
                            min="0"
                            class="w-full min-w-0 rounded-xl border-slate-200 bg-white focus:border-blue-500 focus:ring-blue-500"
                        />
                        <InputError :message="taskForm.errors.actual_minutes" />
                    </label>
                    <label
                        v-if="showAdvancedOptions"
                        class="grid min-w-0 gap-2 rounded-2xl border border-blue-100 bg-blue-50/60 p-4 text-sm font-bold text-slate-700 sm:col-span-2 lg:col-span-4"
                    >
                        <span class="flex items-center justify-between gap-4"
                            ><span>Progression</span
                            ><strong
                                class="rounded-full bg-blue-600 px-3 py-1 text-xs text-white"
                                >{{ taskForm.progress }}%</strong
                            ></span
                        >
                        <input
                            v-model.number="taskForm.progress"
                            type="range"
                            min="0"
                            max="100"
                            class="w-full accent-blue-600"
                        />
                        <InputError :message="taskForm.errors.progress" />
                    </label>
                    <fieldset
                        class="grid min-w-0 gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:col-span-2 lg:col-span-4"
                    >
                        <legend class="px-2 text-sm font-black text-slate-800">
                            Responsables
                            <span class="ml-1 font-medium text-slate-400"
                                >({{
                                    taskForm.assignee_ids.length
                                }}
                                sélectionné{{
                                    taskForm.assignee_ids.length > 1 ? "s" : ""
                                }})</span
                            >
                        </legend>
                        <div
                            class="grid max-h-32 gap-2 overflow-y-auto sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <label
                                v-for="member in members"
                                :key="member.id"
                                class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold transition"
                                :class="
                                    taskForm.assignee_ids.includes(member.id)
                                        ? 'border-blue-300 bg-blue-50 text-blue-900 ring-1 ring-blue-100'
                                        : 'border-slate-100 bg-slate-50 text-slate-600 hover:border-blue-200 hover:bg-blue-50/50'
                                "
                            >
                                <input
                                    v-model="taskForm.assignee_ids"
                                    type="checkbox"
                                    :value="member.id"
                                    class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                />
                                <span
                                    class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-white text-[9px] font-black text-blue-700 shadow-sm"
                                    >{{ initials(member.name) }}</span
                                >
                                <span class="truncate">{{ member.name }}</span>
                            </label>
                        </div>
                        <InputError :message="taskForm.errors.assignee_ids" />
                    </fieldset>
                    <label
                        v-if="showAdvancedOptions"
                        class="grid min-w-0 gap-1.5 text-sm font-bold text-slate-700 sm:col-span-2 lg:col-span-4"
                    >
                        Description
                        <textarea
                            v-model="taskForm.description"
                            rows="3"
                            class="w-full min-w-0 rounded-xl border-slate-200 bg-white focus:border-blue-500 focus:ring-blue-500"
                        ></textarea>
                        <InputError :message="taskForm.errors.description" />
                    </label>
                    <label
                        v-if="showAdvancedOptions"
                        class="flex items-center gap-3 text-sm font-bold text-slate-700 sm:col-span-2 lg:col-span-4"
                    >
                        <input
                            v-model="taskForm.is_private"
                            type="checkbox"
                            class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        Tâche privée
                    </label>
                </div>

                <div
                    class="shrink-0 border-t border-slate-200 bg-white px-5 py-3 shadow-[0_-8px_24px_rgba(15,23,42,0.04)] sm:px-6"
                >
                    <InputError class="mb-3" :message="taskForm.errors.task" />
                    <div
                        class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p class="text-xs font-medium text-slate-400">
                            <span class="text-rose-500">*</span> Champ
                            obligatoire
                        </p>
                        <div class="flex justify-end gap-3">
                            <button
                                type="button"
                                class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                                @click="closeForm"
                            >
                                Annuler
                            </button>
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="taskForm.processing"
                            >
                                <svg
                                    v-if="!taskForm.processing"
                                    class="h-4 w-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.4"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M5 13l4 4L19 7"
                                    />
                                </svg>
                                <svg
                                    v-else
                                    class="h-4 w-4 animate-spin"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="9"
                                        stroke="currentColor"
                                        stroke-width="3"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z"
                                    />
                                </svg>
                                {{
                                    taskForm.processing
                                        ? "Enregistrement..."
                                        : editingTask
                                          ? "Mettre à jour"
                                          : "Créer la tâche"
                                }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>

<style scoped>
.column-list-enter-active,
.column-list-leave-active,
.task-list-enter-active,
.task-list-leave-active {
    transition:
        opacity 280ms ease,
        transform 280ms ease;
}

.column-list-enter-from,
.column-list-leave-to {
    opacity: 0;
    transform: translateY(10px) scale(0.98);
}

.task-list-enter-from,
.task-list-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.97);
}

.task-list-move {
    transition: transform 350ms cubic-bezier(0.22, 1, 0.36, 1);
}

.view-swap-enter-active,
.view-swap-leave-active {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}

.view-swap-enter-from {
    opacity: 0;
    transform: translateY(8px);
}

.view-swap-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

@keyframes wp-task-loading {
    from {
        transform: translateX(-110%);
    }

    to {
        transform: translateX(220%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .column-list-enter-active,
    .column-list-leave-active,
    .task-list-enter-active,
    .task-list-leave-active,
    .task-list-move,
    .view-swap-enter-active,
    .view-swap-leave-active {
        transition: none;
    }
}
</style>
