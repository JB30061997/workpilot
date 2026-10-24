<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('project_category_id')
                ->nullable()
                ->after('axis_id')
                ->constrained()
                ->nullOnDelete();
        });

        DB::table('organizations')
            ->orderBy('id')
            ->each(function (object $organization): void {
                $managerId = DB::table('organization_members')
                    ->where('organization_id', $organization->id)
                    ->where('is_active', true)
                    ->orderByDesc('is_owner')
                    ->value('user_id');

                if ($managerId === null) {
                    return;
                }

                $now = now();
                $categories = collect([
                    ['name' => 'Support', 'slug' => 'support'],
                    ['name' => 'Pédagogique', 'slug' => 'pedagogique'],
                    ['name' => 'Autre', 'slug' => 'autre'],
                ])->map(fn (array $category): array => [
                    ...$category,
                    'organization_id' => $organization->id,
                    'manager_id' => $managerId,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('project_categories')->insert($categories);

                $otherCategoryId = DB::table('project_categories')
                    ->where('organization_id', $organization->id)
                    ->where('slug', 'autre')
                    ->value('id');

                DB::table('projects')
                    ->where('organization_id', $organization->id)
                    ->whereNull('project_category_id')
                    ->update(['project_category_id' => $otherCategoryId]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_category_id');
        });

        Schema::dropIfExists('project_categories');
    }
};
