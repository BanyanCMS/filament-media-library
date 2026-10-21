@php
    /** @var \Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage $this */
    $assets = $this->assets();
    $tags = $this->tagOptions();
    $expiry = $this->expirySummary();
@endphp

<x-filament-panels::page>
<div class="bn-media">
    <x-filament::section>
        <p class="bn-media__intro">{{ __('media-library::media.intro') }}</p>

        @if ($expiry->hasIssues())
            <div
                class="bn-media__expiry-alert"
                role="{{ $expiry->expiredReferenced > 0 ? 'alert' : 'status' }}"
                aria-labelledby="bn-media-expiry-heading"
            >
                <div class="bn-media__expiry-copy">
                    <div class="bn-media__expiry-title" id="bn-media-expiry-heading">
                        <x-filament::icon icon="heroicon-o-shield-exclamation" class="bn-media__banner-icon" />
                        <strong>{{ __('media-library::media.expiry.heading') }}</strong>
                    </div>
                    <p>
                        {{ trans_choice('media-library::media.expiry.expired', $expiry->expired, ['count' => $expiry->expired]) }}
                        ·
                        {{ trans_choice('media-library::media.expiry.soon', $expiry->expiringSoon, ['count' => $expiry->expiringSoon, 'days' => $expiry->warningDays]) }}
                    </p>
                    @if ($expiry->expiredReferenced > 0)
                        <p class="bn-media__expiry-critical">
                            {{ trans_choice('media-library::media.expiry.referenced', $expiry->expiredReferenced, ['count' => $expiry->expiredReferenced]) }}
                        </p>
                    @endif
                </div>

                <div class="bn-media__expiry-actions">
                    @if ($expiry->expired > 0)
                        <x-filament::button size="sm" color="gray" wire:click="showExpiredAssets">
                            {{ __('media-library::media.expiry.review_expired') }}
                        </x-filament::button>
                    @endif
                    @if ($expiry->expiringSoon > 0)
                        <x-filament::button size="sm" color="gray" wire:click="showExpiringAssets">
                            {{ __('media-library::media.expiry.review_soon') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        @endif

        <div class="bn-media__filters" role="search">
            {{-- Filament's own inputs, so the filters look like every other form in the panel. --}}
            <div class="bn-media__filter">
                <label class="bn-media__filter-label" for="bn-media-search">{{ __('media-library::media.filter.search') }}</label>
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        id="bn-media-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        :placeholder="__('media-library::media.filter.search')"
                    />
                </x-filament::input.wrapper>
            </div>

            <div class="bn-media__filter">
                <label class="bn-media__filter-label" for="bn-media-tag">{{ __('media-library::media.filter.tag') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="bn-media-tag" wire:model.live="tagId">
                        <option value="">{{ __('media-library::media.filter.all_tags') }}</option>
                        @foreach ($tags as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <label class="bn-media__filter bn-media__filter--check">
                <x-filament::input.checkbox wire:model.live="expiredOnly" />
                <span>{{ __('media-library::media.filter.expired_only') }}</span>
            </label>

            @if ($this->isFiltered())
                <x-filament::button size="sm" color="gray" wire:click="resetFilters">
                    {{ __('media-library::media.filter.reset') }}
                </x-filament::button>
            @endif
        </div>

        @php $total = $this->totalAssets(); @endphp
        <p class="bn-media__meta bn-media__count" aria-live="polite">
            {{ count($assets) < $total
                ? __('media-library::media.count_partial', ['shown' => count($assets), 'total' => $total])
                : trans_choice('media-library::media.count', $total, ['count' => $total]) }}
        </p>

        @if ($assets === [])
            <p class="bn-media__empty">
                {{ $this->isFiltered() ? __('media-library::media.empty_filtered') : __('media-library::media.empty') }}
            </p>
        @else
            <ul class="bn-media__grid" role="list">
                @foreach ($assets as $asset)
                    <li class="bn-media__card" wire:key="asset-{{ $asset->id }}">
                        {{-- The link's own accessible name, not the image's alt
                             text: an asset missing alt text (flagged below) would
                             otherwise leave this link with no name at all for a
                             screen reader — exactly the asset that most needs one. --}}
                        <a
                            class="bn-media__thumb-link"
                            href="{{ $this->assetUrl($asset) }}"
                            aria-label="{{ __('media-library::media.actions.open_named', ['name' => $asset->filename]) }}"
                        >
                            <img
                                class="bn-media__thumb"
                                src="{{ $this->thumbnailUrl($asset) }}"
                                alt=""
                                loading="lazy"
                                width="320"
                                height="240"
                            />
                        </a>

                        <div class="bn-media__card-body">
                            <a class="bn-media__filename" href="{{ $this->assetUrl($asset) }}">{{ $asset->filename }}</a>

                            <p class="bn-media__meta bn-media__dims">
                                {{ $asset->dimensions() ?? __('media-library::media.unknown_dimensions') }} · {{ $asset->humanSize() }}
                            </p>

                            <div class="bn-media__flags">
                                @if (trim($asset->alt_text) === '')
                                    <span class="bn-media__chip bn-media__flag--warn">{{ __('media-library::media.no_alt') }}</span>
                                @else
                                    <span class="bn-media__meta bn-media__flag--ok">{{ __('media-library::media.has_alt') }}</span>
                                @endif

                                @if ($asset->isExpired())
                                    <span class="bn-media__chip bn-media__flag--expired">{{ __('media-library::media.expired') }}</span>
                                @elseif ($asset->license_expires_at !== null)
                                    <span class="bn-media__meta">{{ __('media-library::media.expires', ['date' => $asset->license_expires_at->toFormattedDateString()]) }}</span>
                                @endif
                            </div>

                            @if ($asset->tags->isNotEmpty())
                                <div class="bn-media__tags">
                                    @foreach ($asset->tags as $tag)
                                        <span class="bn-media__chip">{{ $tag->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @if ($this->canDelete($asset))
                            <div class="bn-media__card-actions">
                                <x-filament::icon-button
                                    icon="heroicon-o-trash"
                                    color="danger"
                                    :label="__('media-library::media.actions.delete')"
                                    wire:click="mountAction('deleteAsset', { asset: {{ $asset->id }} })"
                                />
                            </div>
                        @elseif ($this->canManage())
                            <span
                                class="bn-media__protected"
                                role="img"
                                aria-label="{{ trans_choice('media-library::media.delete_blocked', $this->usageCount($asset), ['count' => $this->usageCount($asset)]) }}"
                            >
                                <x-filament::icon icon="heroicon-o-lock-closed" aria-hidden="true" />
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if (count($assets) < $total)
                <div class="bn-media__more">
                    <x-filament::button color="gray" wire:click="loadMore" wire:loading.attr="disabled">
                        {{ __('media-library::media.actions.load_more') }}
                    </x-filament::button>
                </div>
            @endif
        @endif
    </x-filament::section>
</div>
</x-filament-panels::page>
