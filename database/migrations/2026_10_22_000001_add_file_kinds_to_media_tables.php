<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The library holds files, not only images.
 *
 * `kind` defaults to `image`, so every existing row reads as the image it
 * always was. `alt_text` becomes nullable because a PDF has none; the rule
 * that an image needs one lives in MediaRepository, as it already did.
 */
return new class extends Migration
{
    public function up(): void
    {
        $assets = MediaConfig::table('assets');

        Schema::table($assets, function (Blueprint $table) use ($assets): void {
            $table->string('kind', 16)->default('image');
            $table->string('title', 255)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->index('kind', $assets.'_kind_idx');
        });

        Schema::table($assets, function (Blueprint $table): void {
            $table->string('alt_text', 512)->nullable()->change();
        });

        Schema::table(MediaConfig::table('versions'), function (Blueprint $table): void {
            $table->string('kind', 16)->default('image');
        });
    }

    public function down(): void
    {
        $assets = MediaConfig::table('assets');

        Schema::table(MediaConfig::table('versions'), function (Blueprint $table): void {
            $table->dropColumn('kind');
        });

        // Non-images have no alt text; restoring NOT NULL needs a value.
        DB::table($assets)->whereNull('alt_text')->update(['alt_text' => '']);

        Schema::table($assets, function (Blueprint $table): void {
            $table->string('alt_text', 512)->nullable(false)->change();
        });

        Schema::table($assets, function (Blueprint $table) use ($assets): void {
            $table->dropIndex($assets.'_kind_idx');
        });

        Schema::table($assets, function (Blueprint $table): void {
            $table->dropColumn(['kind', 'title', 'duration_seconds', 'page_count']);
        });
    }
};
