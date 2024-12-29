<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->index()->references('id')->on('users')->cascadeOnDelete();
            $table->string('thumbnail_path')->nullable();
            $table->string('thumbnail_url');
            $table->string('slug');
            $table->string('title');
            $table->text('content');
            $table->integer('viewer');
            $table->enum('status', ['published', 'archived'])->default('archived');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
