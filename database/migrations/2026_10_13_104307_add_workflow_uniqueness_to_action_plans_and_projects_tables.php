<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_plans', function (Blueprint $table) {
            $table->unique(['organization_id', 'code'], 'action_plans_organization_code_unique');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('creation_token')->nullable()->after('created_by');
            $table->unique(['organization_id', 'creation_token'], 'projects_organization_creation_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('action_plans', function (Blueprint $table) {
            $table->dropUnique('action_plans_organization_code_unique');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_organization_creation_token_unique');
            $table->dropColumn('creation_token');
        });
    }
};
