<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 64)->unique();
            $table->date('receipt_date');
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('dismantling_order_id')->constrained('dismantling_orders')->restrictOnDelete();
            $table->string('warehouse_name');
            $table->foreignId('handed_over_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('received')->index();
            $table->text('discrepancy_explanation')->nullable();
            $table->foreignId('discrepancy_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discrepancy_decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'receipt_date']);
        });

        Schema::create('material_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_receipt_id')->constrained('material_receipts')->cascadeOnDelete();
            $table->foreignId('cable_segment_id')->nullable()->constrained('cable_segments')->nullOnDelete();
            $table->string('material_type', 32);
            $table->string('description')->nullable();
            $table->string('unit', 8);
            // Quantity handed over by the dismantling side vs. quantity accepted by the warehouse
            $table->decimal('handed_over_quantity', 14, 3);
            $table->decimal('accepted_quantity', 14, 3);
            $table->date('measured_on');
            $table->string('storage_location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_item_id')->constrained('material_items')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('type', 16)->index();
            // Signed quantity in the item's unit: + incoming, − outgoing
            $table->decimal('quantity', 14, 3);
            $table->string('status', 24)->default('posted')->index();
            $table->string('document_number', 100)->nullable();
            $table->date('document_date')->nullable();
            $table->string('counterparty')->nullable();
            $table->string('storage_location')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transactions');
        Schema::dropIfExists('material_items');
        Schema::dropIfExists('material_receipts');
    }
};
