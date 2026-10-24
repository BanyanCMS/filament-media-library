<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folders and sub-folders.
 *
 * `path` is the materialised list of ids from the top-level folder down to the
 * folder itself ("/12/45/"), so a whole subtree is one indexed prefix match.
 * `access`, `password_hash` and the members table exist now and are enforced by
 * a later phase; until then every folder is `inherit`, which reads as shared.
 * `site_id` lets a host tag a top-level folder with the site it belongs to; the
 * library never reads it beyond ordering, so it carries no foreign key.
 *
 * The unique (parent_id, name) index cannot protect top-level folders on every
 * database (NULLs are distinct), and is case-sensitive on some; MediaFolderService
 * compares names case-insensitively itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        $folders = MediaConfig::table('folders');
        $members = MediaConfig::table('folder_members');
        $assets = MediaConfig::table('assets');

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

        Schema::table($assets, function (Blueprint $table) use ($assets, $folders): void {
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->index('folder_id', $assets.'_folder_idx');
            $table->foreign('folder_id', $assets.'_folder_fk')->references('id')->on($folders)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $assets = MediaConfig::table('assets');

        Schema::table($assets, function (Blueprint $table) use ($assets): void {
            $table->dropForeign($assets.'_folder_fk');
        });

        Schema::table($assets, function (Blueprint $table) use ($assets): void {
            $table->dropIndex($assets.'_folder_idx');
        });

        Schema::table($assets, function (Blueprint $table): void {
            $table->dropColumn('folder_id');
        });

        Schema::dropIfExists(MediaConfig::table('folder_members'));
        Schema::dropIfExists(MediaConfig::table('folders'));
    }
};
