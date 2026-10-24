@props([
    'asset' => null,
    'width' => null,
    'height' => null,
    'sizes' => '100vw',
])

{{--
    One asset, rendered the way its kind wants. An image gets a
    responsive <img>; a document or archive a link whose text says what it is and
    how big (WCAG 2.4.4); a video and an audio file a native player, with captions
    and the transcript when the asset has them. No inline script or style, so a
    strict Content-Security-Policy is fine.

    <x-media-library::file :asset="$asset" class="..." />
--}}
{{--
    A file in a members-only or password-protected folder is not for the public. When the
    visitor may not have it, nothing about it is printed: no URL, title or filename, just a marker
    that something is withheld. A signed-in user who may browse the folder gets the file.
--}}
@if ($asset instanceof \Banyan\FilamentMediaLibrary\Models\MediaAsset && ! $asset->isDeliverable())
    <span class="ml-library__file-locked" role="img" aria-label="{{ __('media-library::media.access.restricted_file') }}">
        <x-filament::icon icon="heroicon-o-lock-closed" aria-hidden="true" />
    </span>
@elseif ($asset instanceof \Banyan\FilamentMediaLibrary\Models\MediaAsset)
    @php
        $kind = $asset->kind;
        $captionsUrl = $asset->captionsUrl();
    @endphp

    @if ($kind === \Banyan\FilamentMediaLibrary\Enums\MediaKind::Image)
        <img
            {{ $attributes->merge(['loading' => 'lazy', 'decoding' => 'async']) }}
            src="{{ $asset->url(1200) }}"
            srcset="{{ $asset->url(600) }} 600w, {{ $asset->url(1200) }} 1200w, {{ $asset->url(1800) }} 1800w"
            sizes="{{ $sizes }}"
            alt="{{ $asset->alt_text }}"
            @if ($width) width="{{ $width }}" @endif
            @if ($height) height="{{ $height }}" @endif
        >
    @elseif ($kind === \Banyan\FilamentMediaLibrary\Enums\MediaKind::Video)
        <video
            {{ $attributes }}
            controls
            preload="metadata"
            src="{{ $asset->fileUrl() }}"
            @if ($width) width="{{ $width }}" @endif
            @if ($height) height="{{ $height }}" @endif
        >
            @if ($captionsUrl !== null)
                <track kind="captions" src="{{ $captionsUrl }}" label="{{ __('media-library::media.preview.captions_label') }}">
            @endif
            <a href="{{ $asset->downloadUrl() }}">{{ __('media-library::media.preview.video_unsupported') }} {{ $asset->displayName() }}</a>
        </video>
    @elseif ($kind === \Banyan\FilamentMediaLibrary\Enums\MediaKind::Audio)
        <audio {{ $attributes }} controls preload="metadata" src="{{ $asset->fileUrl() }}">
            <a href="{{ $asset->downloadUrl() }}">{{ __('media-library::media.preview.audio_unsupported') }} {{ $asset->displayName() }}</a>
        </audio>
    @else
        {{-- A PDF opens in the browser's own viewer; every other document and archive downloads. --}}
        <a
            {{ $attributes }}
            href="{{ \Banyan\FilamentMediaLibrary\Delivery\FileDelivery::isInline($asset) ? $asset->fileUrl() : $asset->downloadUrl() }}"
        >{{ __('media-library::media.file.link', ['title' => $asset->displayName(), 'format' => $asset->formatLabel(), 'size' => $asset->humanSize()]) }}</a>
    @endif

    @if (in_array($kind, [\Banyan\FilamentMediaLibrary\Enums\MediaKind::Video, \Banyan\FilamentMediaLibrary\Enums\MediaKind::Audio], true) && filled($asset->transcript))
        <details class="ml-library__transcript">
            <summary>{{ __('media-library::media.preview.transcript') }}</summary>
            <p>{{ $asset->transcript }}</p>
        </details>
    @endif
@endif
