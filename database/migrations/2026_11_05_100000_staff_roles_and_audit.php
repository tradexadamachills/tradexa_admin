<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique();
            $table->string('role')->default('auditor');
            $table->boolean('is_active')->default(false);
        });
        Schema::create('staff_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('actor_id')->nullable();
            $table->string('action');
            $table->uuid('subject_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_audit_logs');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['public_id', 'role', 'is_active']));
    }
};
