<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin\Support;

use Banyan\FilamentMediaLibrary\Models\MediaFolder;

/** One row of the sidebar tree, already resolved so the view only prints it. */
final readonly class FolderNode
{
    /** @param list<FolderNode> $children */
    public function __construct(
        public MediaFolder $folder,
        public array $children,
        public bool $expanded,
        public bool $current,
        /** Password-protected and not unlocked: shown, with nothing inside it visible. */
        public bool $locked = false,
        /** The folder is members-only (and the viewer is one of the members). */
        public bool $membersOnly = false,
    ) {}

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }
}
