<?php

namespace App\Services\AI;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WorkflowGraphValidator
{
    private const NODE_TYPES = ['start', 'task', 'approval', 'condition', 'notification', 'end'];

    /** @param array<string, mixed> $graph
     *  @return array<string, mixed> */
    public function validateAndLayout(array $graph): array
    {
        $nodes = collect($graph['nodes'] ?? []);
        $edges = collect($graph['edges'] ?? []);

        if ($nodes->isEmpty() || $nodes->count() > 40 || $edges->count() > 80) {
            $this->fail('Le workflow généré doit contenir entre 1 et 40 étapes et au maximum 80 connexions.');
        }

        $nodeIds = $nodes->pluck('id');
        if ($nodeIds->contains(null) || $nodeIds->unique()->count() !== $nodes->count()) {
            $this->fail('Les identifiants des étapes générées doivent être uniques.');
        }

        foreach ($nodes as $node) {
            if (! is_string($node['id'] ?? null) || ! preg_match('/^[a-zA-Z0-9_-]{1,60}$/', $node['id'])) {
                $this->fail('Un identifiant d’étape généré est invalide.');
            }
            if (! in_array($node['type'] ?? null, self::NODE_TYPES, true) || blank($node['label'] ?? null)) {
                $this->fail('Une étape générée contient un type ou un libellé invalide.');
            }
        }

        if ($nodes->where('type', 'start')->count() !== 1 || $nodes->where('type', 'end')->isEmpty()) {
            $this->fail('Le workflow généré doit contenir exactement un début et au moins une fin.');
        }

        $edgeIds = $edges->pluck('id');
        if ($edgeIds->contains(null) || $edgeIds->unique()->count() !== $edges->count()) {
            $this->fail('Les identifiants des connexions générées doivent être uniques.');
        }
        foreach ($edges as $edge) {
            if (! $nodeIds->contains($edge['source'] ?? null) || ! $nodeIds->contains($edge['target'] ?? null) || $edge['source'] === $edge['target']) {
                $this->fail('Une connexion générée pointe vers une étape inexistante ou vers elle-même.');
            }
        }

        $startId = $nodes->firstWhere('type', 'start')['id'];
        $endIds = $nodes->where('type', 'end')->pluck('id');
        if ($edges->contains('target', $startId) || $edges->contains(fn (array $edge): bool => $endIds->contains($edge['source']))) {
            $this->fail('Le début ne peut pas recevoir de connexion et une fin ne peut pas avoir de sortie.');
        }

        $this->ensureReachableAndAcyclic($nodes, $edges);
        $this->ensureConditionBranches($nodes, $edges);

        return [
            'name' => mb_substr((string) ($graph['workflow_name'] ?? 'Workflow généré'), 0, 150),
            'summary' => mb_substr((string) ($graph['summary'] ?? ''), 0, 1000),
            'assumptions' => collect($graph['assumptions'] ?? [])->filter(fn ($value) => is_string($value) && filled($value))->take(8)->values()->all(),
            'nodes' => $this->layout($nodes, $edges),
            'edges' => $edges->map(fn (array $edge): array => [
                'id' => $edge['id'],
                'source' => $edge['source'],
                'target' => $edge['target'],
                'sourceHandle' => null,
                'label' => mb_substr((string) ($edge['label'] ?? ''), 0, 150),
            ])->values()->all(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $nodes
     *  @param Collection<int, array<string, mixed>> $edges */
    private function ensureReachableAndAcyclic(Collection $nodes, Collection $edges): void
    {
        $startId = $nodes->firstWhere('type', 'start')['id'];
        $reachable = collect([$startId]);
        do {
            $before = $reachable->count();
            $reachable = $reachable->merge($edges->whereIn('source', $reachable)->pluck('target'))->unique();
        } while ($reachable->count() > $before);
        if ($reachable->count() !== $nodes->count()) {
            $this->fail('Toutes les étapes générées doivent être accessibles depuis le début.');
        }

        $incoming = $nodes->mapWithKeys(fn (array $node): array => [$node['id'] => 0])->all();
        foreach ($edges as $edge) {
            $incoming[$edge['target']]++;
        }
        $queue = collect($incoming)->filter(fn (int $count): bool => $count === 0)->keys()->values();
        $visited = 0;
        while ($queue->isNotEmpty()) {
            $current = $queue->shift();
            $visited++;
            foreach ($edges->where('source', $current) as $edge) {
                $incoming[$edge['target']]--;
                if ($incoming[$edge['target']] === 0) {
                    $queue->push($edge['target']);
                }
            }
        }
        if ($visited !== $nodes->count()) {
            $this->fail('Le workflow généré contient un cycle. Décrivez explicitement une boucle si elle est indispensable.');
        }
    }

    /** @param Collection<int, array<string, mixed>> $nodes
     *  @param Collection<int, array<string, mixed>> $edges */
    private function ensureConditionBranches(Collection $nodes, Collection $edges): void
    {
        foreach ($nodes->where('type', 'condition') as $condition) {
            $branches = $edges->where('source', $condition['id']);
            if ($branches->count() < 2 || $branches->contains(fn (array $edge): bool => blank($edge['label'] ?? null))) {
                $this->fail('Chaque condition doit posséder au moins deux branches clairement libellées.');
            }
        }
    }

    /** @param Collection<int, array<string, mixed>> $nodes
     *  @param Collection<int, array<string, mixed>> $edges
     *  @return array<int, array<string, mixed>> */
    private function layout(Collection $nodes, Collection $edges): array
    {
        $startId = $nodes->firstWhere('type', 'start')['id'];
        $levels = [$startId => 0];
        $queue = collect([$startId]);
        while ($queue->isNotEmpty()) {
            $source = $queue->shift();
            foreach ($edges->where('source', $source) as $edge) {
                $level = $levels[$source] + 1;
                if (! isset($levels[$edge['target']]) || $level > $levels[$edge['target']]) {
                    $levels[$edge['target']] = $level;
                    $queue->push($edge['target']);
                }
            }
        }

        $rowsByLevel = [];

        return $nodes->map(function (array $node) use ($levels, &$rowsByLevel): array {
            $level = $levels[$node['id']] ?? 0;
            $row = $rowsByLevel[$level] ?? 0;
            $rowsByLevel[$level] = $row + 1;

            return [
                'id' => $node['id'],
                'type' => $node['type'],
                'label' => mb_substr((string) $node['label'], 0, 150),
                'position' => ['x' => 160 + ($row * 360), 'y' => 120 + ($level * 230)],
                'data' => [
                    'description' => mb_substr((string) ($node['description'] ?? ''), 0, 1000),
                    'owner' => mb_substr((string) ($node['owner'] ?? ''), 0, 150),
                    'duration' => is_int($node['duration'] ?? null) ? $node['duration'] : null,
                    'color' => $node['color'] ?? 'sky',
                    'aiGenerated' => true,
                ],
            ];
        })->values()->all();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['generation' => $message]);
    }
}
