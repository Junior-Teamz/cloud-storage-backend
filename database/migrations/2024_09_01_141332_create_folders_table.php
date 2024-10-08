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
        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nanoid');
            $table->string('name');
            $table->string('type');
            $table->string('public_path');
            $table->foreignUuid('user_id')->index()->references('id')->on('users')->cascadeOnDelete();
            $table->foreignUuid('parent_id')->index()->nullable()->references('id')->on('folders')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
