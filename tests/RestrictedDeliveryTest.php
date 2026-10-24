<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Delivery\FileDelivery;
use Banyan\FilamentMediaLibrary\Delivery\SignedUrls;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Exceptions\AccessDenied;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Http\RestrictedFileController;
use Banyan\FilamentMediaLibrary\Http\RestrictedRenditionController;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Tables\MediaThumbnailColumn;
use Banyan\FilamentMediaLibrary\Tests\Support\AccessWorld as W;
use Banyan\FilamentMediaLibrary\Tests\Support\Files;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;

covers(SignedUrls::class, RestrictedFileController::class, RestrictedRenditionController::class, FileDelivery::class);

/** A PDF in a members folder whose only member is returned alongside it. */
function restrictedPdf(string $title = 'Payroll run'): array
{
    $owner = W::user('Owner');
    $member = W::user('Member');
    $folder = W::restricted('Payroll', FolderAccessMode::Members, $owner);
    W::member($folder, $member);
    $asset = app(MediaGovernanceService::class)->upload(Files::pdf(), 'payroll.pdf', '', '1', null, $title);
    app(MediaFolderService::class)->moveAssets([$asset], $folder, '1');

    return [$asset->refresh(), $member, $folder, $owner];
}

function queryOf(string $url, string $key): ?string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return isset($query[$key]) && is_string($query[$key]) ? $query[$key] : null;
}

// ---------------------------------------------------------------- shared files stay public

it('keeps a shared file on a permanent signed address that anyone can open, without a session', function (): void {
    $asset = W::asset('plain');
    $url = $asset->url(200);
    $pdf = app(MediaGovernanceService::class)->upload(Files::pdf(), 'open.pdf', '', '1', null, 'Open report');

    expect($url)->toContain('/render')->not->toContain('expires=')->not->toContain('restricted')
        ->and($pdf->fileUrl())->not->toContain('expires=')->not->toContain('restricted');

    $response = $this->get($pdf->fileUrl())->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('immutable')->toContain('public')
        ->and($response->headers->getCookies())->toBe([]);

    $image = $this->get($url)->assertOk();

    expect($image->headers->get('Cache-Control'))->toContain('immutable')
        ->and($image->headers->getCookies())->toBe([]);
});

it('keeps a file in an unprotected folder public too', function (): void {
    $asset = W::asset('in-open-folder', W::folder('Open'));

    $this->get($asset->url(100))->assertOk();
    expect($asset->url(100))->not->toContain('expires=');
});

// ---------------------------------------------------------------- restricted addresses

it('issues a ten-minute signed address for a restricted file, only to someone who may browse it', function (): void {
    [$asset, $member, , $owner] = restrictedPdf();
    $this->actingAs($member);

    $url = $asset->fileUrl();
    $download = $asset->downloadUrl();

    expect($url)->toContain('/restricted-file')->toContain('signature=')
        ->and((int) queryOf($url, 'expires'))->toBe(now()->addMinutes(10)->getTimestamp())
        ->and($download)->toContain('download=1')->toContain('expires=');

    $this->actingAs(W::user('Outsider'));
    expect(fn () => $asset->fileUrl())->toThrow(AccessDenied::class)
        ->and(fn () => $asset->downloadUrl())->toThrow(AccessDenied::class)
        ->and($asset->isDeliverable())->toBeFalse();

    $this->actingAs($owner);
    expect($asset->fileUrl())->toContain('/restricted-file');
});

it('issues nothing to a visitor who is not signed in', function (): void {
    [$asset] = restrictedPdf();

    expect(fn () => $asset->fileUrl())->toThrow(AccessDenied::class)
        ->and(fn () => $asset->url(100))->toThrow(AccessDenied::class)
        ->and($asset->isDeliverable())->toBeFalse();
});

it('serves the file to the member, privately and never to a shared cache', function (): void {
    [$asset, $member] = restrictedPdf();
    $this->actingAs($member);

    $response = $this->get($asset->fileUrl())->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')->not->toContain('immutable')
        ->and($response->streamedContent())->toBe(Files::pdf());
});

it('refuses a leaked address to a visitor who is not signed in', function (): void {
    [$asset, $member] = restrictedPdf();
    $this->actingAs($member);
    $url = $asset->fileUrl();
    auth()->logout();
    $this->app['auth']->forgetGuards();

    $this->get($url)->assertNotFound();
});

it('refuses a leaked address to a signed-in user who is not a member', function (): void {
    [$asset, $member] = restrictedPdf();
    $this->actingAs($member);
    $url = $asset->fileUrl();

    $this->actingAs(W::user('Outsider'))->get($url)->assertNotFound();
});

it('stops working the moment membership is removed', function (): void {
    [$asset, $member, $folder] = restrictedPdf();
    $this->actingAs($member);
    $url = $asset->fileUrl();

    $this->get($url)->assertOk();

    app(MediaFolderService::class)->removeMember($folder, $member->id, '1');

    $this->get($url)->assertNotFound();
});

it('stops working after ten minutes, even for a member', function (): void {
    [$asset, $member] = restrictedPdf();
    $this->actingAs($member);
    $url = $asset->fileUrl();

    $this->travel(9)->minutes();
    $this->get($url)->assertOk();

    $this->travel(2)->minutes();
    $this->get($url)->assertForbidden();
});

it('honours a configured lifetime', function (): void {
    config()->set('media-library.delivery.restricted_url_minutes', 2);
    [$asset, $member] = restrictedPdf();
    $this->actingAs($member);
    $url = $asset->fileUrl();

    expect((int) queryOf($url, 'expires'))->toBe(now()->addMinutes(2)->getTimestamp());

    $this->travel(3)->minutes();
    $this->get($url)->assertForbidden();
});

it('refuses a tampered address', function (): void {
    [$asset, $member] = restrictedPdf();
    $this->actingAs($member);
    $url = $asset->fileUrl();

    $this->get($url.'&download=1')->assertForbidden();
    $this->get(preg_replace('/expires=\d+/', 'expires='.(now()->getTimestamp() + 86400), $url) ?? '')->assertForbidden();
});

it('refuses a restricted file on the public route, even with a valid permanent signature', function (): void {
    [$asset, $member, $folder] = restrictedPdf();
    $publicRoute = fn (): string => URL::signedRoute('media-library.file', ['uuid' => $asset->uuid, 'v' => $asset->current_version_id]);

    // The very same file, signed for the public route, as an old page might still hold it.
    $this->get($publicRoute())->assertNotFound();
    $this->actingAs($member)->get($publicRoute())->assertNotFound();
});

it('closes an old permanent address when the file is moved into a restricted folder', function (): void {
    $pdf = app(MediaGovernanceService::class)->upload(Files::pdf(), 'was-public.pdf', '', '1', null, 'Was public');
    $image = W::asset('was-public-image');
    $old = [$pdf->fileUrl(), $image->url(100)];

    foreach ($old as $url) {
        $this->get($url)->assertOk();
    }

    $folder = W::restricted('Now private', FolderAccessMode::Members);
    app(MediaFolderService::class)->moveAssets([$pdf, $image], $folder, '1');

    foreach ($old as $url) {
        $this->get($url)->assertNotFound();
    }
});

it('serves restricted renditions the same way: temporary, private, session-checked', function (): void {
    $owner = W::user('Owner');
    $member = W::user('Member');
    $folder = W::restricted('Photos', FolderAccessMode::Members, $owner);
    W::member($folder, $member);
    $asset = W::asset('private-photo', $folder);

    $this->actingAs($member);
    $url = $asset->url(120, 120, 'crop');

    expect($url)->toContain('/restricted-render')->toContain('expires=');

    $response = $this->get($url)->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('immutable')
        ->and(str_starts_with((string) $response->getContent(), "\x89PNG"))->toBeTrue();

    $this->actingAs(W::user('Outsider'))->get($url)->assertNotFound();

    auth()->logout();
    $this->app['auth']->forgetGuards();
    $this->get($url)->assertNotFound();

    $this->travel(11)->minutes();
    $this->actingAs($member)->get($url)->assertForbidden();
});

it('gives a PDF thumbnail in a restricted folder the restricted address too', function (): void {
    [$asset, $member] = restrictedPdf();
    $this->actingAs($member);

    // No Imagick needed to see which route would be used: the renditions service signs for the right one.
    expect(app(RenditionService::class)->url($asset, 160))->toContain('/restricted-render');
});

// ---------------------------------------------------------------- password folders

it('serves a password folder\'s file only while it is unlocked, and not after the password changes', function (): void {
    $reader = W::user('Reader');
    $folder = W::restricted('Vault', FolderAccessMode::Password, password: 'open sesame');
    $asset = W::asset('vault-photo', $folder);

    $this->actingAs($reader);
    expect(fn () => $asset->url(100))->toThrow(AccessDenied::class);

    app(FolderAccess::class)->unlock($folder, 'open sesame');
    $url = $asset->url(100);
    $session = session()->all();
    // The test app keeps one session store for every request; a real one starts empty each time.
    session()->flush();

    $this->withSession($session)->get($url)->assertOk();
    // a session that never unlocked it
    session()->flush();
    $this->get($url)->assertNotFound();

    app(MediaFolderService::class)->setAccess($folder, FolderAccessMode::Password, 'a new password', '1');
    session()->flush();

    $this->withSession($session)->get($url)->assertNotFound();
});

it('lets the owner and manage-all open a password folder\'s files without unlocking', function (): void {
    $owner = W::user('Owner');
    $admin = W::user('Admin');
    $folder = W::restricted('Vault', FolderAccessMode::Password, $owner, 'open sesame');
    $asset = W::asset('vault-photo', $folder);
    Gate::define('media.manage-all', static fn ($user): bool => $user->name === 'Admin');

    foreach ([$owner, $admin] as $user) {
        $this->actingAs($user);
        $this->get($asset->url(100))->assertOk();
    }
});

// ---------------------------------------------------------------- views never crash

it('renders a placeholder, with no address or name, for a restricted file the visitor may not have', function (): void {
    [$asset, $member] = restrictedPdf('Secret payroll title');
    $image = W::asset('secret-image-name', $asset->folder);
    $view = static fn (MediaAsset $a): string => Blade::render('<x-media-library::file :asset="$asset" />', ['asset' => $a]);

    foreach ([$asset, $image] as $denied) {
        $html = $view($denied);

        expect($html)->toContain('ml-library__file-locked')
            ->not->toContain('Secret payroll title')->not->toContain('secret-image-name')->not->toContain($denied->uuid)->not->toContain('href=')->not->toContain('src=');
    }

    $this->actingAs($member);

    expect($view($asset))->toContain('Secret payroll title')->toContain('/restricted-file')
        ->and($view($image))->toContain('<img')->toContain('/restricted-render');
});

it('gives the thumbnail column nothing for a restricted image it may not show', function (): void {
    $folder = W::restricted('Photos', FolderAccessMode::Members);
    $asset = W::asset('private-photo', $folder);
    $member = W::user('Member');
    W::member($folder, $member);
    $column = MediaThumbnailColumn::make('hero');

    expect($column->getImageUrl((string) $asset->id))->toBeNull();

    $this->actingAs($member);

    expect($column->getImageUrl((string) $asset->id))->toContain('/restricted-render');
});

it('leaves a restricted caption file out of a public video\'s markup', function (): void {
    [$caption, $member, $folder] = restrictedPdf();
    $video = app(MediaGovernanceService::class)->upload(Files::mp4().str_repeat('0123456789', 50), 'clip.mp4', '', '1', null, 'A clip');
    $caption->forceFill(['mime_type' => 'text/vtt'])->save();
    $video->forceFill(['captions_asset_id' => $caption->id])->save();

    expect($video->refresh()->captionsUrl())->toBeNull();

    $this->actingAs($member);

    expect($video->refresh()->captionsUrl())->toContain('/restricted-file');
});
