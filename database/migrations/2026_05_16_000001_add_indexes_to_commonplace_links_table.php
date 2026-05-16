<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commonplace_links', function (Blueprint $table) {
            $table->index('source_note_id', 'commonplace_links_source_note_id_index');
            $table->index('target_note_id', 'commonplace_links_target_note_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('commonplace_links', function (Blueprint $table) {
            $table->dropIndex('commonplace_links_source_note_id_index');
            $table->dropIndex('commonplace_links_target_note_id_index');
        });
    }
};
