<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time links an admin issues and shares manually (the app sends no email).
     * Only a SHA-256 hash of the token is stored.
     */
    public function up(): void
    {
        Schema::create('user_access_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20); // setup | reset
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'used_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_links');
    }
};
