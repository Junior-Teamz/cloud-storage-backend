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
        Schema::create('folder_has_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->references('id')->on('folders')->cascadeOnDelete();
            $table->foreignId('instance_id')->nullable()->references('id')->on('instances')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('folder_has_instances');
    }
};