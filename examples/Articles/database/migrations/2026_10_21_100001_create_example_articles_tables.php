<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('example_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            // A MediaPicker stores ids only: one id here, a list of ids below.
            $table->unsignedBigInteger('hero_id')->nullable();
            $table->json('gallery')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->timestamps();
        });

        // The audit trail the MediaEvent listener writes. References only: no alt text, no filename.
        Schema::create('example_media_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 64);
            $table->string('actor_id', 64);
            $table->unsignedBigInteger('asset_id');
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('example_media_audit');
        Schema::dropIfExists('example_articles');
    }
};
