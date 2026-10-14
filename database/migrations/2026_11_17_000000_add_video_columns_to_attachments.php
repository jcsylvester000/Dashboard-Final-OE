<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-11..E-13: videos upload straight to storage in parts, then a media job reads
 * their details and makes a poster and a web-playable copy (proxy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('upload_state', 20)->nullable()->after('is_latest'); // uploading | null (complete)
            $table->string('upload_id')->nullable()->after('upload_state');    // storage multipart id
            $table->string('media_status', 20)->nullable()->after('upload_id'); // processing | ready | failed
            $table->json('media')->nullable()->after('media_status');          // duration_ms, width, height, fps, codecs
            $table->string('media_error', 500)->nullable()->after('media');
            $table->string('proxy_key')->nullable()->after('media_error');     // web-playable H.264/AAC copy
            $table->index(['workspace_id', 'disk']);
            $table->index(['upload_state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'disk']);
            $table->dropIndex(['upload_state', 'created_at']);
            $table->dropColumn(['upload_state', 'upload_id', 'media_status', 'media', 'media_error', 'proxy_key']);
        });
    }
};
