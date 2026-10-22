<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->morphs('documentable');
            $table->string('type', 48)->index();
            $table->string('title');
            $table->string('number', 100)->nullable();
            $table->date('document_date')->nullable();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            // Documents are never deleted — they are voided with a reason
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
