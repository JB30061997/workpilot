<script setup>
import { AlertCircle, ArrowDown, Bot, CheckCircle2, GitBranch, LoaderCircle, RefreshCw, Sparkles, X } from "lucide-vue-next";
import { computed, onUnmounted, ref, watch } from "vue";

const props = defineProps({ show: Boolean, endpoint: String, configured: Boolean, currentGraph: Object });
const emit = defineEmits(["close", "apply"]);
const mode = ref("new");
const description = ref("");
const loading = ref(false);
const error = ref("");
const proposal = ref(null);
const examples = [
    // "Validation d’une demande d’achat avec contrôle du budget, validation du service achats, rejet et notification du demandeur.",
    // "Onboarding d’un nouveau collaborateur : préparation du matériel, création des accès, accueil, formation et validation de la période d’intégration.",
    // "معالجة طلب دعم تقني: استقبال الطلب، التشخيص، التصعيد عند الحاجة، الحل ثم إشعار المستخدم وإغلاق الطلب.",
];
const remaining = computed(() => 5000 - description.value.length);
const canGenerate = computed(() => description.value.trim().length >= 20 && description.value.length <= 5000 && props.configured && !loading.value);
const previewNodes = computed(() => [...(proposal.value?.nodes || [])].sort((first, second) => first.position.y - second.position.y || first.position.x - second.position.x));
const close = () => { if (!loading.value) emit("close"); };
const generate = async () => {
    if (!canGenerate.value) return;
    loading.value = true; error.value = "";
    try {
        const response = await window.axios.post(props.endpoint, {
            description: description.value,
            mode: mode.value,
            current_graph: mode.value === "improve" ? props.currentGraph : null,
        });
        proposal.value = response.data.graph;
    } catch (exception) {
        error.value = exception.response?.data?.message || Object.values(exception.response?.data?.errors || {}).flat().join(" · ") || "La génération a échoué. Réessayez.";
    } finally {
        loading.value = false;
    }
};
const apply = () => { if (proposal.value) emit("apply", proposal.value); };
watch(() => props.show, (show) => {
    document.body.style.overflow = show ? "hidden" : "";
    if (show) { error.value = ""; proposal.value = null; }
});
onUnmounted(() => { document.body.style.overflow = ""; });
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="duration-150 ease-in" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[90] grid place-items-center bg-slate-950/65 p-3 backdrop-blur-sm" @mousedown.self="close">
                <div class="flex max-h-[94dvh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-white/10 bg-white shadow-2xl">
                    <header class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-7">
                        <div class="flex gap-3"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-blue-600 to-violet-600 text-white shadow-lg shadow-blue-200"><Sparkles :size="21" /></span><div><h2 class="text-xl font-black text-slate-950">Générer un workflow avec IA</h2><p class="mt-1 text-sm text-slate-500">Décrivez votre processus et laissez WorkPilot construire les étapes automatiquement.</p></div></div>
                        <button class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50" :disabled="loading" @click="close"><X :size="18" /></button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50/70 p-5 sm:p-7">
                        <div v-if="!configured" class="mb-5 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"><AlertCircle class="shrink-0" :size="20" /><p><b>Assistant non configuré.</b><br />Ajoutez <code>OPENAI_API_KEY</code> dans l’environnement du serveur, puis rechargez la configuration.</p></div>
                        <template v-if="!proposal">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <button type="button" class="rounded-xl border p-4 text-left transition" :class="mode === 'new' ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-100' : 'border-slate-200 bg-white hover:border-blue-200'" @click="mode = 'new'"><b class="flex items-center gap-2 text-slate-900"><GitBranch :size="17" class="text-blue-600" /> Créer un nouveau processus</b><span class="mt-1 block text-xs text-slate-500">Générer un diagramme complet depuis votre description.</span></button>
                                <button type="button" class="rounded-xl border p-4 text-left transition" :class="mode === 'improve' ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-100' : 'border-slate-200 bg-white hover:border-violet-200'" @click="mode = 'improve'"><b class="flex items-center gap-2 text-slate-900"><Bot :size="17" class="text-violet-600" /> Améliorer le processus existant</b><span class="mt-1 block text-xs text-slate-500">Analyser les étapes actuelles et proposer une version améliorée.</span></button>
                            </div>
                            <label class="mt-5 block"><span class="mb-2 flex items-center justify-between text-sm font-bold text-slate-700"><span>Description du processus</span><span class="text-xs" :class="remaining < 250 ? 'text-amber-600' : 'text-slate-400'">{{ description.length }}/5000</span></span><textarea v-model="description" maxlength="5000" rows="17" class="w-full resize-y rounded-xl border-slate-200 bg-white p-4 text-sm leading-6 shadow-sm placeholder:text-slate-400 focus:border-blue-500 focus:ring-blue-500" placeholder="Ex. Le collaborateur soumet une demande d’achat. Son responsable vérifie le budget…"></textarea></label>
                            <!-- <div class="mt-4"><p class="text-xs font-black uppercase tracking-wide text-slate-400">Exemples</p><div class="mt-2 grid gap-2 lg:grid-cols-3"><button v-for="example in examples" :key="example" type="button" class="rounded-xl border border-slate-200 bg-white p-3 text-left text-xs leading-5 text-slate-600 hover:border-blue-300 hover:bg-blue-50" @click="description = example">{{ example }}</button></div></div> -->
                        </template>

                        <template v-else>
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><div class="flex items-start gap-3"><CheckCircle2 class="mt-0.5 shrink-0 text-emerald-600" :size="21" /><div><h3 class="font-black text-emerald-900">Proposition prête à être vérifiée</h3><p class="mt-1 text-sm text-emerald-800">{{ proposal.summary }}</p></div></div></div>
                            <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_280px]"><div class="min-w-0 rounded-xl border border-slate-200 bg-white p-4"><div class="flex items-center justify-between gap-4"><h3 class="truncate font-black text-slate-900">{{ proposal.name }}</h3><span class="shrink-0 text-xs font-bold text-blue-600">{{ proposal.nodes.length }} étapes · {{ proposal.edges.length }} liaisons</span></div><div class="mt-4 max-h-[48dvh] overflow-auto overscroll-contain rounded-xl border border-slate-100 bg-slate-50/70 p-4"><div class="flex min-w-[520px] flex-col items-center gap-2"><template v-for="(node, index) in previewNodes" :key="node.id"><div class="w-full max-w-xl rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><span class="text-[10px] font-black uppercase text-blue-600">{{ node.type }}</span><p class="mt-1 text-sm font-bold text-slate-800">{{ node.label }}</p><p class="mt-1 line-clamp-2 text-[11px] text-slate-400">{{ node.data.description || '—' }}</p></div><ArrowDown v-if="index < previewNodes.length - 1" class="shrink-0 text-slate-300" :size="18" /></template></div></div></div>
                                <aside class="rounded-xl border border-slate-200 bg-white p-4"><h3 class="text-sm font-black text-slate-900">Hypothèses signalées</h3><ul v-if="proposal.assumptions.length" class="mt-3 space-y-2"><li v-for="assumption in proposal.assumptions" :key="assumption" class="flex gap-2 text-xs leading-5 text-slate-600"><AlertCircle class="mt-0.5 shrink-0 text-amber-500" :size="14" />{{ assumption }}</li></ul><p v-else class="mt-3 text-xs text-slate-400">Aucune étape métier supplémentaire n’a été supposée.</p></aside></div>
                            <p class="mt-4 text-xs text-slate-500">L’application remplacera temporairement le diagramme sur le canvas. Rien ne sera enregistré avant votre clic sur « Enregistrer » et vous pourrez utiliser « Annuler ».</p>
                        </template>
                        <div v-if="error" class="mt-4 flex gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700"><AlertCircle class="shrink-0" :size="18" />{{ error }}</div>
                    </div>

                    <footer class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-white px-5 py-4 sm:px-7">
                        <button class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50" :disabled="loading" @click="close">Annuler</button>
                        <button v-if="proposal" class="inline-flex items-center gap-2 rounded-xl border border-blue-200 px-4 py-2.5 text-sm font-bold text-blue-700 hover:bg-blue-50" :disabled="loading" @click="proposal = null; generate()"><RefreshCw :size="16" /> Régénérer</button>
                        <button v-if="proposal" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700" @click="apply"><CheckCircle2 :size="17" /> Appliquer la proposition</button>
                        <button v-else class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-violet-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-200 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!canGenerate" @click="generate"><LoaderCircle v-if="loading" class="animate-spin" :size="17" /><Sparkles v-else :size="17" />{{ loading ? 'Génération en cours…' : 'Générer le workflow' }}</button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
