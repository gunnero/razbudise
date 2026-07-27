<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('group_key')->nullable()->index();
            $table->integer('sort_order')->default(0)->index();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->index(['status', 'group_key', 'sort_order']);
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('display_title')->nullable();
            $table->text('short_bio')->nullable();
            $table->longText('full_bio')->nullable();
            $table->foreignId('profile_image_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('member_type')->default('human')->index();
            $table->string('status')->default('active')->index();
            $table->integer('sort_order')->default(0)->index();
            $table->date('joined_at')->nullable();
            $table->json('social_links')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'sort_order']);
            $table->index(['member_type', 'status']);
        });

        Schema::create('team_member_team_role', function (Blueprint $table) {
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['team_member_id', 'team_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_member_team_role');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('team_roles');
    }
};
