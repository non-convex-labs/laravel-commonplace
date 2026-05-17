<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commonplace_notes', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('title');
            $table->longText('content');
            $table->string('content_hash');
            $table->string('visibility')->default('private');
            $table->timestamp('indexed_at')->nullable();
            $table->foreignIdFor(config('commonplace.user_model'))->constrained()->cascadeOnDelete();
            $table->longText('embedding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commonplace_notes');
    }
};
