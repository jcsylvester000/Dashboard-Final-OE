<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in is email + password only (owner decision 2026-10-14): two-factor and passkeys
 * are removed, so their stored secrets go too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('passkeys');

        $drop = array_values(array_filter(
            ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_deadline'],
            fn (string $c) => Schema::hasColumn('users', $c),
        ));
        if ($drop !== []) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn($drop));
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('two_factor_deadline')->nullable();
        });
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }
};
