<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rate templates: a named set of per-person (and per-department fallback) hourly
 * rates. Applying a template to a client copies its rows into that client's own
 * rate cards, which can then be changed for that client only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('rate_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rate_minor');
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->timestamps();

            $table->unique(['rate_template_id', 'user_id', 'department_id'], 'rate_template_items_unique');
        });

        // Remember which template a client's rates came from (for display only).
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->foreignId('rate_template_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('rate_template_applied_at')->nullable();
        });

        Schema::table('rate_cards', function (Blueprint $table) {
            $table->foreignId('rate_template_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_template_id');
        });
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_template_id');
            $table->dropColumn('rate_template_applied_at');
        });
        Schema::dropIfExists('rate_template_items');
        Schema::dropIfExists('rate_templates');
    }
};
