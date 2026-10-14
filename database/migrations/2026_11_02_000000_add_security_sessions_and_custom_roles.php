<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E7a (admin & security):
 *  - E-87 two-factor deadline per user (grace period before it is enforced)
 *  - E-88 signed-in devices per user (works with Redis sessions; revocable one by one)
 *  - E-89 labels for custom roles, and the new split permissions (seeded here so a
 *    deploy needs no extra step)
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = (string) config('permission.table_names.roles', 'roles');
        Schema::table($roles, function (Blueprint $table) {
            $table->string('label', 60)->nullable();
            $table->string('description', 255)->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('two_factor_deadline')->nullable();
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 128)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            $table->index(['user_id', 'last_seen_at']);
        });

        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('two_factor_deadline'));
        Schema::table((string) config('permission.table_names.roles', 'roles'), fn (Blueprint $table) => $table->dropColumn(['label', 'description']));
    }
};
