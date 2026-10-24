<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The library's seven tables, named from `media-library.tables`.
 *
 * The row is the asset; the bytes are a version. A replacement appends a
 * version and moves `current_version_id`, so nothing an editor uploaded is ever
 * overwritten in place.
 *
 * `kind` defaults to `image`. `alt_text` is nullable because a PDF has none;
 * the rule that an image needs one lives in MediaRepository.
 *
 * `captions_asset_id` points at another asset (a WebVTT document) and is set to
 * null when that asset is deleted: a video must not be left pointing at a file
 * that no longer exists, and losing captions must not block a delete.
 *
 * Folder `path` is the materialised list of ids from the top-level folder down
 * to the folder itself ("/12/45/"), so a whole subtree is one indexed prefix
 * match. `site_id` lets a host tag a top-level folder with the site it belongs
 * to; the library never reads it beyond ordering, so it carries no foreign key.
 *
 * The unique (parent_id, name) index cannot protect top-level folders on every
 * database (NULLs are distinct), and is case-sensitive on some; MediaFolderService
 * compares names case-insensitively itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        $assets = MediaConfig::table('assets');
        $versions = MediaConfig::table('versions');
        $tags = MediaConfig::table('tags');
        $pivot = MediaConfig::table('asset_tag');
        $usages = MediaConfig::table('usages');
        $folders = MediaConfig::table('folders');
        $members = MediaConfig::table('folder_members');

        Schema::create($folders, function (Blueprint $table) use ($folders): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 120);
            $table->string('path', 1024);
            $table->unsignedTinyInteger('depth')->default(1);
            $table->string('access', 16)->default('inherit');
            $table->string('password_hash', 255)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->timestamps();

            $table->unique(['parent_id', 'name'], $folders.'_parent_name_uq');
            $table->index('site_id', $folders.'_site_idx');
            $table->foreign('parent_id', $folders.'_parent_fk')->references('id')->on($folders)->restrictOnDelete();
        });

        // A full-length index on 1024 utf8mb4 characters exceeds InnoDB's key
        // limit, so MySQL gets a prefix index; a subtree match is a prefix match.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(sprintf('create index `%s_path_idx` on `%s` (`path`(255))', $folders, $folders));
        } else {
            Schema::table($folders, function (Blueprint $table) use ($folders): void {
                $table->index('path', $folders.'_path_idx');
            });
        }

        Schema::create($members, function (Blueprint $table) use ($members, $folders): void {
            $table->unsignedBigInteger('media_folder_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 16)->default('view');

            $table->primary(['media_folder_id', 'user_id']);
            $table->foreign('media_folder_id', $members.'_folder_fk')->references('id')->on($folders)->cascadeOnDelete();
        });

        Schema::create($assets, function (Blueprint $table) use ($assets, $folders): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind', 16)->default('image');
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('filename', 255);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->string('title', 255)->nullable();
            $table->string('alt_text', 512)->nullable();
            // Fractions of width and height, 0..1: the point every crop keeps.
            $table->decimal('focal_x', 6, 5)->default(0.5);
            $table->decimal('focal_y', 6, 5)->default(0.5);
            $table->timestamp('license_expires_at')->nullable();
            // No foreign key: versions already point here, and a cycle would
            // make the first insert impossible.
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->unsignedBigInteger('captions_asset_id')->nullable();
            $table->text('transcript')->nullable();
            $table->timestamps();

            $table->index('created_at', $assets.'_created_idx');
            $table->index('license_expires_at', $assets.'_expiry_idx');
            $table->index('kind', $assets.'_kind_idx');
            $table->index('folder_id', $assets.'_folder_idx');
            $table->foreign('folder_id', $assets.'_folder_fk')->references('id')->on($folders)->restrictOnDelete();
            $table->foreign('captions_asset_id', $assets.'_captions_fk')->references('id')->on($assets)->nullOnDelete();
        });

        Schema::create($versions, function (Blueprint $table) use ($versions, $assets): void {
            $table->id();
            $table->unsignedBigInteger('media_asset_id');
            $table->string('kind', 16)->default('image');
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

        Schema::create($pivot, function (Blueprint $table) use ($pivot, $assets, $tags): void {
            $table->unsignedBigInteger('media_asset_id');
            $table->unsignedBigInteger('media_tag_id');

            $table->primary(['media_asset_id', 'media_tag_id']);
            $table->index('media_tag_id', $pivot.'_tag_idx');
            $table->foreign('media_asset_id', $pivot.'_asset_fk')->references('id')->on($assets)->cascadeOnDelete();
            $table->foreign('media_tag_id', $pivot.'_tag_fk')->references('id')->on($tags)->cascadeOnDelete();
        });

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
        // Children first: assets point at folders, and the rest point at assets.
        Schema::dropIfExists(MediaConfig::table('usages'));
        Schema::dropIfExists(MediaConfig::table('asset_tag'));
        Schema::dropIfExists(MediaConfig::table('tags'));
        Schema::dropIfExists(MediaConfig::table('versions'));
        Schema::dropIfExists(MediaConfig::table('assets'));
        Schema::dropIfExists(MediaConfig::table('folder_members'));
        Schema::dropIfExists(MediaConfig::table('folders'));
    }
};
