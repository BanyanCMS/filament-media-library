@php
    /** @var list<\Banyan\FilamentMediaLibrary\Folders\FolderNode> $nodes */
    /** @var \Banyan\FilamentMediaLibrary\Pages\MediaLibraryPage $this */
@endphp
<ul role="group" class="ml-library__tree-group">
    @foreach ($nodes as $node)
        <li
            role="treeitem"
            wire:key="tree-{{ $node->folder->uuid }}"
            aria-selected="{{ $node->current ? 'true' : 'false' }}"
            @if ($node->hasChildren()) aria-expanded="{{ $node->expanded ? 'true' : 'false' }}" @endif
        >
            <div class="ml-library__tree-row">
                @if ($node->hasChildren())
                    <button
                        type="button"
                        class="ml-library__tree-toggle"
                        wire:click="toggleFolder('{{ $node->folder->uuid }}')"
                        aria-label="{{ __($node->expanded ? 'media-library::media.folders.collapse' : 'media-library::media.folders.expand', ['name' => $node->folder->name]) }}"
                    >
                        <x-filament::icon :icon="$node->expanded ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'" aria-hidden="true" />
                    </button>
                @else
                    <span class="ml-library__tree-spacer" aria-hidden="true"></span>
                @endif

                <button
                    type="button"
                    class="ml-library__tree-link {{ $node->current ? 'is-current' : '' }}"
                    wire:click="openFolder('{{ $node->folder->uuid }}')"
                    @if ($node->current) aria-current="true" @endif
                >
                    <x-filament::icon :icon="$node->current ? 'heroicon-o-folder-open' : 'heroicon-o-folder'" aria-hidden="true" />
                    <span>{{ $node->folder->name }}</span>
                    @if ($node->locked)
                        <x-filament::icon icon="heroicon-m-lock-closed" class="ml-library__tree-badge" aria-hidden="true" />
                        <span class="ml-library__sr">{{ __('media-library::media.access.locked') }}</span>
                    @elseif ($node->membersOnly)
                        <x-filament::icon icon="heroicon-m-user-group" class="ml-library__tree-badge" aria-hidden="true" />
                        <span class="ml-library__sr">{{ __('media-library::media.access.members_only') }}</span>
                    @endif
                </button>
            </div>

            @if ($node->hasChildren() && $node->expanded)
                @include('media-library::partials.folder-tree', ['nodes' => $node->children])
            @endif
        </li>
    @endforeach
</ul>
