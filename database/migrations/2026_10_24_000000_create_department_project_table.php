<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finding S3-02: choose the departments involved when creating a project
 * (the team used to appear only through tasks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_project', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_project');
    }
};
