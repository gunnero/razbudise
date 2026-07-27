<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authors', function (Blueprint $table): void {
            $table->string('sex', 16)->nullable()->after('avatar_id')->index();
        });

        Schema::table('team_members', function (Blueprint $table): void {
            $table->string('sex', 16)->nullable()->after('profile_image_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table): void {
            $table->dropIndex(['sex']);
            $table->dropColumn('sex');
        });

        Schema::table('authors', function (Blueprint $table): void {
            $table->dropIndex(['sex']);
            $table->dropColumn('sex');
        });
    }
};
