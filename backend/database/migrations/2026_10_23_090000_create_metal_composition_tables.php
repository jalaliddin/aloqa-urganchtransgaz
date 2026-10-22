<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-ferrous metal content of cables (aluminium, lead, copper in kg per metre).
 *
 * - cable_types: brand / type dictionary that lines refer to.
 * - cable_metal_compositions: versioned kg/m values for a cable type, or an approved
 *   override for a specific line or segment. NULL = unknown, 0 = verified zero.
 * - metal_mass_calculations: snapshots of theoretical masses, so earlier results stay
 *   traceable when a length or a composition changes.
 * - dismantling_orders.act_*_kg: masses actually recovered according to the dismantling act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cable_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('brand', 100)->nullable();
            $table->unsignedSmallInteger('core_count')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cable_metal_compositions', function (Blueprint $table) {
            $table->id();
            // cable_type | cable_line | cable_segment
            $table->morphs('scope');
            // kg per metre; at least 4 decimals as required, 6 kept for normative tables
            $table->decimal('aluminium_kg_per_m', 12, 6)->nullable();
            $table->decimal('lead_kg_per_m', 12, 6)->nullable();
            $table->decimal('copper_kg_per_m', 12, 6)->nullable();
            $table->string('source_type', 32);
            $table->string('source_reference');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('note')->nullable();
            // pending → approved | rejected; approved → superseded by a newer approved version
            $table->string('status', 16)->index();
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('entered_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('cable_metal_compositions')->nullOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'status'], 'cmc_scope_status_index');
        });

        Schema::table('cable_lines', function (Blueprint $table) {
            $table->foreignId('cable_type_id')->nullable()->after('cable_type')->constrained('cable_types')->nullOnDelete();
        });

        Schema::table('dismantling_orders', function (Blueprint $table) {
            // Masses recovered according to the dismantling act — never derived, never overwritten by theory
            $table->decimal('act_aluminium_kg', 16, 3)->nullable()->after('actual_length_m');
            $table->decimal('act_lead_kg', 16, 3)->nullable()->after('act_aluminium_kg');
            $table->decimal('act_copper_kg', 16, 3)->nullable()->after('act_lead_kg');
            $table->foreignId('act_masses_document_id')->nullable()->after('act_copper_kg')->constrained('documents')->nullOnDelete();
            $table->foreignId('act_masses_recorded_by')->nullable()->after('act_masses_document_id')->constrained('users')->nullOnDelete();
            $table->timestamp('act_masses_recorded_at')->nullable()->after('act_masses_recorded_by');
        });

        Schema::create('metal_mass_calculations', function (Blueprint $table) {
            $table->id();
            // cable_segment | dismantling_order
            $table->morphs('subject');
            $table->foreignId('composition_id')->nullable()->constrained('cable_metal_compositions')->nullOnDelete();
            $table->string('trigger', 32);
            $table->decimal('length_m', 12, 2)->nullable();
            $table->decimal('aluminium_kg', 16, 3)->nullable();
            $table->decimal('lead_kg', 16, 3)->nullable();
            $table->decimal('copper_kg', 16, 3)->nullable();
            $table->boolean('is_complete')->default(false);
            $table->json('details')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metal_mass_calculations');

        Schema::table('dismantling_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('act_masses_document_id');
            $table->dropConstrainedForeignId('act_masses_recorded_by');
            $table->dropColumn(['act_aluminium_kg', 'act_lead_kg', 'act_copper_kg', 'act_masses_recorded_at']);
        });

        Schema::table('cable_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cable_type_id');
        });

        Schema::dropIfExists('cable_metal_compositions');
        Schema::dropIfExists('cable_types');
    }
};
