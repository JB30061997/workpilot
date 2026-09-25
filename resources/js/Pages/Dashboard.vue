<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    context: {
        type: Object,
        required: true,
    },

    stats: {
        type: Object,
        required: true,
    },

    actionPlans: {
        type: Array,
        default: () => [],
    },

    projects: {
        type: Array,
        default: () => [],
    },

    myTasks: {
        type: Array,
        default: () => [],
    },

    todayPlanning: {
        type: Array,
        default: () => [],
    },

    teamMembers: {
        type: Array,
        default: () => [],
    },

    tasksByStatus: {
        type: Array,
        default: () => [],
    },
});

/*
|--------------------------------------------------------------------------
| Context
|--------------------------------------------------------------------------
*/

const organizationName = computed(() => {
    return props.context?.organization?.name ?? 'Organisation';
});

const departmentName = computed(() => {
    return props.context?.department?.name ?? 'Service';
});

const departmentCode = computed(() => {
    return props.context?.department?.code ?? '';
});

/*
|--------------------------------------------------------------------------
| Global progress
|--------------------------------------------------------------------------
*/

const globalProgress = computed(() => {
    const value = Number(props.stats?.department_progress ?? 0);

    return Math.min(100, Math.max(0, value));
});

const tasksProgress = computed(() => {
    const value = Number(props.stats?.tasks_progress ?? 0);

    return Math.min(100, Math.max(0, value));
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

const progressClass = (progress) => {
    const value = Number(progress ?? 0);

    if (value >= 80) {
        return 'bg-emerald-500';
    }

    if (value >= 50) {
        return 'bg-blue-500';
    }

    if (value >= 25) {
        return 'bg-amber-500';
    }

    return 'bg-rose-500';
};

const planIconClass = (index) => {
    const classes = [
        'bg-blue-50 text-blue-600 ring-blue-100',
        'bg-violet-50 text-violet-600 ring-violet-100',
        'bg-emerald-50 text-emerald-600 ring-emerald-100',
        'bg-amber-50 text-amber-600 ring-amber-100',
    ];

    return classes[index % classes.length];
};

const statusLabel = (status) => {
    const labels = {
        draft: 'Brouillon',
        active: 'Actif',
        completed: 'Terminé',
        archived: 'Archivé',
        planned: 'Planifié',
        in_progress: 'En cours',
        on_hold: 'En attente',
        cancelled: 'Annulé',
    };

    return labels[status] ?? status ?? '-';
};

const statusClass = (status) => {
    const classes = {
        draft: 'bg-slate-100 text-slate-600',
        active: 'bg-emerald-50 text-emerald-700',
        completed: 'bg-emerald-50 text-emerald-700',
        archived: 'bg-slate-100 text-slate-600',
        planned: 'bg-blue-50 text-blue-700',
        in_progress: 'bg-amber-50 text-amber-700',
        on_hold: 'bg-orange-50 text-orange-700',
        cancelled: 'bg-rose-50 text-rose-700',
    };

    return classes[status] ?? 'bg-slate-100 text-slate-600';
};

const priorityLabel = (priority) => {
    const labels = {
        low: 'Faible',
        medium: 'Moyenne',
        high: 'Haute',
        critical: 'Critique',
    };

    return labels[priority] ?? priority ?? '-';
};

const priorityClass = (priority) => {
    const classes = {
        low: 'bg-slate-100 text-slate-600',
        medium: 'bg-blue-50 text-blue-700',
        high: 'bg-amber-50 text-amber-700',
        critical: 'bg-rose-50 text-rose-700',
    };

    return classes[priority] ?? 'bg-slate-100 text-slate-600';
};

const formatDate = (date) => {
    if (!date) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(date));
};

const formatTime = (date) => {
    if (!date) {
        return '--:--';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(date));
};

const initials = (name) => {
    if (!name) {
        return '?';
    }

    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
};
</script>

<template>
    <Head title="Dashboard - WorkPilot" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50/70">

            <!-- ========================================================= -->
            <!-- PAGE -->
            <!-- ========================================================= -->

            <div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">

                <!-- ===================================================== -->
                <!-- HEADER -->
                <!-- ===================================================== -->

                <section
                    class="relative overflow-hidden rounded-3xl bg-slate-950 px-6 py-7 text-white shadow-xl shadow-slate-200 sm:px-8 lg:px-10"
                >
                    <!-- Background decoration -->

                    <div
                        class="pointer-events-none absolute -right-20 -top-32 h-80 w-80 rounded-full bg-blue-500/20 blur-3xl"
                    ></div>

                    <div
                        class="pointer-events-none absolute bottom-[-120px] left-[30%] h-64 w-64 rounded-full bg-violet-500/10 blur-3xl"
                    ></div>

                    <div class="relative z-10">

                        <div
                            class="flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between"
                        >
                            <!-- Left -->

                            <div>
                                <div
                                    class="mb-3 flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <span
                                        class="rounded-full bg-white/10 px-3 py-1 font-medium text-slate-200 ring-1 ring-white/10"
                                    >
                                        {{ organizationName }}
                                    </span>

                                    <span class="text-slate-500">
                                        /
                                    </span>

                                    <span
                                        class="rounded-full bg-blue-500/15 px-3 py-1 font-medium text-blue-200 ring-1 ring-blue-400/20"
                                    >
                                        {{ departmentName }}
                                    </span>
                                </div>

                                <h1
                                    class="text-2xl font-bold tracking-tight sm:text-3xl"
                                >
                                    Tableau de bord
                                </h1>

                                <p
                                    class="mt-2 max-w-2xl text-sm leading-6 text-slate-400"
                                >
                                    Vue globale de l'activité, des plans
                                    d'action, des projets et de l'avancement
                                    du service
                                    <span class="font-semibold text-slate-200">
                                        {{ departmentName }}
                                    </span>.
                                </p>
                            </div>

                            <!-- Global Progress -->

                            <div
                                class="w-full rounded-2xl bg-white/[0.07] p-5 ring-1 ring-white/10 backdrop-blur xl:w-[360px]"
                            >
                                <div
                                    class="flex items-center justify-between"
                                >
                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase tracking-[0.15em] text-slate-400"
                                        >
                                            Avancement global
                                        </p>

                                        <p
                                            class="mt-1 text-sm text-slate-300"
                                        >
                                            Service {{ departmentName }}
                                        </p>
                                    </div>

                                    <div
                                        class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10"
                                    >
                                        <span
                                            class="text-xl font-bold"
                                        >
                                            {{ globalProgress }}%
                                        </span>
                                    </div>
                                </div>

                                <div
                                    class="mt-5 h-2.5 overflow-hidden rounded-full bg-white/10"
                                >
                                    <div
                                        class="h-full rounded-full bg-blue-500 transition-all duration-700"
                                        :style="{
                                            width: `${globalProgress}%`,
                                        }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ===================================================== -->
                <!-- KPI -->
                <!-- ===================================================== -->

                <section
                    class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-8"
                >
                    <!-- Plans -->

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-600"
                        >
                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"
                                />
                            </svg>
                        </div>

                        <p
                            class="mt-4 text-2xl font-bold text-slate-900"
                        >
                            {{ stats.action_plans ?? 0 }}
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Plans d'action
                        </p>
                    </div>

                    <!-- Projects -->

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600"
                        >
                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M3 7h18M5 7V5a1 1 0 011-1h4l2 3h6a1 1 0 011 1v11a1 1 0 01-1 1H5a1 1 0 01-1-1V7z"
                                />
                            </svg>
                        </div>

                        <p
                            class="mt-4 text-2xl font-bold text-slate-900"
                        >
                            {{ stats.projects ?? 0 }}
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Projets
                        </p>
                    </div>

                    <!-- Active projects -->

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-cyan-50 text-cyan-600"
                        >
                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M5 12h14M13 6l6 6-6 6"
                                />
                            </svg>
                        </div>

                        <p
                            class="mt-4 text-2xl font-bold text-slate-900"
                        >
                            {{ stats.active_projects ?? 0 }}
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Projets actifs
                        </p>
                    </div>

                    <!-- Tasks -->

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"
                        >
                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"
                                />
                            </svg>
                        </div>

                        <p
                            class="mt-4 text-2xl font-bold text-slate-900"
                        >
                            {{ stats.tasks ?? 0 }}
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Tâches
                        </p>
                    </div>

                    <!-- Completed -->

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"
                        >
                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M5 13l4 4L19 7"
                                />
                            </svg>
                        </div>

                        <p
                            class="mt-4 text-2xl font-bold text-slate-900"
                        >
                            {{ stats.completed_tasks ?? 0 }}
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Terminées
                        </p>
                    </div>

                    <!-- Overdue -->

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-rose-600"
                        >
                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M12 9v4m0 4h.01M10.3 3.7L2.6 17a2 2 0 001.7 3h15.4a2 2 0 001.7-3L13.7 3.7a2 2 0 00-3.4 0z"
                                />
                            </svg>
                        </div>

                        <p
                            class="mt-4 text-2xl font-bold text-slate-900"
                        >
                            {{ stats.overdue_tasks ?? 0 }}
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            En retard
                        </p>
                    </div>

                    <!-- Team -->

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600"
                        >
                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M17 20h5v-2a4 4 0 00-5-4M9 20H2v-2a4 4 0 014-4h3m4-4a4 4 0 100-8 4 4 0 000 8zm-7 0a3 3 0 100-6 3 3 0 000 6z"
                                />
                            </svg>
                        </div>

                        <p
                            class="mt-4 text-2xl font-bold text-slate-900"
                        >
                            {{ stats.team_members ?? 0 }}
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Équipe
                        </p>
                    </div>

                    <!-- Sessions -->

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-fuchsia-50 text-fuchsia-600"
                        >
                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14H4V6a1 1 0 011-1z"
                                />
                            </svg>
                        </div>

                        <p
                            class="mt-4 text-2xl font-bold text-slate-900"
                        >
                            {{ stats.work_sessions ?? 0 }}
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Sessions
                        </p>
                    </div>
                </section>

                <!-- ===================================================== -->
                <!-- ACTION PLANS -->
                <!-- ===================================================== -->

                <section class="mt-8">
                    <div
                        class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600"
                            >
                                Pilotage stratégique
                            </p>

                            <h2
                                class="mt-1 text-xl font-bold text-slate-900"
                            >
                                Plans d'action
                            </h2>

                            <p class="mt-1 text-sm text-slate-500">
                                Suivi des plans d'action du service
                                {{ departmentName }}.
                            </p>
                        </div>

                        <div
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm shadow-sm"
                        >
                            <span class="text-slate-500">
                                Avancement service
                            </span>

                            <span
                                class="ml-2 font-bold text-slate-900"
                            >
                                {{ globalProgress }}%
                            </span>
                        </div>
                    </div>

                    <!-- Plans grid -->

                    <div
                        v-if="actionPlans.length"
                        class="grid grid-cols-1 gap-5 xl:grid-cols-2"
                    >
                        <article
                            v-for="(plan, index) in actionPlans"
                            :key="plan.id"
                            class="group rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-200/60"
                        >
                            <!-- Plan header -->

                            <div
                                class="flex items-start justify-between gap-4"
                            >
                                <div class="flex min-w-0 gap-4">
                                    <div
                                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ring-1"
                                        :class="planIconClass(index)"
                                    >
                                        <svg
                                            class="h-6 w-6"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="1.8"
                                                d="M4 19V5m0 14h16M8 15l3-3 3 2 5-6"
                                            />
                                        </svg>
                                    </div>

                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <h3
                                                class="truncate text-lg font-bold text-slate-900"
                                            >
                                                {{ plan.name }}
                                            </h3>

                                            <span
                                                class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                                :class="
                                                    statusClass(plan.status)
                                                "
                                            >
                                                {{
                                                    statusLabel(
                                                        plan.status
                                                    )
                                                }}
                                            </span>
                                        </div>

                                        <p
                                            class="mt-1 text-xs font-medium text-slate-400"
                                        >
                                            {{ plan.code }}
                                        </p>
                                    </div>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p
                                        class="text-2xl font-black text-slate-900"
                                    >
                                        {{ plan.progress }}%
                                    </p>

                                    <p
                                        class="text-xs text-slate-400"
                                    >
                                        progression
                                    </p>
                                </div>
                            </div>

                            <!-- Description -->

                            <p
                                class="mt-5 line-clamp-2 min-h-[40px] text-sm leading-6 text-slate-500"
                            >
                                {{
                                    plan.description ||
                                    'Aucune description disponible.'
                                }}
                            </p>

                            <!-- Progress -->

                            <div class="mt-5">
                                <div
                                    class="mb-2 flex items-center justify-between text-xs"
                                >
                                    <span
                                        class="font-medium text-slate-500"
                                    >
                                        Avancement
                                    </span>

                                    <span
                                        class="font-bold text-slate-700"
                                    >
                                        {{ plan.progress }}%
                                    </span>
                                </div>

                                <div
                                    class="h-2 overflow-hidden rounded-full bg-slate-100"
                                >
                                    <div
                                        class="h-full rounded-full transition-all duration-700"
                                        :class="
                                            progressClass(plan.progress)
                                        "
                                        :style="{
                                            width: `${Math.min(
                                                100,
                                                Math.max(
                                                    0,
                                                    plan.progress ?? 0
                                                )
                                            )}%`,
                                        }"
                                    ></div>
                                </div>
                            </div>

                            <!-- Plan statistics -->

                            <div
                                class="mt-5 grid grid-cols-2 gap-3"
                            >
                                <div
                                    class="rounded-2xl bg-slate-50 px-4 py-3"
                                >
                                    <p
                                        class="text-xs font-medium text-slate-400"
                                    >
                                        Axes
                                    </p>

                                    <p
                                        class="mt-1 text-lg font-bold text-slate-900"
                                    >
                                        {{ plan.axes_count ?? 0 }}
                                    </p>
                                </div>

                                <div
                                    class="rounded-2xl bg-slate-50 px-4 py-3"
                                >
                                    <p
                                        class="text-xs font-medium text-slate-400"
                                    >
                                        Projets
                                    </p>

                                    <p
                                        class="mt-1 text-lg font-bold text-slate-900"
                                    >
                                        {{
                                            plan.projects_count ??
                                            0
                                        }}
                                    </p>
                                </div>
                            </div>

                            <!-- Axes -->

                            <div
                                v-if="plan.axes?.length"
                                class="mt-5 border-t border-slate-100 pt-5"
                            >
                                <p
                                    class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400"
                                >
                                    Axes stratégiques
                                </p>

                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="axis in plan.axes"
                                        :key="axis.id"
                                        class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-600"
                                    >
                                        {{ axis.name }}
                                    </span>
                                </div>
                            </div>
                        </article>
                    </div>

                    <!-- Empty plans -->

                    <div
                        v-else
                        class="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center"
                    >
                        <div
                            class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"
                        >
                            <svg
                                class="h-6 w-6"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M9 12h6m-6 4h6M9 8h6M5 4h14v16H5z"
                                />
                            </svg>
                        </div>

                        <h3
                            class="mt-4 font-semibold text-slate-900"
                        >
                            Aucun plan d'action
                        </h3>

                        <p class="mt-1 text-sm text-slate-500">
                            Aucun plan d'action actif n'est disponible
                            pour ce service.
                        </p>
                    </div>
                </section>

                <!-- ===================================================== -->
                <!-- SERVICE PROGRESS + TASK STATUS -->
                <!-- ===================================================== -->

                <section
                    class="mt-8 grid grid-cols-1 gap-5 xl:grid-cols-3"
                >
                    <!-- Progress -->

                    <div
                        class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm xl:col-span-1"
                    >
                        <p
                            class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600"
                        >
                            Performance
                        </p>

                        <h2
                            class="mt-1 text-lg font-bold text-slate-900"
                        >
                            Progression du service
                        </h2>

                        <div
                            class="mt-7 flex items-center justify-center"
                        >
                            <div
                                class="relative flex h-44 w-44 items-center justify-center rounded-full"
                                :style="{
                                    background: `conic-gradient(#2563eb ${globalProgress * 3.6}deg, #e2e8f0 0deg)`,
                                }"
                            >
                                <div
                                    class="flex h-36 w-36 flex-col items-center justify-center rounded-full bg-white"
                                >
                                    <span
                                        class="text-4xl font-black tracking-tight text-slate-900"
                                    >
                                        {{ globalProgress }}%
                                    </span>

                                    <span
                                        class="mt-1 text-xs font-medium text-slate-400"
                                    >
                                        Global
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-7 rounded-2xl bg-slate-50 p-4"
                        >
                            <div
                                class="flex items-center justify-between"
                            >
                                <span class="text-sm text-slate-500">
                                    Progression des tâches
                                </span>

                                <span
                                    class="text-sm font-bold text-slate-900"
                                >
                                    {{ tasksProgress }}%
                                </span>
                            </div>

                            <div
                                class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200"
                            >
                                <div
                                    class="h-full rounded-full bg-emerald-500"
                                    :style="{
                                        width: `${tasksProgress}%`,
                                    }"
                                ></div>
                            </div>
                        </div>
                    </div>

                    <!-- Tasks by status -->

                    <div
                        class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm xl:col-span-2"
                    >
                        <div
                            class="flex items-start justify-between"
                        >
                            <div>
                                <p
                                    class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600"
                                >
                                    Activité
                                </p>

                                <h2
                                    class="mt-1 text-lg font-bold text-slate-900"
                                >
                                    Répartition des tâches
                                </h2>
                            </div>

                            <span
                                class="rounded-xl bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600"
                            >
                                {{ stats.tasks ?? 0 }} tâches
                            </span>
                        </div>

                        <div
                            v-if="tasksByStatus.length"
                            class="mt-6 space-y-4"
                        >
                            <div
                                v-for="status in tasksByStatus"
                                :key="status.id"
                            >
                                <div
                                    class="mb-2 flex items-center justify-between"
                                >
                                    <div
                                        class="flex items-center gap-2"
                                    >
                                        <span
                                            class="h-2.5 w-2.5 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    status.color ||
                                                    '#64748b',
                                            }"
                                        ></span>

                                        <span
                                            class="text-sm font-medium text-slate-700"
                                        >
                                            {{ status.name }}
                                        </span>
                                    </div>

                                    <span
                                        class="text-sm font-bold text-slate-900"
                                    >
                                        {{ status.total }}
                                    </span>
                                </div>

                                <div
                                    class="h-2 overflow-hidden rounded-full bg-slate-100"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :style="{
                                            backgroundColor:
                                                status.color ||
                                                '#64748b',

                                            width: `${
                                                stats.tasks
                                                    ? Math.min(
                                                          100,
                                                          (status.total /
                                                              stats.tasks) *
                                                              100
                                                      )
                                                    : 0
                                            }%`,
                                        }"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else
                            class="flex min-h-[220px] items-center justify-center text-sm text-slate-400"
                        >
                            Aucune tâche disponible.
                        </div>
                    </div>
                </section>

                <!-- ===================================================== -->
                <!-- PROJECTS -->
                <!-- ===================================================== -->

                <section
                    class="mt-8 rounded-3xl border border-slate-200 bg-white shadow-sm"
                >
                    <div
                        class="flex flex-col gap-3 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600"
                            >
                                Portefeuille
                            </p>

                            <h2
                                class="mt-1 text-lg font-bold text-slate-900"
                            >
                                Projets récents
                            </h2>
                        </div>

                        <span
                            class="text-sm font-medium text-slate-400"
                        >
                            {{ projects.length }} affiché(s)
                        </span>
                    </div>

                    <div
                        v-if="projects.length"
                        class="overflow-x-auto"
                    >
                        <table class="w-full min-w-[900px]">
                            <thead>
                                <tr
                                    class="border-b border-slate-100 bg-slate-50/70 text-left"
                                >
                                    <th
                                        class="px-6 py-3 text-xs font-bold uppercase tracking-wider text-slate-400"
                                    >
                                        Projet
                                    </th>

                                    <th
                                        class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400"
                                    >
                                        Plan d'action
                                    </th>

                                    <th
                                        class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400"
                                    >
                                        Statut
                                    </th>

                                    <th
                                        class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400"
                                    >
                                        Priorité
                                    </th>

                                    <th
                                        class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400"
                                    >
                                        Avancement
                                    </th>

                                    <th
                                        class="px-6 py-3 text-xs font-bold uppercase tracking-wider text-slate-400"
                                    >
                                        Échéance
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr
                                    v-for="project in projects"
                                    :key="project.id"
                                    class="border-b border-slate-100 transition hover:bg-slate-50/70 last:border-0"
                                >
                                    <td class="px-6 py-4">
                                        <p
                                            class="font-semibold text-slate-900"
                                        >
                                            {{ project.name }}
                                        </p>

                                        <p
                                            class="mt-0.5 text-xs text-slate-400"
                                        >
                                            {{ project.code }}
                                        </p>
                                    </td>

                                    <td class="px-4 py-4">
                                        <p
                                            class="text-sm font-medium text-slate-700"
                                        >
                                            {{
                                                project.action_plan
                                                    ?.name ?? '—'
                                            }}
                                        </p>

                                        <p
                                            v-if="project.axis"
                                            class="mt-0.5 text-xs text-slate-400"
                                        >
                                            {{ project.axis.name }}
                                        </p>
                                    </td>

                                    <td class="px-4 py-4">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="
                                                statusClass(
                                                    project.status
                                                )
                                            "
                                        >
                                            {{
                                                statusLabel(
                                                    project.status
                                                )
                                            }}
                                        </span>
                                    </td>

                                    <td class="px-4 py-4">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="
                                                priorityClass(
                                                    project.priority
                                                )
                                            "
                                        >
                                            {{
                                                priorityLabel(
                                                    project.priority
                                                )
                                            }}
                                        </span>
                                    </td>

                                    <td class="px-4 py-4">
                                        <div class="w-32">
                                            <div
                                                class="mb-1.5 flex justify-between text-xs"
                                            >
                                                <span
                                                    class="text-slate-400"
                                                >
                                                    Progression
                                                </span>

                                                <span
                                                    class="font-bold text-slate-700"
                                                >
                                                    {{
                                                        project.progress
                                                    }}%
                                                </span>
                                            </div>

                                            <div
                                                class="h-1.5 overflow-hidden rounded-full bg-slate-100"
                                            >
                                                <div
                                                    class="h-full rounded-full"
                                                    :class="
                                                        progressClass(
                                                            project.progress
                                                        )
                                                    "
                                                    :style="{
                                                        width: `${project.progress}%`,
                                                    }"
                                                ></div>
                                            </div>
                                        </div>
                                    </td>

                                    <td
                                        class="px-6 py-4 text-sm text-slate-500"
                                    >
                                        {{
                                            formatDate(
                                                project.due_date
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-else
                        class="px-6 py-12 text-center text-sm text-slate-400"
                    >
                        Aucun projet disponible pour ce service.
                    </div>
                </section>

                <!-- ===================================================== -->
                <!-- MY TASKS + PLANNING -->
                <!-- ===================================================== -->

                <section
                    class="mt-8 grid grid-cols-1 gap-5 xl:grid-cols-2"
                >
                    <!-- My tasks -->

                    <div
                        class="rounded-3xl border border-slate-200 bg-white shadow-sm"
                    >
                        <div
                            class="border-b border-slate-100 px-6 py-5"
                        >
                            <p
                                class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600"
                            >
                                Personnel
                            </p>

                            <h2
                                class="mt-1 text-lg font-bold text-slate-900"
                            >
                                Mes tâches
                            </h2>
                        </div>

                        <div
                            v-if="myTasks.length"
                            class="divide-y divide-slate-100"
                        >
                            <div
                                v-for="task in myTasks"
                                :key="task.id"
                                class="flex items-center gap-4 px-6 py-4 transition hover:bg-slate-50"
                            >
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500"
                                >
                                    <svg
                                        class="h-5 w-5"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="1.8"
                                            d="M5 13l4 4L19 7"
                                        />
                                    </svg>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-sm font-semibold text-slate-900"
                                    >
                                        {{ task.title }}
                                    </p>

                                    <p
                                        class="mt-1 truncate text-xs text-slate-400"
                                    >
                                        {{
                                            task.project?.name ??
                                            'Sans projet'
                                        }}
                                        ·
                                        {{
                                            formatDate(
                                                task.due_at
                                            )
                                        }}
                                    </p>
                                </div>

                                <span
                                    v-if="task.status"
                                    class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :style="{
                                        backgroundColor: `${
                                            task.status.color ||
                                            '#64748b'
                                        }15`,

                                        color:
                                            task.status.color ||
                                            '#64748b',
                                    }"
                                >
                                    {{ task.status.name }}
                                </span>
                            </div>
                        </div>

                        <div
                            v-else
                            class="px-6 py-12 text-center text-sm text-slate-400"
                        >
                            Aucune tâche assignée.
                        </div>
                    </div>

                    <!-- Planning -->

                    <div
                        class="rounded-3xl border border-slate-200 bg-white shadow-sm"
                    >
                        <div
                            class="border-b border-slate-100 px-6 py-5"
                        >
                            <p
                                class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600"
                            >
                                Organisation
                            </p>

                            <h2
                                class="mt-1 text-lg font-bold text-slate-900"
                            >
                                Planning du jour
                            </h2>
                        </div>

                        <div
                            v-if="todayPlanning.length"
                            class="px-6 py-3"
                        >
                            <div
                                v-for="slot in todayPlanning"
                                :key="slot.id"
                                class="relative border-l-2 border-blue-100 py-4 pl-6"
                            >
                                <span
                                    class="absolute -left-[6px] top-6 h-2.5 w-2.5 rounded-full bg-blue-500 ring-4 ring-blue-50"
                                ></span>

                                <div
                                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div>
                                        <p
                                            class="text-xs font-bold text-blue-600"
                                        >
                                            {{
                                                formatTime(
                                                    slot.start_at
                                                )
                                            }}
                                            —
                                            {{
                                                formatTime(
                                                    slot.end_at
                                                )
                                            }}
                                        </p>

                                        <p
                                            class="mt-1 text-sm font-semibold text-slate-900"
                                        >
                                            {{ slot.title }}
                                        </p>

                                        <p
                                            v-if="
                                                slot.work_session
                                            "
                                            class="mt-1 text-xs text-slate-400"
                                        >
                                            {{
                                                slot
                                                    .work_session
                                                    .name
                                            }}
                                        </p>
                                    </div>

                                    <div
                                        v-if="slot.members?.length"
                                        class="flex -space-x-2"
                                    >
                                        <div
                                            v-for="member in slot.members.slice(
                                                0,
                                                4
                                            )"
                                            :key="member.id"
                                            class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-white bg-slate-900 text-[10px] font-bold text-white"
                                            :title="member.name"
                                        >
                                            {{
                                                initials(
                                                    member.name
                                                )
                                            }}
                                        </div>

                                        <div
                                            v-if="
                                                slot.members
                                                    .length > 4
                                            "
                                            class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-white bg-slate-100 text-[10px] font-bold text-slate-600"
                                        >
                                            +{{
                                                slot.members
                                                    .length - 4
                                            }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else
                            class="px-6 py-12 text-center"
                        >
                            <div
                                class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"
                            >
                                <svg
                                    class="h-6 w-6"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="1.8"
                                        d="M8 7V3m8 4V3M4 11h16M5 5h14v15H5z"
                                    />
                                </svg>
                            </div>

                            <p
                                class="mt-3 text-sm font-medium text-slate-600"
                            >
                                Aucun créneau aujourd'hui
                            </p>

                            <p
                                class="mt-1 text-xs text-slate-400"
                            >
                                Votre planning du jour est libre.
                            </p>
                        </div>
                    </div>
                </section>

                <!-- ===================================================== -->
                <!-- TEAM -->
                <!-- ===================================================== -->

                <section
                    class="mb-10 mt-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
                >
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600"
                            >
                                Collaboration
                            </p>

                            <h2
                                class="mt-1 text-lg font-bold text-slate-900"
                            >
                                Équipe {{ departmentName }}
                            </h2>
                        </div>

                        <span
                            class="text-sm font-medium text-slate-400"
                        >
                            {{ teamMembers.length }} membre(s)
                        </span>
                    </div>

                    <div
                        v-if="teamMembers.length"
                        class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                    >
                        <div
                            v-for="member in teamMembers"
                            :key="member.id"
                            class="flex items-center gap-3 rounded-2xl border border-slate-100 bg-slate-50/70 p-4"
                        >
                            <div
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-xs font-bold text-white"
                            >
                                {{ initials(member.name) }}
                            </div>

                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-semibold text-slate-900"
                                >
                                    {{ member.name }}
                                </p>

                                <p
                                    class="mt-0.5 truncate text-xs text-slate-400"
                                >
                                    {{ member.email }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="py-10 text-center text-sm text-slate-400"
                    >
                        Aucun membre dans ce service.
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>