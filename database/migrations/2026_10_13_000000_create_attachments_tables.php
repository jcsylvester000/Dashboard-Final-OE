<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 files: attachments on tasks, projects, comments and workspaces, plus a tiny
 * key/value table for admin-editable app settings (file size limit, allowed types).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->morphs('attachable');                 // task | project | comment | workspace
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 40);                     // FileStore driver name
            $table->string('key');                          // storage key (never the original name)
            $table->string('original_name');                // sanitised display name
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');             // bytes
            $table->string('thumbnail_key')->nullable();    // WebP preview for images
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('attachments');
    }
};
