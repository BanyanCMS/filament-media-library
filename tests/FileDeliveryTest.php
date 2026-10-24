<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Delivery\FileDelivery;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Http\FileController;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Tests\Support\Files;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\RemoteLikeAdapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

covers(FileController::class, FileDelivery::class, MediaAsset::class);

function documentAsset(string $title = 'Annual report 2025', string $filename = 'annual.pdf', ?string $binary = null): MediaAsset
{
    return app(MediaGovernanceService::class)->upload($binary ?? Files::pdf(), $filename, '', '1', null, $title);
}

function videoAsset(): MediaAsset
{
    return app(MediaGovernanceService::class)->upload(Files::mp4().str_repeat('0123456789', 50), 'clip.mp4', '', '1', null, 'A clip');
}

/** A disk that is not "local" to the controller, so it takes the remote paths. */
function remoteDisk(string $name, bool $temporaryUrls): FilesystemAdapter
{
    Storage::extend('fake-remote', function ($app, array $config): FilesystemAdapter {
        // Wrapped, so it is not a LocalFilesystemAdapter and takes the remote paths.
        $adapter = new RemoteLikeAdapter(new LocalFilesystemAdapter($config['root']));

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    });

    $root = storage_path('framework/testing/'.$name);
    File::deleteDirectory($root);
    config(['filesystems.disks.'.$name => ['driver' => 'fake-remote', 'root' => $root]]);

    $disk = Storage::disk($name);
    assert($disk instanceof FilesystemAdapter);

    if ($temporaryUrls) {
        $disk->buildTemporaryUrlsUsing(fn (string $path, DateTimeInterface $expiration, array $options): string => 'https://cdn.example.test/'.$path.'?expires='.$expiration->getTimestamp().'&type='.rawurlencode((string) $options['ResponseContentType']).'&disposition='.rawurlencode((string) $options['ResponseContentDisposition']));
    }

    return $disk;
}

function moveToDisk(MediaAsset $asset, FilesystemAdapter $disk): void
{
    $disk->put($asset->path, (string) Storage::disk('media-lib-test')->get($asset->path));
    $asset->forceFill(['disk' => 'remote-test'])->save();
}

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/remote-test'));
});

it('serves a pdf inline with the stored type, nosniff and an immutable cache header', function (): void {
    $asset = documentAsset();

    $response = $this->get($asset->fileUrl())->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->streamedContent())->toBe(Files::pdf());
});

it('serves video, audio and images inline and every other document as an attachment', function (): void {
    $service = app(MediaGovernanceService::class);
    $inline = [
        Images::upload(),
        videoAsset(),
        $service->upload(Files::mp3(), 'talk.mp3', '', '1', null, 'A talk'),
    ];
    $attachment = [
        $service->upload(Files::csv(), 'data.csv', '', '1', null, 'Figures'),
        $service->upload(Files::ooxml('docx'), 'letter.docx', '', '1', null, 'Letter'),
        $service->upload(Files::zip(), 'bundle.zip', '', '1', null, 'Bundle'),
        $service->upload(Files::text(), 'notes.txt', '', '1', null, 'Notes'),
    ];

    foreach ($inline as $asset) {
        expect($this->get($asset->fileUrl())->assertOk()->headers->get('Content-Disposition'))->toStartWith('inline;');
    }

    foreach ($attachment as $asset) {
        $response = $this->get($asset->fileUrl())->assertOk();

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
            ->and($response->headers->get('Content-Type'))->toStartWith($asset->mime_type)
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    }
});

it('forces an attachment on a download url, and the flag is covered by the signature', function (): void {
    $asset = documentAsset();

    $url = $asset->downloadUrl();

    expect($url)->toContain('download=1')
        ->and($this->get($url)->assertOk()->headers->get('Content-Disposition'))->toStartWith('attachment;');

    // Turning an inline link into a download (or back) by editing the query string is a different request.
    $this->get((string) preg_replace('/download=1&?/', '', $url))->assertForbidden();
    $this->get($asset->fileUrl().'&download=1')->assertForbidden();
});

it('names the file after its title, with an ascii fallback and an RFC 6266 filename*', function (): void {
    $asset = documentAsset('年度報告 2025 / "final"', 'annual.pdf');

    $header = (string) $this->get($asset->fileUrl())->assertOk()->headers->get('Content-Disposition');

    expect($header)->toContain('filename="2025 - -final-.pdf"')
        ->and($header)->toContain("filename*=UTF-8''".rawurlencode('年度報告 2025 - -final-.pdf'))
        ->and($header)->not->toContain('/');
});

it('falls back to the stored filename when there is no title, and does not double an extension', function (): void {
    $image = Images::upload();
    $titled = documentAsset('report.pdf', 'whatever.pdf');

    expect(FileDelivery::filename($image))->toBe('split.png')
        ->and(FileDelivery::filename($titled))->toBe('report.pdf')
        ->and(FileDelivery::disposition($image))->toBe('inline; filename="split.png"; filename*=UTF-8\'\'split.png');

    $titled->forceFill(['title' => null, 'filename' => '...'])->save();
    expect(FileDelivery::filename($titled))->toBe('download');
});

it('refuses an unsigned or tampered file request without revealing the asset', function (): void {
    $asset = documentAsset();

    $this->get('/media/'.$asset->uuid.'/file')->assertForbidden();
    $this->get('/media/'.$asset->uuid.'/file?v=1&signature=wrong')->assertForbidden();
    $this->get('/media/00000000-0000-0000-0000-000000000000/file')->assertForbidden();

    $other = documentAsset('Other', 'other.pdf');
    $swapped = str_replace($asset->uuid, $other->uuid, $asset->fileUrl());
    $this->get($swapped)->assertForbidden();
});

it('answers 404 for a signed url whose asset or file has gone', function (): void {
    $asset = documentAsset();
    $url = $asset->fileUrl();

    Storage::disk('media-lib-test')->delete($asset->path);
    $this->get($url)->assertNotFound();

    $asset->delete();
    $this->get($url)->assertNotFound();
});

it('puts the version in the url, so a replacement is a new address', function (): void {
    $asset = documentAsset();
    $before = $asset->fileUrl();

    app(MediaGovernanceService::class)->replace($asset, Files::pdfWithPages(3), 'annual-v2.pdf', '1');
    $asset->refresh();

    expect($asset->fileUrl())->not->toBe($before)
        ->and($asset->fileUrl())->toContain('v='.$asset->current_version_id)
        ->and($this->get($asset->fileUrl())->assertOk()->streamedContent())->toBe(Files::pdfWithPages(3));
});

it('honours a Range request on a local disk with a 206 and exactly those bytes', function (): void {
    $asset = videoAsset();
    $bytes = (string) Storage::disk('media-lib-test')->get($asset->path);

    $response = $this->get($asset->fileUrl(), ['Range' => 'bytes=4-19'])->assertStatus(206);

    expect($response->headers->get('Content-Range'))->toBe('bytes 4-19/'.strlen($bytes))
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($response->streamedContent())->toBe(substr($bytes, 4, 16));

    $tail = $this->get($asset->fileUrl(), ['Range' => 'bytes=-8'])->assertStatus(206);
    expect($tail->streamedContent())->toBe(substr($bytes, -8));
});

it('redirects a disk with temporary urls to one, with the type and disposition passed along', function (): void {
    $asset = videoAsset();
    moveToDisk($asset, remoteDisk('remote-test', true));

    $response = $this->get($asset->fileUrl(), ['Range' => 'bytes=0-9'])->assertStatus(302);
    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('https://cdn.example.test/'.$asset->path)
        ->and($location)->toContain('type=video%2Fmp4')
        ->and($location)->toContain('disposition=inline')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    // 5 minutes by default, and configurable.
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBeBetween(time() + 290, time() + 310);

    config(['media-library.delivery.temporary_url_minutes' => 20]);
    $later = (string) $this->get($asset->fileUrl())->headers->get('Location');
    parse_str((string) parse_url($later, PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBeBetween(time() + 1190, time() + 1210);

    // A download link redirects to an attachment.
    expect((string) $this->get($asset->downloadUrl())->headers->get('Location'))->toContain('disposition=attachment');
});

it('streams the whole file from a remote disk that cannot issue temporary urls', function (): void {
    $asset = documentAsset();
    moveToDisk($asset, remoteDisk('remote-test', false));

    $response = $this->get($asset->fileUrl())->assertOk();

    expect($response->streamedContent())->toBe(Files::pdf())
        ->and($response->headers->get('Content-Length'))->toBe((string) $asset->size_bytes)
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('streams an embed from this origin instead of redirecting it to the storage host', function (): void {
    $asset = videoAsset();
    moveToDisk($asset, remoteDisk('remote-test', true));
    $bytes = Files::mp4().str_repeat('0123456789', 50);

    foreach (['iframe', 'video', 'audio', 'embed'] as $destination) {
        $this->get($asset->fileUrl(), ['Sec-Fetch-Dest' => $destination])->assertOk()->assertHeader('Accept-Ranges', 'bytes');
    }

    $partial = $this->get($asset->fileUrl(), ['Sec-Fetch-Dest' => 'video', 'Range' => 'bytes=10-19'])->assertStatus(206);

    expect($partial->streamedContent())->toBe(substr($bytes, 10, 10))
        ->and($partial->headers->get('Content-Range'))->toBe('bytes 10-19/'.strlen($bytes))
        ->and($partial->headers->get('Content-Length'))->toBe('10')
        ->and($partial->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($partial->headers->get('Vary'))->toBe('Sec-Fetch-Dest');

    $suffix = $this->get($asset->fileUrl(), ['Sec-Fetch-Dest' => 'video', 'Range' => 'bytes=-5'])->assertStatus(206);
    expect($suffix->streamedContent())->toBe(substr($bytes, -5));

    $this->get($asset->fileUrl(), ['Sec-Fetch-Dest' => 'video', 'Range' => 'bytes=99999-'])->assertStatus(416);

    // A top-level visit or a download still goes to the storage host.
    $this->get($asset->fileUrl(), ['Sec-Fetch-Dest' => 'document'])->assertStatus(302);
    $this->get($asset->downloadUrl())->assertStatus(302);
});

it('can always or never redirect to storage', function (): void {
    $asset = documentAsset();
    moveToDisk($asset, remoteDisk('remote-test', true));

    config(['media-library.delivery.redirect_to_storage' => 'never']);
    expect($this->get($asset->downloadUrl())->assertOk()->streamedContent())->toBe(Files::pdf());

    config(['media-library.delivery.redirect_to_storage' => 'always']);
    $this->get($asset->fileUrl(), ['Sec-Fetch-Dest' => 'iframe'])->assertStatus(302);
});

it('uses the configured route name and path', function (): void {
    expect(route('media-library.file', ['uuid' => 'abc']))->toEndWith('/media/abc/file');
});
