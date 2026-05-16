<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commonplace_note_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->nullable()->constrained('commonplace_notes')->nullOnDelete();
            $table->string('note_path');
            $table->longText('content');
            $table->string('content_hash');
            $table->foreignIdFor(config('commonplace.user_model'), 'changed_by')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commonplace_note_versions');
    }
};
