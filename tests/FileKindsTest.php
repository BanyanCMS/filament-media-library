<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Events\AssetUploading;
use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Banyan\FilamentMediaLibrary\Exceptions\AltTextRequired;
use Banyan\FilamentMediaLibrary\Exceptions\ExtensionMismatch;
use Banyan\FilamentMediaLibrary\Exceptions\FileTooLarge;
use Banyan\FilamentMediaLibrary\Exceptions\KindMismatch;
use Banyan\FilamentMediaLibrary\Exceptions\MediaException;
use Banyan\FilamentMediaLibrary\Exceptions\TitleRequired;
use Banyan\FilamentMediaLibrary\Exceptions\UnreadableImage;
use Banyan\FilamentMediaLibrary\Exceptions\UnsupportedFileType;
use Banyan\FilamentMediaLibrary\Exceptions\UploadRejected;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Storage\MediaRepository;
use Banyan\FilamentMediaLibrary\Support\MediaKinds;
use Banyan\FilamentMediaLibrary\Tests\Support\Files;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\RejectingScanner;
use Banyan\FilamentMediaLibrary\Uploads\FileTypes;
use Banyan\FilamentMediaLibrary\Uploads\UploadGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

covers(UploadGuard::class, FileTypes::class, MediaKinds::class, MediaKind::class, MediaRepository::class, MediaAsset::class);

function uploadFile(string $binary, string $filename, ?string $title = 'A title', string $alt = ''): MediaAsset
{
    return app(MediaGovernanceService::class)->upload($binary, $filename, 'application/octet-stream', $alt, '1', null, $title);
}

/** Nothing reached the database or the disk. */
function expectNothingWritten(): void
{
    expect(DB::table('lib_assets')->count())->toBe(0)
        ->and(DB::table('lib_versions')->count())->toBe(0)
        ->and(Storage::disk('media-lib-test')->allFiles())->toBe([]);
}

dataset('accepted files', [
    'pdf' => [fn () => Files::pdf(), 'report.pdf', 'application/pdf', MediaKind::Document],
    'docx' => [fn () => Files::ooxml('docx'), 'minutes.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', MediaKind::Document],
    'xlsx' => [fn () => Files::ooxml('xlsx'), 'budget.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', MediaKind::Document],
    'pptx' => [fn () => Files::ooxml('pptx'), 'slides.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', MediaKind::Document],
    'odt' => [fn () => Files::odt(), 'letter.odt', 'application/vnd.oasis.opendocument.text', MediaKind::Document],
    'csv' => [fn () => Files::csv(), 'data.csv', 'text/csv', MediaKind::Document],
    'csv named txt' => [fn () => Files::csv(), 'data.txt', 'text/plain', MediaKind::Document],
    'txt' => [fn () => Files::text(), 'notes.TXT', 'text/plain', MediaKind::Document],
    'zip' => [fn () => Files::zip(), 'bundle.zip', 'application/zip', MediaKind::Archive],
    'mp4' => [fn () => Files::mp4(), 'clip.mp4', 'video/mp4', MediaKind::Video],
    'webm' => [fn () => Files::webm(), 'clip.webm', 'video/webm', MediaKind::Video],
    'm4a (an mp4 container of audio)' => [fn () => Files::mp4(), 'voice.m4a', 'audio/mp4', MediaKind::Audio],
    'mp3' => [fn () => Files::mp3(), 'song.mp3', 'audio/mpeg', MediaKind::Audio],
    'wav' => [fn () => Files::wav(), 'tone.wav', 'audio/wav', MediaKind::Audio],
]);

it('stores a file of every accepted kind with its detected type', function (Closure $binary, string $filename, string $mime, MediaKind $kind): void {
    $asset = uploadFile($binary(), $filename, 'Annual report');

    expect($asset->kind)->toBe($kind)
        ->and($asset->mime_type)->toBe($mime)
        ->and($asset->isImage())->toBeFalse()
        ->and($asset->title)->toBe('Annual report')
        ->and($asset->alt_text)->toBeNull()
        ->and($asset->width)->toBeNull()
        ->and($asset->currentVersion?->kind)->toBe($kind)
        ->and(Storage::disk('media-lib-test')->exists($asset->path))->toBeTrue();
})->with('accepted files');

it('ignores the type the client claimed', function (): void {
    $asset = app(MediaGovernanceService::class)->upload(Files::pdf(), 'report.pdf', 'image/png', '', '1', null, 'Report');

    expect($asset->mime_type)->toBe('application/pdf')->and($asset->kind)->toBe(MediaKind::Document);
});

dataset('refused files', [
    'docx with macros' => [fn () => Files::ooxml('docx', macros: true), 'minutes.docx', UnsupportedFileType::class],
    'macro-enabled content type' => [fn () => Files::zip([
        '[Content_Types].xml' => '<Types><Override ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>',
        'word/document.xml' => '<w/>',
    ]), 'minutes.docx', UnsupportedFileType::class],
    'html renamed pdf' => [fn () => Files::html(), 'form.pdf', ExtensionMismatch::class],
    'html renamed docx' => [fn () => Files::html(), 'cv.docx', ExtensionMismatch::class],
    'html' => [fn () => Files::html(), 'page.html', UnsupportedFileType::class],
    'svg' => [fn () => Files::svg(), 'logo.svg', UnsupportedFileType::class],
    'svg renamed png' => [fn () => Files::svg(), 'logo.png', ExtensionMismatch::class],
    'windows executable' => [fn () => Files::exe(), 'setup.exe', UnsupportedFileType::class],
    'windows executable renamed pdf' => [fn () => Files::exe(), 'report.pdf', ExtensionMismatch::class],
    'elf executable' => [fn () => Files::elf(), 'tool.bin', UnsupportedFileType::class],
    'double extension' => [fn () => Files::exe(), 'report.pdf.exe', UnsupportedFileType::class],
    'png named pdf' => [fn () => Images::splitPng(), 'photo.pdf', ExtensionMismatch::class],
    'pdf named png' => [fn () => Files::pdf(), 'photo.png', ExtensionMismatch::class],
    'docx named zip' => [fn () => Files::ooxml('docx'), 'minutes.zip', ExtensionMismatch::class],
    'no extension' => [fn () => Files::pdf(), 'report', ExtensionMismatch::class],
    'undecodable jpeg' => [fn () => Files::brokenJpeg(), 'photo.jpg', UnreadableImage::class],
]);

it('refuses what it must and writes nothing', function (Closure $binary, string $filename, string $exception): void {
    try {
        uploadFile($binary(), $filename, 'Title', 'Alt');
        $this->fail('The upload was accepted.');
    } catch (MediaException $e) {
        expect($e)->toBeInstanceOf($exception);

        // Names no file, except the long-standing UnreadableImage message.
        if (! $e instanceof UnreadableImage) {
            expect($e->getMessage())->not->toContain(pathinfo($filename, PATHINFO_FILENAME) === '' ? 'x' : pathinfo($filename, PATHINFO_FILENAME));
        }
    }

    expectNothingWritten();
})->with('refused files');

it('refuses a pdf that does not start with the pdf signature', function (): void {
    // finfo recognises a %PDF- marker within the first kilobyte; the library does not.
    expect(fn () => uploadFile("junk\n".Files::pdf(), 'report.pdf'))->toThrow(UnsupportedFileType::class);
    expectNothingWritten();
});

it('refuses a zip with more entries than the limit', function (): void {
    config()->set('media-library.uploads.archive.max_entries', 10);

    expect(fn () => uploadFile(Files::zipWithEntries(11), 'many.zip'))->toThrow(UploadRejected::class);
    expectNothingWritten();

    expect(uploadFile(Files::zipWithEntries(10), 'ten.zip')->kind)->toBe(MediaKind::Archive);
});

it('refuses a file over its kind\'s size limit and names the limit, not the file', function (): void {
    config()->set('media-library.uploads.kinds.document.max_size', 1);

    try {
        uploadFile(str_repeat('a', 2048).'', 'notes.txt');
        $this->fail('Accepted.');
    } catch (FileTooLarge $e) {
        expect($e->getMessage())->toContain('document')->toContain('1 KB')->not->toContain('notes');
    }

    expectNothingWritten();
    expect(uploadFile('short', 'notes.txt')->kind)->toBe(MediaKind::Document);
});

it('limits each kind separately', function (): void {
    config()->set('media-library.uploads.kinds.image.max_size', 1);

    expect(fn () => uploadFile(Files::noisyPng(), 'noise.png', null, 'Alt'))->toThrow(FileTooLarge::class)
        ->and(uploadFile(str_repeat('a', 2048), 'notes.txt')->kind)->toBe(MediaKind::Document);
});

it('requires a title for every kind but images, and alt text for images', function (): void {
    foreach ([null, '', '   '] as $title) {
        expect(fn () => uploadFile(Files::pdf(), 'report.pdf', $title))->toThrow(TitleRequired::class);
    }

    expect(fn () => app(MediaGovernanceService::class)->upload(Images::splitPng(), 'a.png', 'image/png', '  ', '1'))
        ->toThrow(AltTextRequired::class);

    expectNothingWritten();

    $image = app(MediaGovernanceService::class)->upload(Images::splitPng(), 'a.png', 'image/png', 'Alt', '1', null, '  A nice picture ');

    expect($image->title)->toBe('A nice picture')->and($image->kind)->toBe(MediaKind::Image);
});

it('keeps existing image behaviour: alt text, dimensions and the image kind', function (): void {
    $asset = Images::upload('A split');

    expect($asset->kind)->toBe(MediaKind::Image)
        ->and($asset->isImage())->toBeTrue()
        ->and([$asset->width, $asset->height])->toBe([400, 200])
        ->and($asset->title)->toBeNull();
});

it('replaces a file with another of the same kind and keeps the history', function (): void {
    $asset = uploadFile(Files::pdf(), 'v1.pdf');
    $version = app(MediaGovernanceService::class)->replace($asset, Files::pdf()."\n%%v2", 'v2.pdf', 'application/pdf', '1');

    expect($version->kind)->toBe(MediaKind::Document)
        ->and($asset->refresh()->versions)->toHaveCount(2)
        ->and($asset->filename)->toBe('v2.pdf');
});

it('refuses to replace a pdf with an image, or an image with a pdf, and writes nothing', function (): void {
    $pdf = uploadFile(Files::pdf(), 'v1.pdf');
    $image = Images::upload();
    $files = count(Storage::disk('media-lib-test')->allFiles());

    expect(fn () => app(MediaGovernanceService::class)->replace($pdf, Images::splitPng(), 'a.png', 'image/png', '1'))->toThrow(KindMismatch::class)
        ->and(fn () => app(MediaGovernanceService::class)->replace($image, Files::pdf(), 'a.pdf', 'application/pdf', '1'))->toThrow(KindMismatch::class)
        ->and(DB::table('lib_versions')->count())->toBe(2)
        ->and(Storage::disk('media-lib-test')->allFiles())->toHaveCount($files);
});

it('dispatches AssetUploading before anything is written, with a readable temporary copy', function (): void {
    $seen = null;
    Event::listen(AssetUploading::class, function (AssetUploading $event) use (&$seen): void {
        $seen = [
            'kind' => $event->kind,
            'mime' => $event->mimeType,
            'bytes' => file_get_contents($event->path),
            'rows' => DB::table('lib_assets')->count(),
            'files' => count(Storage::disk('media-lib-test')->allFiles()),
            'size' => $event->sizeBytes,
        ];
    });

    uploadFile(Files::pdf(), 'report.pdf');

    expect($seen)->toBe(['kind' => MediaKind::Document, 'mime' => 'application/pdf', 'bytes' => Files::pdf(), 'rows' => 0, 'files' => 0, 'size' => strlen(Files::pdf())]);
});

it('lets a listener reject an upload, writing nothing', function (): void {
    Event::listen(AssetUploading::class, static function (): never {
        throw UploadRejected::because('quarantined by policy.');
    });

    expect(fn () => uploadFile(Files::pdf(), 'report.pdf'))->toThrow(UploadRejected::class, 'quarantined by policy.');
    expectNothingWritten();
});

it('does not fire AssetUploading for a file the built-in checks refused', function (): void {
    $fired = false;
    Event::listen(AssetUploading::class, function () use (&$fired): void {
        $fired = true;
    });

    expect(fn () => uploadFile(Files::exe(), 'setup.exe'))->toThrow(UnsupportedFileType::class);
    expect($fired)->toBeFalse();
});

it('runs the configured scanner and rejects what it flags, writing nothing', function (): void {
    config()->set('media-library.uploads.scanner', RejectingScanner::class);
    RejectingScanner::$seen = [];

    expect(fn () => uploadFile("EICAR\n", 'virus.txt'))->toThrow(UploadRejected::class, 'Eicar-Test-Signature detected.');
    expectNothingWritten();

    expect(uploadFile('fine', 'ok.txt')->kind)->toBe(MediaKind::Document)
        ->and(RejectingScanner::$seen)->toBe(["EICAR\n", 'fine']);
});

it('refuses a scanner class that is not a FileScanner', function (): void {
    config()->set('media-library.uploads.scanner', stdClass::class);

    expect(fn () => uploadFile(Files::pdf(), 'report.pdf'))->toThrow(LogicException::class);
    expectNothingWritten();
});

it('scans nothing when no scanner is configured', function (): void {
    RejectingScanner::$seen = [];

    uploadFile(Files::pdf(), 'report.pdf');

    expect(RejectingScanner::$seen)->toBe([]);
});

it('adds the kind to every asset event payload', function (): void {
    $events = [];
    Event::listen(MediaEvent::class, function (MediaEvent $event) use (&$events): void {
        $events[] = $event;
    });
    $service = app(MediaGovernanceService::class);

    $asset = uploadFile(Files::pdf(), 'report.pdf', 'Report');
    $service->replace($asset, Files::pdf(), 'r2.pdf', 'application/pdf', '1');
    $service->updateDetails($asset->refresh(), '', null, '1', 'Report 2026');
    $service->setFocalPoint($asset, 0.1, 0.1, '1');
    $service->syncTags($asset, ['forms'], '1');
    $service->delete($asset, '1');

    expect($events)->toHaveCount(6);

    foreach ($events as $event) {
        expect($event->payload()['kind'])->toBe('document');
    }

    expect($events[2]->payload())->toMatchArray(['alt_text_changed' => false, 'title_changed' => true])
        ->and(json_encode(array_map(static fn (MediaEvent $e): array => $e->payload(), $events)))->not->toContain('Report');
});

it('updates a title, requires one for a non-image and leaves it alone when not given', function (): void {
    $service = app(MediaGovernanceService::class);
    $pdf = uploadFile(Files::pdf(), 'report.pdf', 'Report');

    $service->updateDetails($pdf, '', null, '1');
    expect($pdf->refresh()->title)->toBe('Report');

    $service->updateDetails($pdf, '', null, '1', 'Report 2026');
    expect($pdf->refresh()->title)->toBe('Report 2026');

    expect(fn () => $service->updateDetails($pdf, '', null, '1', '  '))->toThrow(TitleRequired::class);

    $image = Images::upload('Alt');
    expect(fn () => $service->updateDetails($image, '', null, '1'))->toThrow(AltTextRequired::class);
    $service->updateDetails($image, 'New alt', null, '1');
    expect($image->refresh()->alt_text)->toBe('New alt');
});

it('derives the kind from the configured allow-list', function (): void {
    expect(MediaKind::fromMime('application/pdf'))->toBe(MediaKind::Document)
        ->and(MediaKind::fromMime('video/mp4'))->toBe(MediaKind::Video)
        ->and(MediaKind::fromMime('image/svg+xml'))->toBeNull();

    config()->set('media-library.uploads.kinds.document.mime_types', ['text/plain']);

    expect(MediaKind::fromMime('application/pdf'))->toBeNull()
        ->and(MediaKind::fromMime('text/plain'))->toBe(MediaKind::Document);
});

it('reads the legacy flat upload keys as the image kind, and defaults for the rest', function (): void {
    config()->set('media-library.uploads', ['max_size' => 7, 'mime_types' => ['image/png']]);

    expect(MediaKinds::maxSizeKb(MediaKind::Image))->toBe(7)
        ->and(MediaKinds::mimeTypes(MediaKind::Image))->toBe(['image/png'])
        ->and(MediaKinds::maxSizeKb(MediaKind::Document))->toBe(51200)
        ->and(MediaKinds::mimeTypes(MediaKind::Video))->toBe(['video/mp4', 'video/webm'])
        ->and(MediaKinds::acceptedMimeTypes([MediaKind::Image]))->toBe(['image/png'])
        ->and(MediaKinds::largestMaxSizeKb())->toBe(512000);

    expect(fn () => Images::upload())->not->toThrow(Throwable::class);
});

it('lets uploads.kinds.image win over the legacy keys', function (): void {
    config()->set('media-library.uploads', ['max_size' => 7, 'kinds' => ['image' => ['max_size' => 99, 'mime_types' => ['image/gif']]]]);

    expect(MediaKinds::maxSizeKb(MediaKind::Image))->toBe(99)
        ->and(MediaKinds::mimeTypes(MediaKind::Image))->toBe(['image/gif']);
});

it('has a label in every language and an icon for every kind', function (): void {
    foreach (['en', 'zh-Hant', 'zh-Hans'] as $locale) {
        app()->setLocale($locale);

        foreach (MediaKind::cases() as $kind) {
            expect($kind->label())->not->toStartWith('media-library::')->and($kind->icon())->toStartWith('heroicon-o-');
        }
    }
});

it('scopes by kind and searches titles', function (): void {
    $pdf = uploadFile(Files::pdf(), 'a.pdf', 'Annual 100% report');
    $image = Images::upload();
    uploadFile(Files::zip(), 'b.zip', 'Bundle');

    expect(MediaAsset::query()->ofKind(MediaKind::Document)->pluck('id')->all())->toBe([$pdf->id])
        ->and(MediaAsset::query()->ofKind([MediaKind::Image, MediaKind::Document])->count())->toBe(2)
        ->and(MediaAsset::query()->ofKind(MediaKind::Video)->count())->toBe(0)
        ->and(MediaAsset::query()->search('annual')->pluck('id')->all())->toBe([$pdf->id])
        ->and(MediaAsset::query()->search('100%')->pluck('id')->all())->toBe([$pdf->id])
        ->and($image->isImage())->toBeTrue();
});

it('answers 404 for a rendition of a non-image instead of throwing', function (): void {
    $pdf = uploadFile(Files::pdf(), 'report.pdf');

    expect(app(RenditionService::class)->render($pdf, app(RenditionService::class)->spec(100, 100, 'crop')))->toBeNull();
    $this->get($pdf->url(100, 100, 'crop'))->assertNotFound();
});

it('maps detected types for the quirks of libmagic', function (): void {
    $detect = function (string $binary, string $extension): string {
        $path = (string) tempnam(sys_get_temp_dir(), 'ft-');
        file_put_contents($path, $binary);

        try {
            return FileTypes::detect($path, $extension);
        } finally {
            unlink($path);
        }
    };

    expect($detect(Files::wav(), 'wav'))->toBe('audio/wav')
        ->and($detect(Files::csv(), 'txt'))->toBe('text/plain')
        ->and($detect(Files::csv(), 'csv'))->toBe('text/csv')
        ->and($detect('not a zip', 'zip'))->toBe('text/plain')
        ->and($detect(Files::ooxml('docx'), 'docx'))->toContain('wordprocessingml')
        ->and(FileTypes::extensionAgrees('image/jpeg', 'JPG'))->toBeTrue()
        ->and(FileTypes::extensionAgrees('image/jpeg', 'png'))->toBeFalse()
        ->and(FileTypes::extensionAgrees('application/x-unknown', 'pdf'))->toBeFalse()
        ->and(FileTypes::extensionAgrees('application/x-unknown', 'foo'))->toBeTrue();
});
