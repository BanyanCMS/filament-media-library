<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Access;

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Closure;
use Illuminate\Support\Facades\Gate;

/**
 * Who may browse the library, and who may change it.
 *
 * Two levels, because they are two different risks. Picking an existing asset
 * into a form is an ordinary edit; uploading, replacing and deleting change a
 * shared resource other people's pages depend on.
 *
 * Both are Laravel Gate abilities (`media-library.abilities.*`). When the app
 * has not defined one, viewing needs a signed-in user and managing falls back
 * to {@see manageUsing()}, which by default refuses: an undefined permission
 * that defaults open is how a governance surface silently stops governing.
 */
final class MediaLibraryGate
{
    /** @var (Closure(): bool)|null */
    private static ?Closure $manageFallback = null;

    /**
     * Decide "may manage" when the manage ability is not defined — for an app
     * whose permissions live somewhere a Gate ability does not reach.
     *
     * @param  (Closure(): bool)|null  $callback
     */
    public static function manageUsing(?Closure $callback): void
    {
        self::$manageFallback = $callback;
    }

    /** Upload, replace, refocus, retag, delete. */
    public static function allowsManage(): bool
    {
        if (auth()->user() === null) {
            return false;
        }

        $ability = MediaConfig::string('abilities.manage', 'media.manage');

        // allows() first: a Gate::before() grant (how permission packages and
        // super-admin shortcuts work) counts even when the ability itself was
        // never define()d, which Gate::has() cannot see.
        if (Gate::allows($ability)) {
            return true;
        }

        if (Gate::has($ability)) {
            return false;
        }

        return self::$manageFallback !== null && (self::$manageFallback)() === true;
    }

    /** See the library and pick from it. */
    public static function allowsBrowse(): bool
    {
        if (auth()->user() === null) {
            return false;
        }

        $ability = MediaConfig::string('abilities.view', 'media.view');

        return ! Gate::has($ability) || Gate::allows($ability);
    }

    /** The acting user's id as a string, for event attribution — null when unauthenticated. */
    public static function actorId(): ?string
    {
        $id = auth()->id();

        return is_int($id) || is_string($id) ? (string) $id : null;
    }
}
