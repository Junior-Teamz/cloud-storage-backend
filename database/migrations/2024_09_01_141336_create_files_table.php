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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->string('name');
            $table->string('nanoid');
            $table->string('path');
            $table->string('public_path');
            $table->string('image_url')->nullable();
            $table->unsignedBigInteger('size');
            $table->string('type');
            $table->foreignId('user_id')->index()->references('id')->on('users')->cascadeOnDelete();
            $table->foreignId('folder_id')->index()->nullable()->references('id')->on('folders')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
