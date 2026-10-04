<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('title')->nullable()->after('email');
            $table->foreignId('primary_department_id')->nullable()->after('title')
                ->constrained('departments')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index()->after('password');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
            $table->timestamp('deactivated_at')->nullable()->after('last_login_at');
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['department_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_department_id');
            $table->dropIndex(['is_active']);
            $table->dropColumn(['title', 'is_active', 'must_change_password', 'last_login_at', 'deactivated_at']);
        });
    }
};
