<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cable_lines', function (Blueprint $table) {
            $table->id();
            $table->string('registry_number', 32)->unique();
            $table->string('cable_code', 64)->nullable()->unique();
            $table->string('name');
            $table->string('cable_type')->nullable();
            $table->string('brand', 100)->nullable();
            $table->unsignedSmallInteger('core_count')->nullable();
            $table->unsignedSmallInteger('laid_year')->nullable()->index();
            $table->string('laid_year_precision', 16)->default('unknown');

            $table->foreignId('balance_holder_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('gas_pipeline_asset_id')->nullable()->constrained('gas_pipeline_assets')->nullOnDelete();
            $table->string('object_name')->nullable();
            $table->string('start_point')->nullable();
            $table->string('end_point')->nullable();

            // Lengths are stored in metres
            $table->decimal('design_length_m', 12, 2)->nullable();
            $table->decimal('measured_length_m', 12, 2)->nullable();

            // Financial fields (visible with cable_lines.view_financial only)
            $table->string('inventory_number', 64)->nullable()->index();
            $table->decimal('initial_cost', 18, 2)->nullable();
            $table->decimal('accumulated_depreciation', 18, 2)->nullable();
            $table->decimal('residual_value', 18, 2)->nullable();

            // Aggregated from segments (see CableLineAggregator) — read-only via API
            $table->string('technical_condition', 32)->default('unknown');
            $table->string('usage_status', 32)->default('unknown');
            $table->string('risk_level', 16)->default('low');
            $table->string('inspection_status', 32)->default('not_inspected');
            $table->string('dismantling_status', 32)->default('not_planned');
            $table->boolean('has_geometry')->default(false);
            $table->boolean('is_complex')->default(false);

            $table->text('notes')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'dismantling_status']);
            $table->index(['organization_id', 'usage_status']);
            $table->index(['organization_id', 'risk_level']);
            $table->index('inspection_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cable_lines');
    }
};
