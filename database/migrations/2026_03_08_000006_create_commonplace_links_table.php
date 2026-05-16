<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commonplace_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_note_id')->constrained('commonplace_notes')->cascadeOnDelete();
            $table->string('target_path');
            $table->foreignId('target_note_id')->nullable()->constrained('commonplace_notes')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commonplace_links');
    }
};
