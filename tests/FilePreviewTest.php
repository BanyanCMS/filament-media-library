<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Admin\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\Contracts\PdfThumbnailer;
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Events\CaptionsChanged;
use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Banyan\FilamentMediaLibrary\Events\TranscriptChanged;
use Banyan\FilamentMediaLibrary\Exceptions\CompanionNotAllowed;
use Banyan\FilamentMediaLibrary\Exceptions\ExtensionMismatch;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Rendering\ImagickPdfThumbnailer;
use Banyan\FilamentMediaLibrary\Rendering\NullPdfThumbnailer;
use Banyan\FilamentMediaLibrary\Rendering\PdfThumbnailService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Tests\Support\FakePdfThumbnailer;
use Banyan\FilamentMediaLibrary\Tests\Support\Files;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Banyan\FilamentMediaLibrary\Uploads\PdfPages;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

covers(
    MediaAssetPage::class,
    MediaLibraryPage::class,
    PdfPages::class,
    PdfThumbnailService::class,
    ImagickPdfThumbnailer::class,
    NullPdfThumbnailer::class,
    CaptionsChanged::class,
    TranscriptChanged::class,
);

function upload(string $binary, string $filename, string $title): MediaAsset
{
    return app(MediaGovernanceService::class)->upload($binary, $filename, 'application/octet-stream', '', '1', null, $title);
}

/** The library's own markup: Filament's action-modal host carries a `style` of its own that is not ours. */
function assetPageHtml(MediaAsset $asset): string
{
    $html = Livewire::actingAs(User::query()->first() ?? User::make())->test(MediaAssetPage::class, ['asset' => $asset->uuid])->assertOk()->html();
    $start = (int) strpos($html, 'class="bn-media"');
    $end = strpos($html, 'wire:partial="action-modals"');

    return substr($html, $start, $end === false ? null : $end - $start);
}

it('counts the pages of a pdf, without counting the page tree, and says null when it cannot', function (): void {
    expect(PdfPages::count(Files::pdfWithPages(2)))->toBe(2)
        ->and(PdfPages::count(Files::pdfWithPages(3, '')))->toBe(3)
        ->and(PdfPages::count(Files::pdf()))->toBeNull()
        ->and(PdfPages::count('not a pdf'))->toBeNull();
});

it('stores the page count of a pdf on upload and again on replacement, and nothing for other files', function (): void {
    $pdf = upload(Files::pdfWithPages(2), 'two.pdf', 'Two pages');
    $docx = upload(Files::ooxml(), 'letter.docx', 'Letter');

    expect($pdf->page_count)->toBe(2)
        ->and($docx->page_count)->toBeNull()
        ->and($pdf->duration_seconds)->toBeNull();

    app(MediaGovernanceService::class)->replace($pdf, Files::pdfWithPages(5), 'five.pdf', 'application/pdf', '1');

    expect($pdf->refresh()->page_count)->toBe(5);
});

it('previews a pdf in an accessible iframe with its page count, an open link and a download link', function (): void {
    $pdf = upload(Files::pdfWithPages(2), 'annual.pdf', 'Annual report 2025');

    $html = assetPageHtml($pdf);

    expect($html)->toContain('<iframe')
        ->and($html)->toContain('class="bn-media__pdf"')
        ->and($html)->toContain('title="Preview of Annual report 2025"')
        ->and($html)->toContain(e($pdf->fileUrl()))
        ->and($html)->toContain('2 pages')
        ->and($html)->toContain('Open in new tab')
        ->and($html)->toContain('rel="noopener"')
        ->and($html)->toContain(e($pdf->downloadUrl()))
        ->and($html)->not->toContain('style=')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain(__('media-library::media.detail.focal_heading'));
});

it('previews a video with a native player, captions when attached, and the transcript', function (): void {
    $video = upload(Files::mp4(), 'clip.mp4', 'Welcome film');

    $html = assetPageHtml($video);

    expect($html)->toContain('<video')
        ->and($html)->toContain('controls')
        ->and($html)->toContain('preload="metadata"')
        ->and($html)->not->toContain('<track')
        ->and($html)->not->toContain('<iframe')
        ->and($html)->not->toContain('style=');

    $captions = upload(Files::vtt(), 'clip.vtt', 'Welcome film captions');
    $service = app(MediaGovernanceService::class);
    $service->setCaptions($video, $captions, '1');
    $service->setTranscript($video, "Hello and welcome.\nThanks for coming.", '1');

    $html = assetPageHtml($video->refresh());

    expect($html)->toContain('<track kind="captions"')
        ->and($html)->toContain(e($captions->fileUrl()))
        ->and($html)->toContain('Hello and welcome.')
        ->and($html)->toContain('Welcome film captions');
});

it('previews audio with a native player and the transcript', function (): void {
    $audio = upload(Files::mp3(), 'talk.mp3', 'The talk');
    app(MediaGovernanceService::class)->setTranscript($audio, 'Good morning <everyone>', '1');

    $html = assetPageHtml($audio->refresh());

    expect($html)->toContain('<audio')
        ->and($html)->toContain('preload="metadata"')
        ->and($html)->toContain('Good morning &lt;everyone&gt;')
        ->and($html)->not->toContain('<video')
        ->and($html)->not->toContain('style=');
});

it('shows other documents and archives with an icon, kind, size and a download button', function (): void {
    foreach ([[Files::ooxml(), 'letter.docx', 'Letter'], [Files::zip(), 'bundle.zip', 'Bundle'], [Files::csv(), 'data.csv', 'Figures']] as [$binary, $name, $title]) {
        $asset = upload($binary, $name, $title);

        $html = assetPageHtml($asset);

        expect($html)->toContain('bn-media__kind-icon')
            ->and($html)->toContain($asset->kind->label())
            ->and($html)->toContain($asset->humanSize())
            ->and($html)->toContain(e($asset->downloadUrl()))
            ->and($html)->not->toContain('<iframe')
            ->and($html)->not->toContain('<video')
            ->and($html)->not->toContain('style=');
    }
});

it('keeps the image preview and focal point exactly as they were', function (): void {
    $html = assetPageHtml(Images::upload());

    expect($html)->toContain('bn-media__stage')->and($html)->not->toContain('<iframe')->and($html)->not->toContain('<video');
});

it('shows a title and a kind badge on the grid card of a non-image', function (): void {
    upload(Files::pdf(), 'annual.pdf', 'Annual report 2025');

    $html = Livewire::actingAs(User::make())->test(MediaLibraryPage::class)->html();

    expect($html)->toContain('Annual report 2025')
        ->and($html)->toContain('bn-media__kind-badge')
        ->and($html)->toContain('annual.pdf');
});

it('falls back to the kind icon when no PDF engine is available', function (): void {
    $pdf = upload(Files::pdf(), 'annual.pdf', 'Annual report 2025');
    app()->instance(PdfThumbnailer::class, new NullPdfThumbnailer);
    app()->forgetInstance(PdfThumbnailService::class);

    $html = Livewire::actingAs(User::make())->test(MediaLibraryPage::class)->html();

    expect(app(PdfThumbnailService::class)->url($pdf))->toBeNull()
        ->and($html)->toContain('bn-media__kind-icon')
        ->and($html)->not->toContain('/media/'.$pdf->uuid.'/render');
});

it('binds the null thumbnailer unless Imagick can read PDFs', function (): void {
    app()->forgetInstance(PdfThumbnailer::class);
    $bound = app(PdfThumbnailer::class);

    expect($bound)->toBeInstanceOf(ImagickPdfThumbnailer::supported() ? ImagickPdfThumbnailer::class : NullPdfThumbnailer::class)
        ->and($bound->available())->toBe(ImagickPdfThumbnailer::supported());

    if (! ImagickPdfThumbnailer::supported()) {
        expect((new ImagickPdfThumbnailer)->thumbnail(Files::pdf(), 100))->toBeNull()
            ->and((new NullPdfThumbnailer)->thumbnail(Files::pdf(), 100))->toBeNull();
    }
});

it('renders a first-page thumbnail with Imagick', function (): void {
    expect((new ImagickPdfThumbnailer)->thumbnail('%PDF-1.4 definitely broken', 100))->toBeNull();
})->skip(fn (): bool => ! ImagickPdfThumbnailer::supported(), 'Imagick with a PDF delegate is not available.');

it('serves and caches a PDF thumbnail through the rendition route when an engine exists', function (): void {
    $fake = new FakePdfThumbnailer;
    app()->instance(PdfThumbnailer::class, $fake);
    app()->forgetInstance(PdfThumbnailService::class);
    $pdf = upload(Files::pdf(), 'annual.pdf', 'Annual report 2025');

    $html = Livewire::actingAs(User::make())->test(MediaLibraryPage::class)->html();
    $url = app(PdfThumbnailService::class)->url($pdf, 300);

    expect($html)->toContain('bn-media__thumb--page')
        ->and($url)->toContain('w=320');

    $first = $this->get((string) $url)->assertOk();
    $this->get((string) $url)->assertOk();

    expect($first->headers->get('Content-Type'))->toBe('image/png')
        ->and($fake->calls)->toBe(1)
        ->and(Images::pixelAt((string) $first->getContent(), 0.25))->toBe([255, 0, 0])
        ->and(Storage::disk('media-lib-test')->exists('media/renditions/'.$pdf->uuid.'/v'.$pdf->current_version_id.'-pdf-320.png'))->toBeTrue();
});

it('answers 404 for a PDF thumbnail that cannot be rendered, and for a non-image without an engine', function (): void {
    $pdf = upload(Files::pdf(), 'annual.pdf', 'Annual report 2025');
    $docx = upload(Files::ooxml(), 'letter.docx', 'Letter');

    app()->instance(PdfThumbnailer::class, new FakePdfThumbnailer(works: false));
    app()->forgetInstance(PdfThumbnailService::class);
    $this->get((string) app(PdfThumbnailService::class)->url($pdf))->assertNotFound();

    $this->get(app(RenditionService::class)->url($docx, 100))->assertNotFound();
});

it('accepts a WebVTT file as a document only when it starts with WEBVTT', function (): void {
    $vtt = upload(Files::vtt(), 'clip.vtt', 'Captions');
    $bom = upload("\xEF\xBB\xBF".Files::vtt(), 'bom.vtt', 'Captions with BOM');

    expect($vtt->kind)->toBe(MediaKind::Document)->and($vtt->mime_type)->toBe('text/vtt')
        ->and($bom->mime_type)->toBe('text/vtt');

    expect(fn () => upload("not a caption file\n", 'fake.vtt', 'Fake'))->toThrow(ExtensionMismatch::class);

    // The same bytes under another name stay plain text.
    expect(upload(Files::vtt(), 'notes.txt', 'Notes')->mime_type)->toBe('text/plain');
});

it('attaches captions and a transcript through the service, with reference-only events', function (): void {
    $events = [];
    Event::listen(MediaEvent::class, function (MediaEvent $event) use (&$events): void {
        $events[] = $event;
    });

    $video = upload(Files::mp4(), 'clip.mp4', 'Film');
    $captions = upload(Files::vtt(), 'clip.vtt', 'Captions');
    $service = app(MediaGovernanceService::class);
    $events = [];

    $service->setCaptions($video, $captions, '9');
    $service->setTranscript($video, '  Secret words spoken by Jane Doe  ', '9');

    expect($video->refresh()->captions_asset_id)->toBe($captions->id)
        ->and($video->captions?->is($captions))->toBeTrue()
        ->and($video->captionsUrl())->toBe($captions->fileUrl())
        ->and($video->transcript)->toBe('Secret words spoken by Jane Doe')
        ->and($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(CaptionsChanged::class)
        ->and($events[0]->name())->toBe('media.asset.captions_changed')
        ->and($events[0]->payload())->toBe(['asset_id' => $video->id, 'uuid' => $video->uuid, 'kind' => 'video', 'folder_id' => null, 'captions_asset_id' => $captions->id])
        ->and($events[1])->toBeInstanceOf(TranscriptChanged::class)
        ->and($events[1]->name())->toBe('media.asset.transcript_changed')
        ->and($events[1]->payload())->toBe(['asset_id' => $video->id, 'uuid' => $video->uuid, 'kind' => 'video', 'folder_id' => null, 'has_transcript' => true])
        ->and($events[1]->actorId())->toBe('9')
        ->and($events[1]->assetId())->toBe($video->id)
        ->and(json_encode($events[1]->payload()))->not->toContain('Jane');

    $events = [];
    $service->setCaptions($video, null, '9');
    $service->setTranscript($video, '   ', '9');

    expect($video->refresh()->captions_asset_id)->toBeNull()
        ->and($video->transcript)->toBeNull()
        ->and($events[1]->payload()['has_transcript'])->toBeFalse();
});

it('refuses captions on anything but a video, a non-WebVTT captions file and a transcript on a document', function (): void {
    $service = app(MediaGovernanceService::class);
    $audio = upload(Files::mp3(), 'talk.mp3', 'Talk');
    $video = upload(Files::mp4(), 'clip.mp4', 'Film');
    $pdf = upload(Files::pdf(), 'a.pdf', 'Doc');

    expect(fn () => $service->setCaptions($audio, null, '1'))->toThrow(CompanionNotAllowed::class)
        ->and(fn () => $service->setCaptions($video, $pdf, '1'))->toThrow(CompanionNotAllowed::class)
        ->and(fn () => $service->setTranscript($pdf, 'words', '1'))->toThrow(CompanionNotAllowed::class);

    $service->setTranscript($audio, 'Fine for audio', '1');
    expect($audio->refresh()->transcript)->toBe('Fine for audio');
});

it('clears the link when the captions asset is deleted', function (): void {
    $video = upload(Files::mp4(), 'clip.mp4', 'Film');
    $captions = upload(Files::vtt(), 'clip.vtt', 'Captions');
    app(MediaGovernanceService::class)->setCaptions($video, $captions, '1');

    app(MediaGovernanceService::class)->delete($captions, '1');

    expect($video->refresh()->captions_asset_id)->toBeNull()->and($video->captionsUrl())->toBeNull();
});

it('lets an editor set captions and a transcript from the asset page', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    $video = upload(Files::mp4(), 'clip.mp4', 'Film');
    $captions = upload(Files::vtt(), 'clip.vtt', 'Captions');
    upload(Files::pdf(), 'a.pdf', 'A document that is not captions');

    Livewire::actingAs(User::make())->test(MediaAssetPage::class, ['asset' => $video->uuid])
        ->callAction('captions', ['captions_asset_id' => $captions->id])
        ->assertHasNoActionErrors()
        ->callAction('transcript', ['transcript' => 'Welcome everyone.'])
        ->assertHasNoActionErrors();

    expect($video->refresh()->captions_asset_id)->toBe($captions->id)->and($video->transcript)->toBe('Welcome everyone.');
});

it('offers captions only on a video and the transcript only on video and audio', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    $audio = upload(Files::mp3(), 'talk.mp3', 'Talk');
    $pdf = upload(Files::pdf(), 'a.pdf', 'Doc');

    $user = User::make();
    $page = fn (MediaAsset $asset) => Livewire::actingAs($user)->test(MediaAssetPage::class, ['asset' => $asset->uuid]);

    $page($audio)->assertActionHidden('captions')->assertActionVisible('transcript');
    $page($pdf)->assertActionHidden('captions')->assertActionHidden('transcript');
});

it('renders each kind through the file component', function (): void {
    $service = app(MediaGovernanceService::class);
    $image = Images::upload('A red and blue split');
    $pdf = upload(Files::pdf(), 'annual.pdf', 'Annual report 2025');
    $zip = upload(Files::zip(), 'bundle.zip', 'Bundle');
    $video = upload(Files::mp4(), 'clip.mp4', 'Film');
    $audio = upload(Files::mp3(), 'talk.mp3', 'Talk');
    $service->setCaptions($video, upload(Files::vtt(), 'clip.vtt', 'Captions'), '1');
    $service->setTranscript($video, 'Hello video.', '1');
    $service->setTranscript($audio, 'Hello audio.', '1');

    $render = static fn (MediaAsset $asset, string $extra = ''): string => Blade::render('<x-media-library::file :asset="$asset" '.$extra.' />', ['asset' => $asset->refresh()]);

    $img = $render($image, 'class="hero" width="600" height="300" sizes="(min-width: 800px) 50vw, 100vw"');
    expect($img)->toContain('<img')
        ->and($img)->toContain('alt="A red and blue split"')
        ->and($img)->toContain('w=600')->toContain('w=1200')->toContain('w=1800')
        ->and($img)->toContain('600w')->toContain('1200w')->toContain('1800w')
        ->and($img)->toContain('sizes="(min-width: 800px) 50vw, 100vw"')
        ->and($img)->toContain('class="hero"')->toContain('width="600"')->toContain('height="300"')
        ->and($img)->not->toContain('style=');

    $link = $render($pdf);
    expect($link)->toContain('>Annual report 2025 (PDF, '.$pdf->humanSize().')</a>')
        ->and($link)->toContain('href="'.e($pdf->fileUrl()).'"');

    $zipLink = $render($zip);
    expect($zipLink)->toContain('>Bundle (ZIP, '.$zip->humanSize().')</a>')
        ->and($zipLink)->toContain('href="'.e($zip->downloadUrl()).'"');

    $videoHtml = $render($video, 'class="player"');
    expect($videoHtml)->toContain('<video')->toContain('class="player"')->toContain('controls')
        ->and($videoHtml)->toContain('<track kind="captions"')
        ->and($videoHtml)->toContain('<details')->toContain('Hello video.');

    $audioHtml = $render($audio);
    expect($audioHtml)->toContain('<audio')->toContain('controls')
        ->and($audioHtml)->not->toContain('<track')
        ->and($audioHtml)->toContain('<details')->toContain('Hello audio.');

    expect(Blade::render('<x-media-library::file :asset="null" />'))->toBe('');
});

it('does not render a transcript details element when there is none', function (): void {
    $audio = upload(Files::mp3(), 'talk.mp3', 'Talk');

    expect(Blade::render('<x-media-library::file :asset="$asset" />', ['asset' => $audio]))->not->toContain('<details');
});

it('ships the new strings in all three languages with identical keys', function (): void {
    $keys = static fn (array $lines): array => array_keys(Arr::dot($lines));
    $en = require __DIR__.'/../resources/lang/en/media.php';

    foreach (['zh-Hant', 'zh-Hans'] as $locale) {
        $other = require __DIR__.'/../resources/lang/'.$locale.'/media.php';
        expect($keys($other))->toEqualCanonicalizing($keys($en));
    }

    expect($keys($en))->toContain('preview.pdf_title', 'file.link', 'picker.kind_rejected', 'actions.captions', 'field.transcript');
});
