<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Access;

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
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

    /** @var (Closure(): bool)|null */
    private static ?Closure $manageAllFallback = null;

    /**
     * Decide "may manage" when the manage ability is not defined, for an app
     * whose permissions live somewhere a Gate ability does not reach.
     *
     * @param  (Closure(): bool)|null  $callback
     */
    public static function manageUsing(?Closure $callback): void
    {
        self::$manageFallback = $callback;
    }

    /**
     * Decide "may manage everything" (every folder, restricted or not) when the
     * manage-all ability is not defined. A host usually maps it to superusers.
     *
     * @param  (Closure(): bool)|null  $callback
     */
    public static function manageAllUsing(?Closure $callback): void
    {
        self::$manageAllFallback = $callback;
    }

    /**
     * Upload, replace, refocus, retag, delete.
     *
     * With no `$user` the signed-in user is asked. A fallback closure can only
     * speak for the signed-in user, so for any other user an undefined ability
     * is a refusal, never a guess.
     */
    public static function allowsManage(?Authenticatable $user = null): bool
    {
        return self::allowsAbility($user, MediaConfig::string('abilities.manage', 'media.manage'), self::$manageFallback);
    }

    /**
     * Sees and manages every folder, including members-only and password-protected
     * ones, without being a member or unlocking. Closed until defined, like
     * {@see allowsManage()}.
     */
    public static function allowsManageAll(?Authenticatable $user = null): bool
    {
        return self::allowsAbility($user, MediaConfig::string('abilities.manage_all', 'media.manage-all'), self::$manageAllFallback);
    }

    /** See the library and pick from it. */
    public static function allowsBrowse(?Authenticatable $user = null): bool
    {
        $user ??= self::currentUser();

        if (! $user instanceof Authenticatable) {
            return false;
        }

        $ability = MediaConfig::string('abilities.view', 'media.view');
        $gate = Gate::forUser($user);

        return ! $gate->has($ability) || $gate->allows($ability);
    }

    /** @param (Closure(): bool)|null $fallback */
    private static function allowsAbility(?Authenticatable $user, string $ability, ?Closure $fallback): bool
    {
        $current = self::currentUser();
        $user ??= $current;

        if (! $user instanceof Authenticatable) {
            return false;
        }

        $gate = Gate::forUser($user);

        // allows() first: a Gate::before() grant (how permission packages and
        // super-admin shortcuts work) counts even when the ability itself was
        // never define()d, which Gate::has() cannot see.
        if ($gate->allows($ability)) {
            return true;
        }

        if ($gate->has($ability)) {
            return false;
        }

        return $fallback !== null && self::isSame($user, $current) && $fallback() === true;
    }

    private static function currentUser(): ?Authenticatable
    {
        return auth()->user();
    }

    private static function isSame(Authenticatable $a, ?Authenticatable $b): bool
    {
        return $b instanceof Authenticatable
            && $a::class === $b::class
            && self::identifier($a) === self::identifier($b);
    }

    /** A user's id as a string, for comparing owners and members; '' when the identifier is not a scalar. */
    public static function identifier(Authenticatable $user): string
    {
        $id = $user->getAuthIdentifier();

        return is_int($id) || is_string($id) ? (string) $id : '';
    }

    /** The acting user's id as a string, for event attribution, null when unauthenticated. */
    public static function actorId(): ?string
    {
        $id = auth()->id();

        return is_int($id) || is_string($id) ? (string) $id : null;
    }
}
