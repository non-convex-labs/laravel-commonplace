<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commonplace_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained('commonplace_notes')->cascadeOnDelete();
            $table->foreignIdFor(config('commonplace.user_model'))->constrained()->cascadeOnDelete();
            $table->string('permission')->default('read');
            $table->timestamp('created_at')->nullable();
            $table->unique(['note_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commonplace_shares');
    }
};
