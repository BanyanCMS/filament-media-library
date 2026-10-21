<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The library's five tables, named from `media-library.tables`.
 *
 * The row is the asset; the bytes are a version. A replacement appends a
 * version and moves `current_version_id`, so nothing an editor uploaded is ever
 * overwritten in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        $assets = MediaConfig::table('assets');
        $versions = MediaConfig::table('versions');
        $tags = MediaConfig::table('tags');

        Schema::create($assets, function (Blueprint $table) use ($assets): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('filename', 255);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 512);
            // Fractions of width and height, 0..1: the point every crop keeps.
            $table->decimal('focal_x', 6, 5)->default(0.5);
            $table->decimal('focal_y', 6, 5)->default(0.5);
            $table->timestamp('license_expires_at')->nullable();
            // No foreign key: versions already point here, and a cycle would
            // make the first insert impossible.
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('created_at', $assets.'_created_idx');
            $table->index('license_expires_at', $assets.'_expiry_idx');
        });

        Schema::create($versions, function (Blueprint $table) use ($versions, $assets): void {
            $table->id();
            $table->unsignedBigInteger('media_asset_id');
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('checksum', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['media_asset_id', 'id'], $versions.'_asset_idx');
            $table->foreign('media_asset_id', $versions.'_asset_fk')->references('id')->on($assets)->cascadeOnDelete();
        });

        Schema::create($tags, function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->timestamps();
        });

        $pivot = MediaConfig::table('asset_tag');

        Schema::create($pivot, function (Blueprint $table) use ($pivot, $assets, $tags): void {
            $table->unsignedBigInteger('media_asset_id');
            $table->unsignedBigInteger('media_tag_id');

            $table->primary(['media_asset_id', 'media_tag_id']);
            $table->index('media_tag_id', $pivot.'_tag_idx');
            $table->foreign('media_asset_id', $pivot.'_asset_fk')->references('id')->on($assets)->cascadeOnDelete();
            $table->foreign('media_tag_id', $pivot.'_tag_fk')->references('id')->on($tags)->cascadeOnDelete();
        });

        $usages = MediaConfig::table('usages');

        Schema::create($usages, function (Blueprint $table) use ($usages, $assets): void {
            $table->id();
            $table->unsignedBigInteger('media_asset_id');
            $table->string('usable_type');
            $table->string('usable_id', 64);
            $table->string('field', 128);
            $table->string('locale', 16)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('media_asset_id', $usages.'_asset_idx');
            $table->index(['usable_type', 'usable_id'], $usages.'_usable_idx');
            $table->foreign('media_asset_id', $usages.'_asset_fk')->references('id')->on($assets)->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MediaConfig::table('usages'));
        Schema::dropIfExists(MediaConfig::table('asset_tag'));
        Schema::dropIfExists(MediaConfig::table('tags'));
        Schema::dropIfExists(MediaConfig::table('versions'));
        Schema::dropIfExists(MediaConfig::table('assets'));
    }
};
