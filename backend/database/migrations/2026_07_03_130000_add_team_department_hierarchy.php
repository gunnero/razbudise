<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('group_key')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->integer('sort_order')->default(0)->index();
            $table->foreignId('parent_department_id')->nullable()->constrained('team_departments')->nullOnDelete();
            $table->foreignId('manager_team_member_id')->nullable()->constrained('team_members')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'sort_order']);
            $table->index(['parent_department_id', 'sort_order']);
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('primary_department_id')
                ->nullable()
                ->after('profile_image_id')
                ->constrained('team_departments')
                ->nullOnDelete();
            $table->foreignId('manager_team_member_id')
                ->nullable()
                ->after('primary_department_id')
                ->constrained('team_members')
                ->nullOnDelete();

            $table->index(['primary_department_id', 'status']);
            $table->index(['manager_team_member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_department_id');
            $table->dropConstrainedForeignId('manager_team_member_id');
        });

        Schema::dropIfExists('team_departments');
    }
};
