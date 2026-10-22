<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Delivery;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Exceptions\AccessDenied;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Support\Facades\URL;

/**
 * Chooses between the two ways a file address can be signed (SPEC-0103 §5.4).
 *
 *  - An ordinary asset gets a permanent signed address on a route with no
 *    session, so public pages and CDNs keep working.
 *  - An asset in a members-only or password folder gets a temporary signed
 *    address (`delivery.restricted_url_minutes`, ten by default) on a *different*
 *    route that also needs the visitor's session and re-checks their access
 *    when it is opened. The permanent route refuses restricted assets, so an old
 *    permanent address cannot outlive a move into a restricted folder.
 *
 * The address is only issued to a user who may browse the folder. Anyone else
 * gets {@see AccessDenied}: an exception rather than null so that a caller can
 * never print an empty `src` or `href` by accident. Views use
 * {@see MediaAsset::isDeliverable()} first and render a placeholder.
 */
final class SignedUrls
{
    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws AccessDenied
     */
    public static function make(MediaAsset $asset, string $publicRoute, string $restrictedRoute, array $parameters): string
    {
        $access = app(FolderAccess::class);

        if (! $access->isRestricted($asset)) {
            return URL::signedRoute($publicRoute, $parameters);
        }

        if (! $access->canBrowse($asset)) {
            throw AccessDenied::make();
        }

        return URL::temporarySignedRoute(
            $restrictedRoute,
            now()->addMinutes(max(1, MediaConfig::int('delivery.restricted_url_minutes', 10))),
            $parameters,
        );
    }
}
