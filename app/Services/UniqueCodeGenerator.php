<?php

namespace App\Services;

use App\Models\ActionPlan;
use App\Models\Project;
use Illuminate\Support\Str;

class UniqueCodeGenerator
{
    public function forActionPlan(string $name, int $organizationId): string
    {
        return $this->generate($name, fn (string $code): bool => ActionPlan::query()
            ->where('organization_id', $organizationId)->where('code', $code)->exists());
    }

    public function forProject(string $name, int $organizationId): string
    {
        return $this->generate($name, fn (string $code): bool => Project::query()
            ->where('organization_id', $organizationId)->where('code', $code)->exists());
    }

    private function generate(string $name, callable $exists): string
    {
        $baseCode = Str::of($name)->ascii()->slug('-')->upper()->limit(240, '')->toString();
        $baseCode = $baseCode !== '' ? $baseCode : 'SANS-NOM';
        $code = $baseCode;
        $suffix = 2;

        while ($exists($code)) {
            $suffixText = '-'.$suffix;
            $code = Str::limit($baseCode, 255 - strlen($suffixText), '').$suffixText;
            $suffix++;
        }

        return $code;
    }
}
