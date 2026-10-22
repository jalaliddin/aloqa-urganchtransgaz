<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cable_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cable_line_id')->constrained('cable_lines')->restrictOnDelete();
            // Denormalised from the line for organisation scoping and map queries
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('code', 48)->unique();
            $table->string('name')->nullable();
            $table->string('start_point')->nullable();
            $table->string('end_point')->nullable();
            $table->string('location_description')->nullable();
            $table->string('laying_type', 32)->default('underground');
            $table->decimal('depth_m', 5, 2)->nullable();

            $table->decimal('design_length_m', 12, 2)->nullable();
            $table->decimal('measured_length_m', 12, 2)->nullable();

            // GeoJSON LineString / MultiLineString in WGS84 (lng, lat) + derived values
            $table->json('geometry')->nullable();
            $table->decimal('geometry_length_m', 12, 2)->nullable();
            $table->decimal('bbox_min_lat', 10, 7)->nullable();
            $table->decimal('bbox_min_lng', 10, 7)->nullable();
            $table->decimal('bbox_max_lat', 10, 7)->nullable();
            $table->decimal('bbox_max_lng', 10, 7)->nullable();
            $table->string('geometry_source', 16)->nullable();
            $table->string('geometry_source_note')->nullable();
            $table->timestamp('geometry_updated_at')->nullable();

            $table->string('technical_condition', 32)->default('unknown');
            $table->string('usage_status', 32)->default('unknown');
            $table->string('risk_level', 16)->default('low');
            $table->json('complexity_flags')->nullable();
            $table->string('inspection_status', 32)->default('not_inspected');
            $table->string('dismantling_status', 32)->default('not_planned');

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cable_line_id', 'sequence']);
            $table->index(['organization_id', 'dismantling_status']);
            $table->index(['bbox_min_lat', 'bbox_max_lat', 'bbox_min_lng', 'bbox_max_lng'], 'cable_segments_bbox_index');
            $table->index('inspection_status');
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cable_segments');
    }
};
