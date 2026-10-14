<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-04 / E-05: pinned comments and drawings on a file version. Positions and shapes are
 * stored as fractions of the image (0..1) so they land on the same spot at any zoom or
 * screen size. The file itself is never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('annotations')->cascadeOnDelete(); // replies
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('number')->nullable();   // pin number per file (threads only)
            $table->decimal('x', 6, 5)->nullable();                // 0..1 of the image width
            $table->decimal('y', 6, 5)->nullable();                // 0..1 of the image height
            $table->json('shapes')->nullable();                    // vector drawing, same 0..1 space
            $table->string('color', 9)->nullable();
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attachment_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
