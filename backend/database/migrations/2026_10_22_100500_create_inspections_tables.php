<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('inspection_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained('inspections')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('full_name');
            $table->string('position')->nullable();
            $table->string('role', 16)->default('member');
            $table->timestamps();
        });

        Schema::create('inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained('inspections')->cascadeOnDelete();
            $table->foreignId('cable_segment_id')->constrained('cable_segments')->restrictOnDelete();
            $table->date('inspected_on')->nullable();
            // Book (accounting) length is snapshotted when the item is created
            $table->decimal('recorded_length_m', 12, 2)->nullable();
            $table->decimal('measured_length_m', 12, 2)->nullable();
            $table->decimal('length_difference_m', 12, 2)->nullable();
            $table->text('difference_reason')->nullable();
            $table->string('technical_condition', 32)->nullable();
            $table->string('usage_status', 32)->nullable();
            $table->text('usage_evidence')->nullable();
            $table->string('risk_level', 16)->nullable();
            $table->json('complexity_flags')->nullable();
            $table->string('laying_type', 32)->nullable();
            $table->decimal('depth_m', 5, 2)->nullable();
            $table->text('findings')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->unique(['inspection_id', 'cable_segment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_items');
        Schema::dropIfExists('inspection_members');
        Schema::dropIfExists('inspections');
    }
};
