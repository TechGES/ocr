<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_processings', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('mime_type');
            $table->string('path');
            $table->string('input_type')->nullable()->index();
            $table->string('document_type')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('pages_count')->nullable();
            $table->json('raw_classification_json')->nullable();
            $table->json('raw_extraction_json')->nullable();
            $table->json('normalized_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_processings');
    }
};
