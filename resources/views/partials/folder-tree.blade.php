@php
    /** @var list<\Banyan\FilamentMediaLibrary\Admin\Support\FolderNode> $nodes */
    /** @var \Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage $this */
@endphp
<ul role="group" class="bn-media__tree-group">
    @foreach ($nodes as $node)
        <li
            role="treeitem"
            wire:key="tree-{{ $node->folder->uuid }}"
            aria-selected="{{ $node->current ? 'true' : 'false' }}"
            @if ($node->hasChildren()) aria-expanded="{{ $node->expanded ? 'true' : 'false' }}" @endif
        >
            <div class="bn-media__tree-row">
                @if ($node->hasChildren())
                    <button
                        type="button"
                        class="bn-media__tree-toggle"
                        wire:click="toggleFolder('{{ $node->folder->uuid }}')"
                        aria-label="{{ __($node->expanded ? 'media-library::media.folders.collapse' : 'media-library::media.folders.expand', ['name' => $node->folder->name]) }}"
                    >
                        <x-filament::icon :icon="$node->expanded ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'" aria-hidden="true" />
                    </button>
                @else
                    <span class="bn-media__tree-spacer" aria-hidden="true"></span>
                @endif

                <button
                    type="button"
                    class="bn-media__tree-link {{ $node->current ? 'is-current' : '' }}"
                    wire:click="openFolder('{{ $node->folder->uuid }}')"
                    @if ($node->current) aria-current="true" @endif
                >
                    <x-filament::icon :icon="$node->current ? 'heroicon-o-folder-open' : 'heroicon-o-folder'" aria-hidden="true" />
                    <span>{{ $node->folder->name }}</span>
                    @if ($node->locked)
                        <x-filament::icon icon="heroicon-m-lock-closed" class="bn-media__tree-badge" aria-hidden="true" />
                        <span class="bn-media__sr">{{ __('media-library::media.access.locked') }}</span>
                    @elseif ($node->membersOnly)
                        <x-filament::icon icon="heroicon-m-user-group" class="bn-media__tree-badge" aria-hidden="true" />
                        <span class="bn-media__sr">{{ __('media-library::media.access.members_only') }}</span>
                    @endif
                </button>
            </div>

            @if ($node->hasChildren() && $node->expanded)
                @include('media-library::partials.folder-tree', ['nodes' => $node->children])
            @endif
        </li>
    @endforeach
</ul>
