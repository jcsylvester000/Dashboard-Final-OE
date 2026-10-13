<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team file drive ("My files"): each member's own files on UploadThing, with a
 * per-user quota, sharing (private / team / client workspace / public link) and
 * a lock that freezes a file (no delete, rename or sharing changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete(); // shared with this client's team
            $table->string('name');                               // sanitised display name
            $table->string('mime', 150);
            $table->unsignedBigInteger('size');                   // bytes (counts against the owner's quota)
            $table->string('storage', 20)->default('uploadthing');
            $table->string('storage_key', 300)->nullable();       // UploadThing file key
            $table->string('custom_id', 128)->unique();
            $table->string('status', 12)->default('pending');     // pending | ready
            $table->string('visibility', 12)->default('private'); // private | team | workspace | public
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('share_expires_at')->nullable();
            $table->unsignedInteger('share_downloads')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'status']);
            $table->index(['visibility', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_files');
    }
};
