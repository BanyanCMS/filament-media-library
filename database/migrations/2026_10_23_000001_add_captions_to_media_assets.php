<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A video's captions file and a video's or audio file's transcript.
 *
 * `captions_asset_id` points at another asset (a WebVTT document) and is set to
 * null when that asset is deleted: a video must not be left pointing at a
 * file that no longer exists, and losing captions must not block a delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        $assets = MediaConfig::table('assets');

        Schema::table($assets, function (Blueprint $table) use ($assets): void {
            $table->unsignedBigInteger('captions_asset_id')->nullable();
            $table->text('transcript')->nullable();
            $table->foreign('captions_asset_id', $assets.'_captions_fk')->references('id')->on($assets)->nullOnDelete();
        });
    }

    public function down(): void
    {
        $assets = MediaConfig::table('assets');

        Schema::table($assets, function (Blueprint $table) use ($assets): void {
            $table->dropForeign($assets.'_captions_fk');
        });

        Schema::table($assets, function (Blueprint $table): void {
            $table->dropColumn(['captions_asset_id', 'transcript']);
        });
    }
};
