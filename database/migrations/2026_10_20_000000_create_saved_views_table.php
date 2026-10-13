<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved views (brutal scenario M1): a named set of task filters + grouping on the
 * list or board, kept for yourself or shared with everyone on the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('page', 10)->default('list'); // list | board
            $table->json('filters');
            $table->boolean('shared')->default(false);
            $table->timestamps();

            $table->index(['workspace_id', 'shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
