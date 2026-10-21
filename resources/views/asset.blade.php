@php
    /** @var \Banyan\FilamentMediaLibrary\Admin\MediaAssetPage $this */
    $asset = $this->record();
@endphp

<x-filament-panels::page>
    @if ($asset === null)
        <x-filament::section>
            <p class="bn-empty">{{ __('media-library::media.not_found') }}</p>
        </x-filament::section>
    @else
        <p class="bn-media__back">
            <a href="{{ $this->libraryUrl() }}">&larr; {{ __('media-library::media.actions.back') }}</a>
        </p>

        @if ($asset->isExpired())
            <div class="bn-media__banner bn-media__banner--expired" role="status">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="bn-media__banner-icon" />
                <span>{{ __('media-library::media.expired') }} — {{ $asset->license_expires_at?->toFormattedDateString() }}</span>
            </div>
        @endif

        <div class="bn-media__detail">
            <x-filament::section :heading="__('media-library::media.detail.focal_heading')">
                <p id="bn-media-focal-help" class="bn-meta bn-media__focal-help">{{ __('media-library::media.detail.focal_help') }}</p>

                {{-- The crosshair. Fractions of the *rendered* box, so the same
                     value is correct at any preview size and after a replacement
                     of different intrinsic dimensions. --}}
                <div
                    class="bn-media__focal"
                    x-data="{
                        x: {{ $asset->focal_x }},
                        y: {{ $asset->focal_y }},
                        dragging: false,
                        manage: {{ $this->canManage() ? 'true' : 'false' }},
                        positionTemplate: @js(__('media-library::media.detail.focal_position')),
                        clamp(v) { return Math.min(1, Math.max(0, v)) },
                        fromEvent(event) {
                            if (! this.manage) return
                            const box = this.$refs.stage.getBoundingClientRect()
                            if (box.width === 0 || box.height === 0) return
                            this.x = this.clamp((event.clientX - box.left) / box.width)
                            this.y = this.clamp((event.clientY - box.top) / box.height)
                        },
                        nudge(dx, dy, big) {
                            if (! this.manage) return
                            const step = big ? 0.05 : 0.01
                            this.x = this.clamp(this.x + dx * step)
                            this.y = this.clamp(this.y + dy * step)
                        },
                        save() {
                            if (! this.manage) return
                            $wire.call('storeFocalPoint', Number(this.x.toFixed(5)), Number(this.y.toFixed(5)))
                        },
                        // Shared by the stage's own aria-label (read once, on
                        // focus) and the visible readout below it, which is the
                        // one that is actually kept up to date for assistive
                        // technology: a screen reader does not reliably
                        // re-announce an accessible-name change on an element
                        // that already has focus, but it does announce a change
                        // inside a live region (UX-0006 accessibility pass).
                        label() {
                            return this.positionTemplate
                                .replace(':x', this.x.toFixed(2))
                                .replace(':y', this.y.toFixed(2));
                        },
                    }"
                >
                    <div
                        class="bn-media__stage"
                        x-ref="stage"
                        role="application"
                        tabindex="0"
                        :aria-label="label()"
                        aria-describedby="bn-media-focal-help"
                        @mousedown.prevent="dragging = true; fromEvent($event)"
                        @mousemove="dragging && fromEvent($event)"
                        @mouseup.window="dragging = false"
                        @mouseleave="dragging = false"
                        @keydown.arrow-left.prevent="nudge(-1, 0, $event.shiftKey)"
                        @keydown.arrow-right.prevent="nudge(1, 0, $event.shiftKey)"
                        @keydown.arrow-up.prevent="nudge(0, -1, $event.shiftKey)"
                        @keydown.arrow-down.prevent="nudge(0, 1, $event.shiftKey)"
                    >
                        <img
                            class="bn-media__preview"
                            src="{{ $this->previewUrl($asset) }}"
                            alt="{{ $asset->alt_text }}"
                            draggable="false"
                        />

                        <span
                            class="bn-media__crosshair"
                            aria-hidden="true"
                            :style="`left: ${x * 100}%; top: ${y * 100}%`"
                        ></span>
                    </div>

                    <div class="bn-media__focal-controls">
                        {{-- role="status" makes every drag/nudge announce the new
                             position politely, independent of whether the stage
                             itself still has focus. --}}
                        <span class="bn-media__focal-readout" role="status" aria-live="polite" x-text="label()"></span>

                        @if ($this->canManage())
                            <x-filament::button size="sm" color="gray" x-on:click="x = 0.5; y = 0.5">
                                {{ __('media-library::media.actions.reset_focal') }}
                            </x-filament::button>

                            <x-filament::button size="sm" x-on:click="save()">
                                {{ __('media-library::media.actions.save_focal') }}
                            </x-filament::button>
                        @endif
                    </div>

                    {{-- Two saved crops, so the focal point's effect is visible
                         rather than promised. --}}
                    <div class="bn-media__crops">
                        <figure class="bn-media__crop">
                            <img src="{{ $this->cropPreviewUrl($asset, 240, 240) }}" alt="" width="120" height="120" />
                            <figcaption class="bn-meta">1:1</figcaption>
                        </figure>
                        <figure class="bn-media__crop">
                            <img src="{{ $this->cropPreviewUrl($asset, 320, 120) }}" alt="" width="160" height="60" />
                            <figcaption class="bn-meta">8:3</figcaption>
                        </figure>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('media-library::media.detail.facts_heading')">
                <dl class="bn-media__facts">
                    <dt>{{ __('media-library::media.detail.fact_alt') }}</dt>
                    <dd>{{ $asset->alt_text }}</dd>

                    <dt>{{ __('media-library::media.detail.fact_filename') }}</dt>
                    <dd>{{ $asset->filename }}</dd>

                    <dt>{{ __('media-library::media.detail.fact_type') }}</dt>
                    <dd>{{ $asset->mime_type }}</dd>

                    <dt>{{ __('media-library::media.detail.fact_dimensions') }}</dt>
                    <dd>{{ $asset->dimensions() ?? __('media-library::media.unknown_dimensions') }}</dd>

                    <dt>{{ __('media-library::media.detail.fact_size') }}</dt>
                    <dd>{{ $asset->humanSize() }}</dd>

                    <dt>{{ __('media-library::media.detail.fact_license') }}</dt>
                    <dd>
                        @if ($asset->license_expires_at === null)
                            {{ __('media-library::media.detail.fact_license_none') }}
                        @else
                            {{ $asset->license_expires_at->toFormattedDateString() }}
                        @endif
                    </dd>

                    <dt>{{ __('media-library::media.detail.fact_tags') }}</dt>
                    <dd>
                        @if ($asset->tags->isEmpty())
                            <span class="bn-meta">{{ __('media-library::media.detail.fact_tags_none') }}</span>
                        @else
                            @foreach ($asset->tags as $tag)
                                <span class="bn-chip">{{ $tag->name }}</span>
                            @endforeach
                        @endif
                    </dd>

                    <dt>{{ __('media-library::media.detail.fact_disk') }}</dt>
                    <dd class="bn-media__mono">{{ $asset->disk }}</dd>

                    <dt>{{ __('media-library::media.detail.fact_uuid') }}</dt>
                    <dd class="bn-media__mono">{{ $asset->uuid }}</dd>
                </dl>
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('media-library::media.detail.usage_heading')">
            <p class="bn-media__usage-count">{{ $this->usageSummary() }}</p>

            @php $usages = $this->usages(); @endphp

            @if ($usages === [])
                <p class="bn-empty">{{ __('media-library::media.detail.usage_empty') }}</p>
            @else
                <div class="bn-media__table-wrap">
                    <table class="bn-media__table">
                        <thead>
                            <tr>
                                <th>{{ __('media-library::media.detail.usage_col_record') }}</th>
                                <th>{{ __('media-library::media.detail.usage_col_type') }}</th>
                                <th>{{ __('media-library::media.detail.usage_col_field') }}</th>
                                <th>{{ __('media-library::media.detail.usage_col_locale') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($usages as $index => $usage)
                                <tr @class(['bn-media__row--alt' => $index % 2 === 1])>
                                    <td class="bn-media__mono">
                                        @if ($usage->url !== null)
                                            <a href="{{ $usage->url }}">{{ $usage->label }}</a>
                                        @else
                                            {{ $usage->label }}
                                        @endif
                                    </td>
                                    <td>{{ $usage->type }}</td>
                                    <td class="bn-media__mono">{{ $usage->field }}</td>
                                    <td>{{ $usage->locale ?? __('media-library::media.shared_locale') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('media-library::media.detail.versions_heading')">
            <div class="bn-media__table-wrap">
                <table class="bn-media__table">
                    <thead>
                        <tr>
                            <th>{{ __('media-library::media.detail.versions_col_version') }}</th>
                            <th>{{ __('media-library::media.detail.versions_col_dimensions') }}</th>
                            <th>{{ __('media-library::media.detail.versions_col_size') }}</th>
                            <th>{{ __('media-library::media.detail.versions_col_checksum') }}</th>
                            <th>{{ __('media-library::media.detail.versions_col_uploaded') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->versions() as $index => $version)
                            <tr wire:key="version-{{ $version->id }}" @class(['bn-media__row--alt' => $index % 2 === 1])>
                                <td>
                                    #{{ $version->id }}
                                    @if ($version->id === $asset->current_version_id)
                                        <span class="bn-chip">{{ __('media-library::media.detail.versions_current') }}</span>
                                    @endif
                                </td>
                                <td>{{ $version->width !== null && $version->height !== null ? $version->width.' × '.$version->height : '—' }}</td>
                                <td>{{ $version->size_bytes }}</td>
                                <td class="bn-media__mono">{{ $version->shortChecksum() }}</td>
                                <td>{{ $version->created_at?->toDayDateTimeString() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
