@php
    /** @var \Banyan\FilamentMediaLibrary\Pages\MediaLibraryPage $this */
    $assets = $this->assets();
    $tags = $this->tagOptions();
    $expiry = $this->expirySummary();
    $folders = $this->foldersEnabled();
    $currentFolder = $this->currentFolder();
    $subfolders = $folders && ! $this->isFiltered() ? $this->subfolders() : [];
    $canSelect = $this->canManageFolders();
    $locked = $this->lockedFolder();
@endphp

<x-filament-panels::page>
<div class="ml-library">
    <x-filament::section>
        <p class="ml-library__intro">{{ __('media-library::media.intro') }}</p>

        @if ($expiry->hasIssues())
            <div
                class="ml-library__expiry-alert"
                role="{{ $expiry->expiredReferenced > 0 ? 'alert' : 'status' }}"
                aria-labelledby="ml-expiry-heading"
            >
                <div class="ml-library__expiry-copy">
                    <div class="ml-library__expiry-title" id="ml-expiry-heading">
                        <x-filament::icon icon="heroicon-o-shield-exclamation" class="ml-library__banner-icon" />
                        <strong>{{ __('media-library::media.expiry.heading') }}</strong>
                    </div>
                    <p>
                        {{ trans_choice('media-library::media.expiry.expired', $expiry->expired, ['count' => $expiry->expired]) }}
                        ·
                        {{ trans_choice('media-library::media.expiry.soon', $expiry->expiringSoon, ['count' => $expiry->expiringSoon, 'days' => $expiry->warningDays]) }}
                    </p>
                    @if ($expiry->expiredReferenced > 0)
                        <p class="ml-library__expiry-critical">
                            {{ trans_choice('media-library::media.expiry.referenced', $expiry->expiredReferenced, ['count' => $expiry->expiredReferenced]) }}
                        </p>
                    @endif
                </div>

                <div class="ml-library__expiry-actions">
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

        <div class="ml-library__filters" role="search">
            {{-- Filament's own inputs, so the filters look like every other form in the panel. --}}
            <div class="ml-library__filter">
                <label class="ml-library__filter-label" for="ml-search">{{ __('media-library::media.filter.search') }}</label>
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        id="ml-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        :placeholder="__('media-library::media.filter.search')"
                    />
                </x-filament::input.wrapper>
            </div>

            <div class="ml-library__filter">
                <label class="ml-library__filter-label" for="ml-tag">{{ __('media-library::media.filter.tag') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="ml-tag" wire:model.live="tagId">
                        <option value="">{{ __('media-library::media.filter.all_tags') }}</option>
                        @foreach ($tags as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <label class="ml-library__filter ml-library__filter--check">
                <x-filament::input.checkbox wire:model.live="expiredOnly" />
                <span>{{ __('media-library::media.filter.expired_only') }}</span>
            </label>

            @if ($this->isFiltered())
                <x-filament::button size="sm" color="gray" wire:click="resetFilters">
                    {{ __('media-library::media.filter.reset') }}
                </x-filament::button>
            @endif
        </div>

        @if ($folders && $currentFolder !== null && $this->isFiltered())
            <label class="ml-library__filter ml-library__filter--check">
                <x-filament::input.checkbox wire:model.live="wholeLibrary" />
                <span>{{ __('media-library::media.folders.whole_library') }}</span>
            </label>
        @endif

        <div class="{{ $folders ? 'ml-library__layout' : '' }}">
        @if ($folders)
            <nav class="ml-library__folders" aria-label="{{ __('media-library::media.folders.nav') }}">
                <ul role="tree" class="ml-library__tree" aria-label="{{ __('media-library::media.folders.nav') }}">
                    <li role="treeitem" aria-selected="{{ $currentFolder === null ? 'true' : 'false' }}" aria-expanded="true">
                        <div class="ml-library__tree-row">
                            <span class="ml-library__tree-spacer" aria-hidden="true"></span>
                            <button
                                type="button"
                                class="ml-library__tree-link {{ $currentFolder === null ? 'is-current' : '' }}"
                                wire:click="openFolder"
                                @if ($currentFolder === null) aria-current="true" @endif
                            >
                                <x-filament::icon icon="heroicon-o-home" aria-hidden="true" />
                                <span>{{ __('media-library::media.folders.all') }}</span>
                            </button>
                        </div>
                        @include('media-library::partials.folder-tree', ['nodes' => $this->folderTree()])
                    </li>
                </ul>
            </nav>
        @endif

        <div class="ml-library__main">
        @if ($folders && $currentFolder !== null)
            <nav aria-label="{{ __('media-library::media.folders.breadcrumb') }}">
                <ol class="ml-library__crumbs">
                    <li>
                        <button type="button" class="ml-library__crumb-link" wire:click="openFolder">{{ __('media-library::media.folders.all') }}</button>
                    </li>
                    @foreach ($this->breadcrumbs() as $crumb)
                        <li>
                            @if ($crumb->id === $currentFolder->id)
                                <span class="ml-library__crumb-current" aria-current="page">{{ $crumb->name }}</span>
                            @else
                                <button type="button" class="ml-library__crumb-link" wire:click="openFolder('{{ $crumb->uuid }}')">{{ $crumb->name }}</button>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif

        @if ($canSelect && count($selected) > 0)
            <div class="ml-library__selection" role="status">
                <span>{{ trans_choice('media-library::media.folders.selected', count($selected), ['count' => count($selected)]) }}</span>
                <x-filament::button size="sm" color="gray" wire:click="mountAction('moveSelected')">
                    {{ __('media-library::media.folders.move_selected') }}
                </x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="$set('selected', [])">
                    {{ __('media-library::media.folders.clear_selection') }}
                </x-filament::button>
            </div>
        @endif

        @if ($locked !== null)
            {{-- A password folder waits to be unlocked: no counts, no files, no sub-folders. --}}
            <div class="ml-library__locked-panel" role="status">
                <x-filament::icon icon="heroicon-o-lock-closed" class="ml-library__banner-icon" aria-hidden="true" />
                <strong>{{ __('media-library::media.access.locked_heading') }}</strong>
                <p class="ml-library__meta">{{ __('media-library::media.access.locked_text', ['minutes' => max(1, (int) config('media-library.folders.unlock_minutes', 30))]) }}</p>
                <x-filament::button icon="heroicon-o-lock-open" wire:click="mountAction('unlockFolder')">
                    {{ __('media-library::media.access.unlock') }}
                </x-filament::button>
            </div>
        @else
        @php $total = $this->totalAssets(); @endphp
        <p class="ml-library__meta ml-library__count" aria-live="polite">
            {{ count($assets) < $total
                ? __('media-library::media.count_partial', ['shown' => count($assets), 'total' => $total])
                : trans_choice('media-library::media.count', $total, ['count' => $total]) }}
        </p>

        @if ($assets === [] && $subfolders === [])
            <p class="ml-library__empty">
                @if ($this->isFiltered())
                    {{ __('media-library::media.empty_filtered') }}
                @elseif ($currentFolder !== null)
                    {{ __('media-library::media.folders.empty') }}
                @else
                    {{ __('media-library::media.empty') }}
                @endif
            </p>
        @else
            <ul class="ml-library__grid" role="list">
                @foreach ($subfolders as $subfolder)
                    @php
                        // WCAG 2.5.3 (label in name): the accessible name must contain every word shown on the tile.
                        $folderLocked = $this->isFolderLocked($subfolder);
                        $folderItems = trans_choice('media-library::media.folders.items', $this->itemCount($subfolder), ['count' => $this->itemCount($subfolder)]);
                        $folderName = __('media-library::media.folders.open_named', ['name' => $subfolder->name])
                            .' '.($folderLocked ? __('media-library::media.access.locked') : $folderItems)
                            .(! $folderLocked && $this->isFolderMembersOnly($subfolder) ? ' '.__('media-library::media.access.members_only') : '');
                    @endphp
                    <li class="ml-library__card ml-library__folder-card" wire:key="folder-{{ $subfolder->id }}">
                        <button
                            type="button"
                            class="ml-library__folder-link"
                            wire:click="openFolder('{{ $subfolder->uuid }}')"
                            aria-label="{{ $folderName }}"
                        >
                            <x-filament::icon icon="heroicon-o-folder" aria-hidden="true" />
                            <span class="ml-library__folder-name">{{ $subfolder->name }}</span>
                            @if ($folderLocked)
                                <span class="ml-library__chip ml-library__flag--warn">
                                    <x-filament::icon icon="heroicon-m-lock-closed" aria-hidden="true" />
                                    {{ __('media-library::media.access.locked') }}
                                </span>
                            @else
                                <span class="ml-library__meta">{{ $folderItems }}</span>
                                @if ($this->isFolderMembersOnly($subfolder))
                                    <span class="ml-library__chip">{{ __('media-library::media.access.members_only') }}</span>
                                @endif
                            @endif
                        </button>
                    </li>
                @endforeach

                @foreach ($assets as $asset)
                    <li class="ml-library__card" wire:key="asset-{{ $asset->id }}">
                        @if ($canSelect)
                            <label class="ml-library__select">
                                <x-filament::input.checkbox
                                    wire:model.live="selected"
                                    :value="$asset->id"
                                    :aria-label="__('media-library::media.folders.select', ['name' => $asset->filename])"
                                />
                            </label>
                        @endif
                        {{-- The link's own accessible name, not the image's alt
                             text: an asset missing alt text (flagged below) would
                             otherwise leave this link with no name at all for a
                             screen reader — exactly the asset that most needs one. --}}
                        <a
                            class="ml-library__thumb-link"
                            href="{{ $this->assetUrl($asset) }}"
                            aria-label="{{ __('media-library::media.actions.open_named', ['name' => $asset->filename]) }}"
                        >
                            @if ($asset->isImage())
                                <img
                                    class="ml-library__thumb"
                                    src="{{ $this->thumbnailUrl($asset) }}"
                                    alt=""
                                    loading="lazy"
                                    width="320"
                                    height="240"
                                />
                            @elseif (($pdfThumbnail = $this->pdfThumbnailUrl($asset)) !== null)
                                <img
                                    class="ml-library__thumb ml-library__thumb--page"
                                    src="{{ $pdfThumbnail }}"
                                    alt=""
                                    loading="lazy"
                                    width="320"
                                    height="240"
                                />
                            @else
                                <span class="ml-library__thumb ml-library__kind-icon">
                                    <x-filament::icon :icon="$asset->kind->icon()" aria-hidden="true" />
                                </span>
                            @endif
                        </a>

                        <div class="ml-library__card-body">
                            {{-- A person names a document ("Annual report 2025"); a filename is what a camera called a picture. --}}
                            <a class="ml-library__filename" href="{{ $this->assetUrl($asset) }}">{{ $asset->isImage() ? $asset->filename : $asset->displayName() }}</a>

                            <p class="ml-library__meta ml-library__dims">
                                @if ($asset->isImage())
                                    {{ $asset->dimensions() ?? __('media-library::media.unknown_dimensions') }} · {{ $asset->humanSize() }}
                                @else
                                    <span class="ml-library__chip ml-library__kind-badge">{{ $asset->kind->label() }}</span>
                                    {{ $asset->humanSize() }}
                                @endif
                            </p>

                            @if (! $asset->isImage())
                                <p class="ml-library__meta ml-library__mono ml-library__subname">{{ $asset->filename }}</p>
                            @endif

                            <div class="ml-library__flags">
                                @if (! $asset->isImage())
                                    @if (blank($asset->title))
                                        <span class="ml-library__chip ml-library__flag--warn">{{ __('media-library::media.no_title') }}</span>
                                    @endif
                                @elseif (blank($asset->alt_text))
                                    <span class="ml-library__chip ml-library__flag--warn">{{ __('media-library::media.no_alt') }}</span>
                                @else
                                    <span class="ml-library__meta ml-library__flag--ok">{{ __('media-library::media.has_alt') }}</span>
                                @endif

                                @if ($asset->isExpired())
                                    <span class="ml-library__chip ml-library__flag--expired">{{ __('media-library::media.expired') }}</span>
                                @elseif ($asset->license_expires_at !== null)
                                    <span class="ml-library__meta">{{ __('media-library::media.expires', ['date' => $asset->license_expires_at->toFormattedDateString()]) }}</span>
                                @endif
                            </div>

                            @if ($asset->tags->isNotEmpty())
                                <div class="ml-library__tags">
                                    @foreach ($asset->tags as $tag)
                                        <span class="ml-library__chip">{{ $tag->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @if ($this->canDelete($asset))
                            <div class="ml-library__card-actions">
                                <x-filament::icon-button
                                    icon="heroicon-o-trash"
                                    color="danger"
                                    :label="__('media-library::media.actions.delete')"
                                    wire:click="mountAction('deleteAsset', { asset: {{ $asset->id }} })"
                                />
                            </div>
                        @elseif ($this->canManageAsset($asset))
                            <span
                                class="ml-library__protected"
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
                <div class="ml-library__more">
                    <x-filament::button color="gray" wire:click="loadMore" wire:loading.attr="disabled">
                        {{ __('media-library::media.actions.load_more') }}
                    </x-filament::button>
                </div>
            @endif
        @endif
        @endif
        </div>
        </div>
    </x-filament::section>
</div>
</x-filament-panels::page>
