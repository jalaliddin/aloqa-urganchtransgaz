<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dismantling_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('cable_line_id')->constrained('cable_lines')->restrictOnDelete();
            $table->string('work_plan_number', 64)->nullable();
            $table->string('order_number', 64)->nullable();
            $table->date('order_date')->nullable();
            $table->string('brigade_name')->nullable();
            $table->foreignId('work_supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('work_supervisor_name')->nullable();
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->decimal('planned_length_m', 12, 2)->default(0);
            $table->decimal('actual_length_m', 12, 2)->default(0);
            $table->string('status', 32)->default('draft');
            $table->boolean('is_complex')->default(false);
            $table->text('suspension_reason')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('actual_end');
        });

        Schema::create('dismantling_order_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dismantling_order_id')->constrained('dismantling_orders')->cascadeOnDelete();
            $table->foreignId('cable_segment_id')->constrained('cable_segments')->restrictOnDelete();
            $table->decimal('planned_length_m', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['dismantling_order_id', 'cable_segment_id'], 'dos_order_segment_unique');
        });

        Schema::create('dismantling_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dismantling_order_id')->constrained('dismantling_orders')->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->dateTime('occurred_at');
            $table->foreignId('cable_segment_id')->nullable()->constrained('cable_segments')->nullOnDelete();
            $table->decimal('extracted_length_m', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dismantling_events');
        Schema::dropIfExists('dismantling_order_segments');
        Schema::dropIfExists('dismantling_orders');
    }
};
