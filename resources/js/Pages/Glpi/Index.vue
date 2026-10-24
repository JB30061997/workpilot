<script setup>
import AppIcon from "@/Components/AppIcon.vue";
import InputError from "@/Components/InputError.vue";
import Modal from "@/Components/Modal.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    integration: { type: Object, default: null },
    canConfigure: { type: Boolean, default: false },
    canManageGmail: { type: Boolean, default: false },
    gmailIntegration: { type: Object, default: null },
    gmailMessages: { type: Object, default: null },
    gmailStats: {
        type: Object,
        default: () => ({ total: 0, solved: 0, unread: 0 }),
    },
    ticketFilters: { type: Object, default: () => ({}) },
    ticketAnalysis: {
        type: Object,
        default: () => ({ total: 0, domains: {}, tickets: [] }),
    },
});

const requestedTab =
    typeof window !== "undefined"
        ? new URLSearchParams(window.location.search).get("tab")
        : null;
const activeTab = ref(
    ["tracking", "tickets", "configuration"].includes(requestedTab)
        ? requestedTab
        : props.gmailIntegration
          ? "tickets"
          : props.integration
            ? "tracking"
            : "configuration",
);
const testing = ref(false);
const syncing = ref(false);
const selectedMessage = ref(null);
const form = useForm({
    base_url: props.integration?.base_url ?? "",
    api_version: props.integration?.api_version ?? "v1",
    app_token: "",
    user_token: "",
    entity_id: props.integration?.entity_id ?? "",
    is_active: props.integration?.is_active ?? true,
});
const ticketFilterForm = useForm({
    ticket_search: props.ticketFilters?.ticket_search ?? "",
    ticket_type: props.ticketFilters?.ticket_type ?? "",
});

const connectionLabel = computed(() => {
    if (!props.integration) return "Non configuré";
    if (props.integration.last_test_status === "success") return "Connecté";
    if (props.integration.last_test_status === "error")
        return "Connexion en erreur";
    return "À tester";
});

const connectionClass = computed(
    () =>
        ({
            success: "bg-emerald-50 text-emerald-700 ring-emerald-100",
            error: "bg-rose-50 text-rose-700 ring-rose-100",
        })[props.integration?.last_test_status] ??
        "bg-amber-50 text-amber-700 ring-amber-100",
);

const save = () => form.put(route("glpi.update"), { preserveScroll: true });
const testConnection = () => {
    testing.value = true;
    router.post(
        route("glpi.test"),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                testing.value = false;
            },
        },
    );
};

const eventLabels = {
    new: "Nouveau",
    updated: "Mise à jour",
    solved: "Résolu",
    closed: "Clôturé",
};
const eventClasses = {
    new: "bg-blue-50 text-blue-700 ring-blue-100",
    updated: "bg-amber-50 text-amber-700 ring-amber-100",
    solved: "bg-emerald-50 text-emerald-700 ring-emerald-100",
    closed: "bg-slate-100 text-slate-700 ring-slate-200",
};
const syncGmail = () => {
    syncing.value = true;
    router.post(
        route("glpi.gmail.sync"),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                syncing.value = false;
            },
        },
    );
};
const disconnectGmail = () =>
    router.delete(route("glpi.gmail.disconnect"), { preserveScroll: true });
const filterTickets = () =>
    ticketFilterForm.get(route("glpi.index"), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
const resetTicketFilters = () => {
    ticketFilterForm.ticket_search = "";
    ticketFilterForm.ticket_type = "";
    filterTickets();
};

// Tableau de suivi : analyse des tickets uniques issue du backend.
const selectedDomain = ref(null);
const detailSearch = ref("");
const detailCategory = ref("");
const detailPage = ref(1);
const detailPageSize = 20;

const domainDefinitions = [
    { key: "infrastructure", label: "Infrastructure", subtitle: "Réseau, matériel, serveurs…", icon: "server" },
    { key: "solutions", label: "Solutions digitales", subtitle: "Odoo, Sage, AGIRH, Moovapps…", icon: "monitor" },
    { key: "unknown", label: "Non identifiés", subtitle: "Sujets à vérifier", icon: "ticket" },
];

const emptyDomain = () => ({
    total: 0,
    statuses: { new: 0, solved: 0, closed: 0, unknown: 0 },
    categories: [],
});
const domainData = (key) => props.ticketAnalysis?.domains?.[key] ?? emptyDomain();
const selectedDomainDefinition = computed(() =>
    domainDefinitions.find((item) => item.key === selectedDomain.value),
);
const selectedDomainData = computed(() =>
    selectedDomain.value ? domainData(selectedDomain.value) : emptyDomain(),
);
const selectedCategories = computed(() => selectedDomainData.value.categories ?? []);
const selectedTickets = computed(() => {
    const term = detailSearch.value.trim().toLocaleLowerCase("fr-FR");
    return (props.ticketAnalysis?.tickets ?? []).filter((ticket) => {
        if (ticket.domain !== selectedDomain.value) return false;
        if (detailCategory.value && ticket.category !== detailCategory.value) return false;
        if (!term) return true;
        return [ticket.ticket_number, ticket.subject, ticket.category]
            .some((value) => String(value ?? "").toLocaleLowerCase("fr-FR").includes(term));
    });
});
const detailPageCount = computed(() =>
    Math.max(1, Math.ceil(selectedTickets.value.length / detailPageSize)),
);
const visibleDetailTickets = computed(() =>
    selectedTickets.value.slice((detailPage.value - 1) * detailPageSize, detailPage.value * detailPageSize),
);
const openDomainDetails = (key, category = "") => {
    selectedDomain.value = key;
    detailCategory.value = category;
    detailSearch.value = "";
    detailPage.value = 1;
};
const closeDomainDetails = () => { selectedDomain.value = null; };
const statusLabel = (status) => ({
    new: "Nouveau", solved: "Résolu", closed: "Clôturé", unknown: "Statut inconnu",
})[status] ?? "Statut inconnu";
const statusClass = (status) => ({
    new: "bg-blue-50 text-blue-700", solved: "bg-emerald-50 text-emerald-700",
    closed: "bg-slate-100 text-slate-700", unknown: "bg-amber-50 text-amber-700",
})[status] ?? "bg-amber-50 text-amber-700";
const formatTicketDate = (value) => value ? new Date(value).toLocaleString("fr-FR") : "—";
const glpiTicketUrl = (number) => /^\d+$/.test(String(number ?? ""))
    ? `https://tickets.holged.com/front/ticket.form.php?id=${Number(number)}`
    : null;

</script>

<template>
    <Head title="Suivi GLPI - WorkPilot" />

    <AuthenticatedLayout>
        <div
            class="mx-auto grid min-w-0 max-w-[1700px] gap-4 px-3 py-5 sm:px-5 lg:px-7"
        >
            <header
                class="wp-page-header flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-7"
            >
                <div>
                    <p
                        class="text-[11px] font-bold uppercase tracking-[.15em] text-blue-700"
                    >
                        Support informatique
                    </p>
                    <h1
                        class="mt-1 text-2xl font-bold tracking-tight text-slate-950"
                    >
                        Suivi des tickets GLPI
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        Centralisez vos indicateurs GLPI par mois et par
                        affectation.
                    </p>
                </div>
                <span
                    class="inline-flex w-fit items-center gap-2 rounded-full px-3 py-2 text-xs font-black ring-1 ring-inset"
                    :class="connectionClass"
                    ><i class="h-2 w-2 rounded-full bg-current" />{{
                        connectionLabel
                    }}</span
                >
            </header>

            <div
                class="flex w-fit gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm"
            >
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-xs font-black transition"
                    :class="
                        activeTab === 'tracking'
                            ? 'bg-blue-600 text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50'
                    "
                    @click="activeTab = 'tracking'"
                >
                    Tableau de suivi
                </button>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-xs font-black transition"
                    :class="
                        activeTab === 'tickets'
                            ? 'bg-blue-600 text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50'
                    "
                    @click="activeTab = 'tickets'"
                >
                    Tickets Gmail
                </button>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-xs font-black transition"
                    :class="
                        activeTab === 'configuration'
                            ? 'bg-blue-600 text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50'
                    "
                    @click="activeTab = 'configuration'"
                >
                    Configuration API
                </button>
            </div>

            <section v-if="activeTab === 'tracking'" class="grid min-w-0 gap-4">
                <div class="border border-slate-200 bg-white px-5 py-5 shadow-sm sm:px-6">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.16em] text-blue-600">Analyse automatique · Gmail GLPI</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-950">Tableau de suivi des tickets</h2>
                            <p class="mt-1 text-xs text-slate-500">Classification par sujet uniquement · Tickets regroupés par numéro</p>
                        </div>
                        <div class="text-left sm:text-right">
                            <p class="text-xs font-semibold text-slate-500">Total des tickets analysés</p>
                            <p class="mt-1 text-3xl font-bold text-slate-950">{{ ticketAnalysis?.total ?? 0 }}</p>
                        </div>
                    </div>
                </div>

                <div v-if="!gmailIntegration" class="border border-slate-200 bg-white px-6 py-14 text-center shadow-sm">
                    <div class="mx-auto grid h-12 w-12 place-items-center bg-blue-50 text-blue-600"><AppIcon name="mail" class="h-6 w-6" /></div>
                    <h3 class="mt-4 text-base font-bold text-slate-900">Connectez Gmail pour analyser les tickets</h3>
                    <p class="mt-2 text-sm text-slate-500">Les statistiques sont calculées à partir des sujets des messages importés.</p>
                    <button type="button" class="mt-5 bg-blue-600 px-5 py-2.5 text-xs font-bold text-white hover:bg-blue-700" @click="activeTab = 'tickets'">Ouvrir Tickets Gmail</button>
                </div>

                <template v-else>
                    <div class="grid gap-3 md:grid-cols-3">
                        <article v-for="domain in domainDefinitions" :key="domain.key" class="flex min-w-0 flex-col border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start gap-3">
                                <div class="grid h-11 w-11 shrink-0 place-items-center bg-blue-50 text-blue-600"><AppIcon :name="domain.icon" class="h-5 w-5" /></div>
                                <div class="min-w-0">
                                    <h3 class="text-sm font-bold text-slate-900">{{ domain.label }}</h3>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ domain.subtitle }}</p>
                                </div>
                            </div>
                            <p class="mt-5 text-3xl font-bold text-slate-950">{{ domainData(domain.key).total }}</p>
                            <p class="mt-1 text-xs text-slate-500">Tickets</p>
                            <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-slate-100 pt-4">
                                <div><p class="text-[11px] text-slate-500">Nouveaux</p><p class="mt-0.5 text-lg font-bold text-blue-600">{{ domainData(domain.key).statuses?.new ?? 0 }}</p></div>
                                <div><p class="text-[11px] text-slate-500">Résolus</p><p class="mt-0.5 text-lg font-bold text-emerald-600">{{ domainData(domain.key).statuses?.solved ?? 0 }}</p></div>
                                <div><p class="text-[11px] text-slate-500">Clôturés</p><p class="mt-0.5 text-lg font-bold text-slate-800">{{ domainData(domain.key).statuses?.closed ?? 0 }}</p></div>
                                <div><p class="text-[11px] text-slate-500">Statut inconnu</p><p class="mt-0.5 text-lg font-bold text-amber-600">{{ domainData(domain.key).statuses?.unknown ?? 0 }}</p></div>
                            </div>
                            <button type="button" class="mt-5 inline-flex h-10 items-center justify-center gap-2 border border-blue-200 bg-blue-50 px-4 text-xs font-bold text-blue-700 transition hover:bg-blue-600 hover:text-white" @click="openDomainDetails(domain.key)">
                                <AppIcon name="eye" class="h-4 w-4" />Afficher les détails
                            </button>
                        </article>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <article v-for="domain in domainDefinitions.slice(0, 2)" :key="domain.key" class="min-w-0 overflow-hidden border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                                <div><h3 class="text-sm font-bold text-slate-900">{{ domain.label }}</h3><p class="mt-1 text-xs text-slate-500">Répartition par catégorie</p></div>
                                <span class="text-xs font-bold text-slate-500">{{ domainData(domain.key).total }} tickets</span>
                            </div>
                            <div v-if="!domainData(domain.key).categories?.length" class="px-5 py-10 text-center text-sm text-slate-400">Aucun ticket identifié dans ce domaine.</div>
                            <div v-else class="divide-y divide-slate-100">
                                <button v-for="category in domainData(domain.key).categories" :key="category.name" type="button" class="block w-full px-5 py-3 text-left transition hover:bg-blue-50/40" @click="openDomainDetails(domain.key, category.name)">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="truncate text-sm font-semibold text-slate-700">{{ category.name }}</span>
                                        <span class="text-sm font-bold text-slate-900">{{ category.total }}</span>
                                    </div>
                                    <div class="mt-2 h-1.5 overflow-hidden bg-slate-100">
                                        <div class="h-full bg-blue-600" :style="{ width: `${domainData(domain.key).total ? (category.total / domainData(domain.key).total) * 100 : 0}%` }"></div>
                                    </div>
                                    <p class="mt-1.5 text-[11px] text-slate-500">{{ category.statuses?.solved ?? 0 }} résolus · {{ category.statuses?.closed ?? 0 }} clôturés</p>
                                </button>
                            </div>
                        </article>
                    </div>
                    <p class="text-xs text-slate-500">Les états sont déduits des événements Gmail disponibles et ne remplacent pas les statuts officiels GLPI. « Mise à jour » seule ne permet pas de confirmer un statut « En cours ».</p>
                </template>
            </section>

            <section v-else-if="activeTab === 'tickets'" class="grid gap-4">
                <div
                    v-if="!gmailIntegration"
                    class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
                >
                    <div
                        class="grid min-h-[430px] place-items-center bg-[radial-gradient(circle_at_top,_rgba(37,99,235,0.08),_transparent_45%)] px-6 py-14 text-center"
                    >
                        <div class="max-w-lg">
                            <span
                                class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-200"
                                ><AppIcon name="mail" class="h-8 w-8"
                            /></span>
                            <p
                                class="mt-6 text-[11px] font-black uppercase tracking-[.18em] text-blue-600"
                            >
                                Gmail · GLPI_Tickets
                            </p>
                            <h2
                                class="mt-2 text-2xl font-black tracking-tight text-slate-950"
                            >
                                Connectez la boîte support
                            </h2>
                            <p class="mt-3 text-sm leading-6 text-slate-500">
                                WorkPilot importera uniquement les messages
                                portant le libellé
                                <strong class="text-slate-700"
                                    >GLPI_Tickets</strong
                                >, avec un accès Gmail en lecture seule.
                            </p>
                            <a
                                v-if="canManageGmail"
                                :href="route('glpi.gmail.connect')"
                                class="mt-6 inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-black text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-blue-700 hover:shadow-md"
                                ><AppIcon
                                    name="link"
                                    class="h-4 w-4"
                                />Connecter Gmail</a
                            >
                            <p
                                v-else
                                class="mt-6 rounded-xl bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-800"
                            >
                                Vous devez être membre actif du service pour
                                connecter la boîte support.
                            </p>
                        </div>
                    </div>
                </div>

                <template v-else>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <article
                            class="rounded-2xl border border-blue-100 bg-blue-50/70 p-4"
                        >
                            <p
                                class="text-[10px] font-black uppercase tracking-wider text-blue-600"
                            >
                                Messages importés
                            </p>
                            <p class="mt-2 text-2xl font-black text-slate-950">
                                {{ gmailStats.total }}
                            </p>
                        </article>
                        <article
                            class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4"
                        >
                            <p
                                class="text-[10px] font-black uppercase tracking-wider text-emerald-600"
                            >
                                Tickets résolus
                            </p>
                            <p class="mt-2 text-2xl font-black text-slate-950">
                                {{ gmailStats.solved }}
                            </p>
                        </article>
                        <article
                            class="rounded-2xl border border-amber-100 bg-amber-50/70 p-4"
                        >
                            <p
                                class="text-[10px] font-black uppercase tracking-wider text-amber-600"
                            >
                                Non lus
                            </p>
                            <p class="mt-2 text-2xl font-black text-slate-950">
                                {{ gmailStats.unread }}
                            </p>
                        </article>
                        <article
                            class="rounded-2xl border border-slate-200 bg-white p-4"
                        >
                            <p
                                class="text-[10px] font-black uppercase tracking-wider text-slate-400"
                            >
                                Boîte connectée
                            </p>
                            <p
                                class="mt-2 truncate text-sm font-black text-slate-800"
                            >
                                {{ gmailIntegration.email }}
                            </p>
                        </article>
                    </div>

                    <div
                        class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
                    >
                        <div
                            class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between lg:px-6"
                        >
                            <div>
                                <p
                                    class="text-[10px] font-black uppercase tracking-[.16em] text-blue-600"
                                >
                                    {{ gmailIntegration.label_name }}
                                </p>
                                <h2
                                    class="mt-1 text-lg font-black text-slate-950"
                                >
                                    Tickets reçus par Gmail
                                </h2>
                                <p class="mt-1 text-xs text-slate-500">
                                    Dernière synchronisation :
                                    {{
                                        gmailIntegration.last_synced_at
                                            ? new Date(
                                                  gmailIntegration.last_synced_at,
                                              ).toLocaleString("fr-FR")
                                            : "Jamais"
                                    }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-if="canManageGmail"
                                    type="button"
                                    class="h-10 rounded-xl border border-slate-200 px-4 text-xs font-black text-slate-600 transition hover:border-rose-200 hover:text-rose-600"
                                    @click="disconnectGmail"
                                >
                                    Déconnecter</button
                                ><button
                                    v-if="canManageGmail"
                                    type="button"
                                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-xs font-black text-white transition hover:bg-blue-700 disabled:opacity-60"
                                    :disabled="syncing"
                                    @click="syncGmail"
                                >
                                    <AppIcon name="refresh" class="h-4 w-4" />{{
                                        syncing
                                            ? "Synchronisation…"
                                            : "Synchroniser"
                                    }}
                                </button>
                            </div>
                        </div>

                        <form
                            class="grid gap-3 border-b border-slate-100 bg-slate-50/60 p-4 sm:grid-cols-[minmax(0,1fr)_220px_auto] sm:p-5"
                            @submit.prevent="filterTickets"
                        >
                            <label class="relative"
                                ><AppIcon
                                    name="search"
                                    class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><input
                                    v-model="ticketFilterForm.ticket_search"
                                    type="search"
                                    placeholder="Rechercher par ticket, sujet ou expéditeur…"
                                    class="h-11 w-full rounded-xl border-slate-200 pl-10 text-sm focus:border-blue-500 focus:ring-blue-500"
                            /></label>
                            <select
                                v-model="ticketFilterForm.ticket_type"
                                class="h-11 rounded-xl border-slate-200 text-sm font-semibold text-slate-700 focus:border-blue-500 focus:ring-blue-500"
                            >
                                <option value="">Tous les événements</option>
                                <option value="new">Nouveaux tickets</option>
                                <option value="updated">Mises à jour</option>
                                <option value="solved">Résolus</option>
                                <option value="closed">Clôturés</option>
                            </select>
                            <div class="flex gap-2">
                                <button
                                    type="submit"
                                    class="h-11 rounded-xl bg-blue-600 px-4 text-xs font-black text-white hover:bg-blue-700"
                                >
                                    Filtrer</button
                                ><button
                                    v-if="
                                        ticketFilterForm.ticket_search ||
                                        ticketFilterForm.ticket_type
                                    "
                                    type="button"
                                    class="h-11 rounded-xl border border-slate-200 bg-white px-4 text-xs font-black text-slate-600 hover:bg-slate-50"
                                    @click="resetTicketFilters"
                                >
                                    Effacer
                                </button>
                            </div>
                        </form>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[900px] text-left">
                                <thead
                                    class="bg-slate-50 text-[10px] font-black uppercase tracking-wider text-slate-400"
                                >
                                    <tr>
                                        <th class="px-6 py-3">Ticket</th>
                                        <th class="px-4 py-3">Sujet</th>
                                        <th class="px-4 py-3">Expéditeur</th>
                                        <th class="px-4 py-3">Événement</th>
                                        <th class="px-4 py-3">Reçu le</th>
                                        <th class="px-6 py-3 text-right">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="message in gmailMessages.data"
                                        :key="message.id"
                                        class="transition hover:bg-blue-50/40"
                                        :class="
                                            message.is_unread
                                                ? 'bg-blue-50/20'
                                                : ''
                                        "
                                    >
                                        <td class="whitespace-nowrap px-6 py-4">
                                            <span
                                                class="font-mono text-xs font-black text-blue-700"
                                                >{{
                                                    message.ticket_number
                                                        ? `#${message.ticket_number}`
                                                        : "—"
                                                }}</span
                                            ><span
                                                v-if="message.is_unread"
                                                class="ml-2 inline-block h-2 w-2 rounded-full bg-blue-600"
                                                title="Non lu"
                                            />
                                        </td>
                                        <td class="max-w-[410px] px-4 py-4">
                                            <p
                                                class="truncate text-sm font-bold text-slate-900"
                                            >
                                                {{ message.subject }}
                                            </p>
                                            <p
                                                class="mt-1 truncate text-xs text-slate-400"
                                            >
                                                {{ message.snippet }}
                                            </p>
                                        </td>
                                        <td class="px-4 py-4">
                                            <p
                                                class="text-xs font-bold text-slate-700"
                                            >
                                                {{
                                                    message.sender ||
                                                    "Support IT"
                                                }}
                                            </p>
                                            <p
                                                class="mt-1 text-[11px] text-slate-400"
                                            >
                                                {{ message.sender_email }}
                                            </p>
                                        </td>
                                        <td class="px-4 py-4">
                                            <span
                                                class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-black ring-1 ring-inset"
                                                :class="
                                                    eventClasses[
                                                        message.event_type
                                                    ]
                                                "
                                                >{{
                                                    eventLabels[
                                                        message.event_type
                                                    ]
                                                }}</span
                                            >
                                        </td>
                                        <td
                                            class="whitespace-nowrap px-4 py-4 text-xs font-semibold text-slate-500"
                                        >
                                            {{
                                                message.received_at
                                                    ? new Date(
                                                          message.received_at,
                                                      ).toLocaleString("fr-FR")
                                                    : "—"
                                            }}
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <button
                                                type="button"
                                                class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-black text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                                                @click="
                                                    selectedMessage = message
                                                "
                                            >
                                                <AppIcon
                                                    name="eye"
                                                    class="h-4 w-4"
                                                />Consulter
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="!gmailMessages.data.length">
                                        <td
                                            colspan="6"
                                            class="px-6 py-16 text-center"
                                        >
                                            <p class="font-bold text-slate-600">
                                                Aucun ticket trouvé
                                            </p>
                                            <p
                                                class="mt-1 text-xs text-slate-400"
                                            >
                                                Synchronisez Gmail ou modifiez
                                                vos filtres.
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div
                            v-if="gmailMessages.links?.length > 3"
                            class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <p class="text-xs font-semibold text-slate-500">
                                {{ gmailMessages.from }}–{{
                                    gmailMessages.to
                                }}
                                sur {{ gmailMessages.total }} messages
                            </p>
                            <div class="flex flex-wrap gap-1">
                                <Link
                                    v-for="link in gmailMessages.links"
                                    :key="link.label"
                                    :href="link.url || '#'"
                                    preserve-scroll
                                    preserve-state
                                    class="rounded-lg border px-3 py-1.5 text-xs font-bold"
                                    :class="[
                                        link.active
                                            ? 'border-blue-600 bg-blue-600 text-white'
                                            : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50',
                                        !link.url
                                            ? 'pointer-events-none opacity-40'
                                            : '',
                                    ]"
                                    v-html="link.label"
                                />
                            </div>
                        </div>
                    </div>
                </template>
            </section>

            <section
                v-else
                class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]"
            >
                <form
                    class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
                    @submit.prevent="save"
                >
                    <div
                        class="flex items-start gap-3 border-b border-slate-100 pb-5"
                    >
                        <span
                            class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-blue-100"
                            ><AppIcon name="referential" class="h-5 w-5"
                        /></span>
                        <div>
                            <h2 class="font-black text-slate-900">
                                Connexion à GLPI
                            </h2>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Les jetons sont chiffrés avant leur stockage et
                                ne sont jamais renvoyés au navigateur.
                            </p>
                        </div>
                    </div>
                    <fieldset
                        :disabled="!canConfigure"
                        class="mt-5 disabled:opacity-60"
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label
                                class="grid gap-1.5 text-xs font-bold text-slate-700 sm:col-span-2"
                                >URL de l’API GLPI<input
                                    v-model="form.base_url"
                                    type="url"
                                    required
                                    placeholder="https://glpi.exemple.ma/apirest.php"
                                    class="h-11 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError
                                    :message="form.errors.base_url"
                            /></label>
                            <label
                                class="grid gap-1.5 text-xs font-bold text-slate-700"
                                >Version API<select
                                    v-model="form.api_version"
                                    class="h-11 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"
                                >
                                    <option value="v1">
                                        REST API v1
                                    </option></select
                                ><InputError :message="form.errors.api_version"
                            /></label>
                            <label
                                class="grid gap-1.5 text-xs font-bold text-slate-700"
                                >Entité GLPI
                                <span class="font-medium text-slate-400"
                                    >(optionnel)</span
                                ><input
                                    v-model="form.entity_id"
                                    type="number"
                                    min="0"
                                    placeholder="ID de l’entité"
                                    class="h-11 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError
                                    :message="form.errors.entity_id"
                            /></label>
                            <label
                                class="grid gap-1.5 text-xs font-bold text-slate-700"
                                >App Token
                                <span class="font-medium text-slate-400"
                                    >(si activé dans GLPI)</span
                                ><input
                                    v-model="form.app_token"
                                    type="password"
                                    :placeholder="
                                        integration?.has_app_token
                                            ? '•••••••••••• (conservé)'
                                            : 'Jeton de l’application'
                                    "
                                    autocomplete="new-password"
                                    class="h-11 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError
                                    :message="form.errors.app_token"
                            /></label>
                            <label
                                class="grid gap-1.5 text-xs font-bold text-slate-700"
                                >User Token<input
                                    v-model="form.user_token"
                                    type="password"
                                    :required="!integration?.has_user_token"
                                    :placeholder="
                                        integration?.has_user_token
                                            ? '•••••••••••• (conservé)'
                                            : 'Jeton utilisateur'
                                    "
                                    autocomplete="new-password"
                                    class="h-11 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError
                                    :message="form.errors.user_token"
                            /></label>
                            <label
                                class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-bold text-slate-700 sm:col-span-2"
                                ><input
                                    v-model="form.is_active"
                                    type="checkbox"
                                    class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                />Activer cette intégration pour le service
                                actuel</label
                            >
                        </div>
                        <div
                            class="mt-6 flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-5"
                        >
                            <button
                                v-if="integration"
                                type="button"
                                class="h-10 rounded-xl border border-slate-200 bg-white px-4 text-xs font-black text-slate-600 transition hover:border-blue-200 hover:text-blue-600 disabled:opacity-50"
                                :disabled="testing"
                                @click="testConnection"
                            >
                                {{
                                    testing
                                        ? "Test en cours…"
                                        : "Tester la connexion"
                                }}</button
                            ><button
                                type="submit"
                                class="h-10 rounded-xl bg-blue-600 px-5 text-xs font-black text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50"
                                :disabled="form.processing"
                            >
                                {{
                                    form.processing
                                        ? "Enregistrement…"
                                        : "Enregistrer"
                                }}
                            </button>
                        </div>
                    </fieldset>
                </form>

                <aside
                    class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <p
                        class="text-[10px] font-black uppercase tracking-[0.16em] text-blue-600"
                    >
                        État de l’intégration
                    </p>
                    <h2 class="mt-1 font-black text-slate-900">Diagnostic</h2>
                    <div class="mt-5 grid gap-3">
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p
                                class="text-[10px] font-bold uppercase tracking-wide text-slate-400"
                            >
                                Statut
                            </p>
                            <p class="mt-2 text-sm font-black text-slate-800">
                                {{ connectionLabel }}
                            </p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p
                                class="text-[10px] font-bold uppercase tracking-wide text-slate-400"
                            >
                                Dernier test
                            </p>
                            <p class="mt-2 text-sm font-bold text-slate-700">
                                {{
                                    integration?.last_tested_at
                                        ? new Date(
                                              integration.last_tested_at,
                                          ).toLocaleString("fr-FR")
                                        : "Jamais testé"
                                }}
                            </p>
                        </div>
                        <div
                            v-if="integration?.last_test_message"
                            class="rounded-2xl p-4 text-xs leading-5"
                            :class="
                                integration.last_test_status === 'success'
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : 'bg-rose-50 text-rose-700'
                            "
                        >
                            {{ integration.last_test_message }}
                        </div>
                    </div>
                    <div
                        v-if="!canConfigure"
                        class="mt-5 rounded-2xl bg-amber-50 p-4 text-xs leading-5 text-amber-800"
                    >
                        Seul un responsable autorisé peut modifier les jetons de
                        ce service.
                    </div>
                </aside>
            </section>
        </div>

        <!-- DÉTAILS D'UN DOMAINE : HEADER ET FOOTER FIXES -->
        <Modal :show="Boolean(selectedDomain)" max-width="6xl" @close="closeDomainDetails">
            <div v-if="selectedDomain" class="flex max-h-[88dvh] min-h-0 w-full flex-col overflow-hidden bg-white">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-7">
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[.15em] text-blue-600">Détails de l'analyse GLPI</p>
                        <h2 class="mt-1 text-xl font-bold text-slate-950">{{ selectedDomainDefinition?.label }}</h2>
                        <p class="mt-1 text-xs text-slate-500">{{ selectedDomainData.total }} tickets · Classification basée sur le sujet</p>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center border border-slate-200 text-slate-500 hover:bg-slate-50" aria-label="Fermer" @click="closeDomainDetails">✕</button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 px-5 py-5 sm:px-7">
                    <div class="grid gap-3 sm:grid-cols-4">
                        <div v-for="item in [{ key: 'new', label: 'Nouveaux' }, { key: 'solved', label: 'Résolus' }, { key: 'closed', label: 'Clôturés' }, { key: 'unknown', label: 'Statut inconnu' }]" :key="item.key" class="border border-slate-200 bg-white p-3">
                            <p class="text-xs text-slate-500">{{ item.label }}</p>
                            <p class="mt-1 text-xl font-bold text-slate-900">{{ selectedDomainData.statuses?.[item.key] ?? 0 }}</p>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_240px]">
                        <input v-model="detailSearch" @input="detailPage = 1" type="search" placeholder="Rechercher un ticket, sujet ou catégorie…" class="h-10 w-full border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" />
                        <select v-model="detailCategory" @change="detailPage = 1" class="h-10 w-full border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Toutes les catégories</option>
                            <option v-for="category in selectedCategories" :key="category.name" :value="category.name">{{ category.name }} ({{ category.total }})</option>
                        </select>
                    </div>
                    <div class="mt-4 overflow-x-auto border border-slate-200 bg-white">
                        <table class="w-full min-w-[720px] text-left">
                            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wide text-slate-500"><tr>
                                <th class="px-4 py-3">Ticket</th><th class="px-4 py-3">Sujet</th><th class="px-4 py-3">Catégorie</th><th class="px-4 py-3">Statut estimé</th><th class="px-4 py-3">Dernier événement</th><th class="px-4 py-3 text-right">GLPI</th>
                            </tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(ticket, index) in visibleDetailTickets" :key="`${ticket.ticket_number ?? 'sans-numero'}-${index}`" class="hover:bg-blue-50/30">
                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-bold text-blue-700">{{ ticket.ticket_number ? `#${ticket.ticket_number}` : '—' }}</td>
                                    <td class="max-w-[360px] px-4 py-3 text-xs font-semibold text-slate-800"><span class="block break-words">{{ ticket.subject || 'Sans objet' }}</span></td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ ticket.category }}</td>
                                    <td class="px-4 py-3"><span class="inline-flex whitespace-nowrap px-2 py-1 text-[11px] font-bold" :class="statusClass(ticket.status)">{{ statusLabel(ticket.status) }}</span></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{{ formatTicketDate(ticket.received_at) }}</td>
                                    <td class="px-4 py-3 text-right"><a v-if="glpiTicketUrl(ticket.ticket_number)" :href="glpiTicketUrl(ticket.ticket_number)" target="_blank" rel="noopener noreferrer" class="text-xs font-bold text-blue-600 hover:underline">Ouvrir ↗</a><span v-else class="text-xs text-slate-400">—</span></td>
                                </tr>
                                <tr v-if="!visibleDetailTickets.length"><td colspan="6" class="px-4 py-12 text-center text-sm text-slate-500">Aucun ticket correspondant.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white px-5 py-4 sm:px-7">
                    <p class="text-xs text-slate-500">{{ selectedTickets.length }} résultat(s) · Page {{ detailPage }} / {{ detailPageCount }}</p>
                    <div class="flex items-center gap-2">
                        <button type="button" :disabled="detailPage <= 1" class="border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-40" @click="detailPage--">Précédent</button>
                        <button type="button" :disabled="detailPage >= detailPageCount" class="border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-40" @click="detailPage++">Suivant</button>
                        <button type="button" class="bg-blue-600 px-4 py-2 text-xs font-bold text-white hover:bg-blue-700" @click="closeDomainDetails">Fermer</button>
                    </div>
                </div>
            </div>
        </Modal>

        <Modal
            :show="Boolean(selectedMessage)"
            max-width="6xl"
            @close="selectedMessage = null"
        >
            <div
                v-if="selectedMessage"
                class="flex max-h-[88dvh] min-h-0 w-full flex-col overflow-hidden bg-white"
            >
                <!-- HEADER -->
                <div
                    class="shrink-0 border-b border-slate-200 bg-white px-6 py-6 sm:px-9"
                >
                    <div class="flex items-start justify-between gap-6">
                        <div class="min-w-0 flex-1">
                            <div class="mb-4 flex flex-wrap items-center gap-3">
                                <span
                                    class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-bold ring-1 ring-inset"
                                    :class="
                                        eventClasses[selectedMessage.event_type]
                                    "
                                >
                                    {{
                                        eventLabels[
                                            selectedMessage.event_type
                                        ] || "Message"
                                    }}
                                </span>

                                <span
                                    class="text-xs font-semibold text-slate-400"
                                >
                                    Ticket GLPI
                                </span>

                                <span
                                    class="font-mono text-sm font-bold text-blue-600"
                                >
                                    {{
                                        selectedMessage.ticket_number
                                            ? `#${selectedMessage.ticket_number}`
                                            : "—"
                                    }}
                                </span>
                            </div>

                            <h2
                                class="break-words text-xl font-bold leading-8 tracking-tight text-slate-950 sm:text-2xl"
                            >
                                {{ selectedMessage.subject }}
                            </h2>

                            <p class="mt-2 text-sm text-slate-500">
                                Détails du message reçu depuis Gmail
                            </p>
                        </div>

                        <button
                            type="button"
                            class="grid h-10 w-10 shrink-0 place-items-center rounded-md border border-slate-200 bg-slate-50 text-slate-500 transition hover:bg-rose-50 hover:text-rose-600"
                            aria-label="Fermer"
                            @click="selectedMessage = null"
                        >
                            <svg
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            >
                                <path d="M18 6 6 18M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- BODY SCROLLABLE -->
                <div
                    class="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-slate-50 px-6 py-7 sm:px-9"
                >
                    <!-- INFORMATIONS -->
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="border border-slate-200 bg-white p-6">
                            <div
                                class="mb-3 flex items-center gap-2 text-blue-600"
                            >
                                <AppIcon name="mail" class="h-5 w-5" />
                                <span
                                    class="text-xs font-bold uppercase tracking-wider"
                                >
                                    Expéditeur
                                </span>
                            </div>

                            <p class="text-base font-bold text-slate-900">
                                {{ selectedMessage.sender || "Support IT" }}
                            </p>

                            <p class="mt-1 break-all text-sm text-slate-500">
                                {{ selectedMessage.sender_email || "—" }}
                            </p>
                        </div>

                        <div class="border border-slate-200 bg-white p-6">
                            <div
                                class="mb-3 flex items-center gap-2 text-blue-600"
                            >
                                <AppIcon name="calendar" class="h-5 w-5" />
                                <span
                                    class="text-xs font-bold uppercase tracking-wider"
                                >
                                    Date de réception
                                </span>
                            </div>

                            <p class="text-base font-bold text-slate-900">
                                {{
                                    selectedMessage.received_at
                                        ? new Date(
                                              selectedMessage.received_at,
                                          ).toLocaleString("fr-FR")
                                        : "—"
                                }}
                            </p>
                        </div>
                    </div>

                    <!-- CONTENU DU MESSAGE -->
                    <div class="mt-6 border border-slate-200 bg-white">
                        <div
                            class="flex items-center gap-3 border-b border-slate-100 px-6 py-5"
                        >
                            <AppIcon
                                name="mail"
                                class="h-5 w-5 text-blue-600"
                            />
                            <h3 class="text-base font-bold text-slate-900">
                                Contenu du message
                            </h3>
                        </div>

                        <div class="min-h-[260px] p-6 sm:p-8">
                            <div
                                class="whitespace-pre-wrap break-words text-sm leading-8 text-slate-700"
                            >
                                {{
                                    selectedMessage.body_text ||
                                    selectedMessage.body ||
                                    selectedMessage.snippet ||
                                    "Aucun contenu disponible."
                                }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FOOTER FIXE -->
                <div
                    class="flex shrink-0 flex-wrap items-center justify-between gap-4 border-t border-slate-200 bg-white px-6 py-5 sm:px-9"
                >
                    <span class="text-xs font-medium text-slate-400">
                        Source : Gmail · GLPI Tickets
                    </span>

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex h-11 items-center justify-center rounded-md border border-slate-200 bg-white px-6 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                            @click="selectedMessage = null"
                        >
                            Fermer
                        </button>

                        <a
                            v-if="
                                selectedMessage.ticket_number &&
                                /^\d+$/.test(
                                    String(selectedMessage.ticket_number),
                                )
                            "
                            :href="`https://tickets.holged.com/front/ticket.form.php?id=${Number(selectedMessage.ticket_number)}`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-blue-600 px-6 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700"
                        >
                            Ouvrir dans GLPI

                            <svg
                                class="h-4 w-4"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M7 17 17 7M8 7h9v9" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
