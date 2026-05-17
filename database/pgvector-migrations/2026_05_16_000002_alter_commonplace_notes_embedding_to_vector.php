<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::table('commonplace_notes', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });

        $dimensions = app(EmbeddingProvider::class)->dimensions();

        Schema::table('commonplace_notes', function (Blueprint $table) use ($dimensions) {
            $table->vector('embedding', $dimensions)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commonplace_notes', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });

        Schema::table('commonplace_notes', function (Blueprint $table) {
            $table->longText('embedding')->nullable();
        });
    }
};
