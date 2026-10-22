<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Models;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's role (`view` or `manage`) in a members-only folder.
 *
 * Keyed by (folder, user), so it is read through relations and written with
 * query-builder statements; it has no single-column primary key to save by.
 * {@see MediaFolderService} is the writer, and drops
 * {@see FolderAccess}'s cached roles after each change.
 *
 * @property int $media_folder_id
 * @property int $user_id
 * @property string $role
 */
final class MediaFolderMember extends Model
{
    public function getTable(): string
    {
        return MediaConfig::table('folder_members');
    }

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];
}
