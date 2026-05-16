<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commonplace_note_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained('commonplace_notes')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('commonplace_tags')->cascadeOnDelete();
            $table->unique(['note_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commonplace_note_tag');
    }
};
