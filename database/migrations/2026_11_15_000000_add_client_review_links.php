<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement E-08: a client review link per file version (no login). The client's name is
 * kept on their pins / replies and on their approve / request-changes decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('review_token', 40)->nullable()->unique();
            $table->timestamp('review_token_expires_at')->nullable();
            $table->unsignedInteger('review_views')->default(0);
            $table->foreignId('review_link_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_decision_by', 80)->nullable();
        });
        Schema::table('annotations', function (Blueprint $table) {
            $table->string('client_name', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('annotations', fn (Blueprint $table) => $table->dropColumn('client_name'));
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_link_by');
            $table->dropColumn(['review_token', 'review_token_expires_at', 'review_views', 'client_decision_by']);
        });
    }
};
