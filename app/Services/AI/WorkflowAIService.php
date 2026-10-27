<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WorkflowAIService
{
    public function __construct(private readonly WorkflowGraphValidator $validator) {}

    /** @param array<string, mixed>|null $currentGraph
     *  @return array<string, mixed> */
    public function generate(string $description, string $mode, ?array $currentGraph = null): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if (blank($apiKey)) {
            throw new RuntimeException('L’assistant IA n’est pas configuré. Ajoutez OPENAI_API_KEY côté serveur.');
        }

        $input = "MODE: {$mode}\n\nDESCRIPTION DU PROCESSUS:\n{$description}";
        if ($mode === 'improve' && $currentGraph !== null) {
            $input .= "\n\nWORKFLOW ACTUEL (données métier minimales):\n".json_encode($currentGraph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $graph = $this->requestGraph($apiKey, $input);

        try {
            return $this->validator->validateAndLayout($graph);
        } catch (ValidationException $exception) {
            $validationReason = collect($exception->errors())->flatten()->first();
            $validationReason = is_string($validationReason) ? $validationReason : 'Le graphe généré est incohérent.';

            Log::info('OpenAI workflow graph required automatic repair', ['reason' => $validationReason]);

            $repairedGraph = $this->requestGraph($apiKey, $this->repairInput($input, $graph, $validationReason));

            return $this->validator->validateAndLayout($repairedGraph);
        }
    }

    /** @return array<string, mixed> */
    private function requestGraph(string $apiKey, string $input): array
    {
        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.openai.workflow_timeout', 45))
                ->connectTimeout(10)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => config('services.openai.workflow_model', 'gpt-4.1-mini'),
                    'store' => false,
                    'max_output_tokens' => 6000,
                    'instructions' => $this->instructions(),
                    'input' => $input,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'workpilot_workflow_graph',
                            'strict' => true,
                            'schema' => $this->schema(),
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('OpenAI workflow connection failure', ['exception' => $exception::class]);
            throw new RuntimeException('Impossible de joindre le service IA. Réessayez dans quelques instants.');
        }

        if ($response->status() === 429) {
            Log::warning('OpenAI workflow quota or rate limit reached', ['status' => 429]);
            throw new RuntimeException('Le quota IA est temporairement atteint. Réessayez plus tard.', 429);
        }
        if (! $response->successful()) {
            Log::warning('OpenAI workflow request failed', ['status' => $response->status(), 'request_id' => $response->header('x-request-id')]);
            throw new RuntimeException('La génération IA a échoué. Vérifiez la configuration du modèle ou réessayez.');
        }

        $payload = $response->json();
        $outputPart = collect($payload['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $item): array => $item['content'] ?? [])
            ->firstWhere('type', 'output_text');
        $outputText = is_array($outputPart) ? ($outputPart['text'] ?? null) : null;
        if (! is_string($outputText)) {
            Log::warning('OpenAI workflow response contained no structured output', ['request_id' => $response->header('x-request-id')]);
            throw new RuntimeException('La réponse IA ne contient pas de workflow exploitable.');
        }

        $graph = json_decode($outputText, true);
        if (! is_array($graph) || json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('OpenAI workflow returned invalid JSON', ['request_id' => $response->header('x-request-id')]);
            throw new RuntimeException('La réponse IA est invalide. Régénérez le workflow.');
        }

        return $graph;
    }

    /**
     * @param  array<string, mixed>  $invalidGraph
     */
    private function repairInput(string $originalInput, array $invalidGraph, string $validationReason): string
    {
        $encodedGraph = json_encode($invalidGraph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $originalInput."\n\nCORRECTION OBLIGATOIRE DU GRAPHE:\n"
            ."Le graphe précédent a été refusé par WorkPilot pour cette raison: {$validationReason}\n"
            .'Produis une nouvelle version complète et corrigée. Elle doit être strictement acyclique: aucun lien ne doit revenir vers une étape précédente. '
            .'Une branche de refus ou de correction doit continuer vers une étape située plus loin ou se terminer dans un nœud Fin. '
            .'Conserve exactement un Début, au moins une Fin, deux branches libellées pour chaque Condition, et rends tous les nœuds accessibles depuis le Début. '
            ."Ne commente pas la correction; renvoie uniquement le JSON demandé.\n\n"
            ."GRAPHE REFUSÉ À CORRIGER:\n{$encodedGraph}";
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You are “WorkPilot AI Workflow Assistant”. Your only task is to design or improve business workflows.
Understand French, English, and Arabic. Return a concise, editable workflow using only: start, task, approval, condition, notification, end.
Use exactly one start and at least one end. Every node must be reachable. Never create cycles. Conditions require at least two outgoing branches with explicit labels such as Oui/Non or Accepté/Refusé.
Do not invent critical business steps. Put any non-critical inference in assumptions. When facts are missing, prefer a generic label and disclose the assumption.
For improve mode, preserve useful existing steps while applying the requested improvement. Use short stable ASCII identifiers. Maximum 40 nodes.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['workflow_name', 'summary', 'assumptions', 'nodes', 'edges'],
            'properties' => [
                'workflow_name' => ['type' => 'string', 'maxLength' => 150],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
                'assumptions' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'string', 'maxLength' => 300]],
                'nodes' => [
                    'type' => 'array', 'minItems' => 2, 'maxItems' => 40,
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['id', 'type', 'label', 'description', 'owner', 'duration', 'color'],
                        'properties' => [
                            'id' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{1,60}$'],
                            'type' => ['type' => 'string', 'enum' => ['start', 'task', 'approval', 'condition', 'notification', 'end']],
                            'label' => ['type' => 'string', 'maxLength' => 150],
                            'description' => ['type' => 'string', 'maxLength' => 1000],
                            'owner' => ['type' => 'string', 'maxLength' => 150],
                            'duration' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 3650],
                            'color' => ['type' => 'string', 'enum' => ['mint', 'sky', 'aqua', 'lavender', 'lilac', 'rose', 'peach', 'sand', 'lemon', 'pistachio', 'sage', 'ice', 'denim', 'periwinkle', 'coral', 'blush', 'latte', 'silver', 'mist', 'white']],
                        ],
                    ],
                ],
                'edges' => [
                    'type' => 'array', 'maxItems' => 80,
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['id', 'source', 'target', 'label'],
                        'properties' => [
                            'id' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{1,60}$'],
                            'source' => ['type' => 'string'],
                            'target' => ['type' => 'string'],
                            'label' => ['type' => 'string', 'maxLength' => 150],
                        ],
                    ],
                ],
            ],
        ];
    }
}
