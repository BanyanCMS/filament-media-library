<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Models;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Support\LikeEscape;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder of assets.
 *
 * `path` lists the ids from the top-level folder down to this one, own id
 * included ("/12/45/"), so ancestors and the whole subtree are answered
 * without recursion. Only {@see MediaFolderService}
 * writes it; code that sets `parent_id` by hand leaves `path` and `depth` wrong.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $parent_id
 * @property string $name
 * @property string $path
 * @property int $depth
 * @property string $access
 * @property int|null $owner_id
 * @property int|null $created_by
 * @property int|null $site_id
 * @property CarbonInterface|null $created_at
 * @property int|null $assets_count
 * @property int|null $children_count
 */
final class MediaFolder extends Model
{
    public function getTable(): string
    {
        return MediaConfig::table('folders');
    }

    protected $guarded = [];

    /** A password hash never leaves the model through serialisation. */
    protected $hidden = ['password_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['depth' => 'integer'];
    }

    /**
     * Any write to a folder drops {@see FolderAccess}'s per-request copy of the
     * folder table, so a decision is never made from a tree that has since changed.
     */
    protected static function booted(): void
    {
        $flush = static function (): void {
            app(FolderAccess::class)->forget();
        };

        self::saved($flush);
        self::deleted($flush);
    }

    /** This folder's own mode (`inherit` included); see {@see effectiveAccess()} for what applies. */
    public function accessMode(): FolderAccessMode
    {
        return FolderAccessMode::tryFrom($this->access) ?? FolderAccessMode::Members;
    }

    /** The mode that applies here: the nearest self-or-ancestor that is not `inherit`, `shared` at the top. */
    public function effectiveAccess(): FolderAccessMode
    {
        return app(FolderAccess::class)->effectiveMode($this);
    }

    /** Members-only or password-protected anywhere on the path from the top. */
    public function isRestricted(): bool
    {
        return app(FolderAccess::class)->isRestricted($this);
    }

    /**
     * Folders the user may see in a tree: open ones, and password folders
     * waiting to be unlocked. Folders they may not see are absent from the query.
     *
     * @param  Builder<MediaFolder>  $query
     * @return Builder<MediaFolder>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user = null): Builder
    {
        return $query->whereIntegerInRaw($this->getTable().'.id', app(FolderAccess::class)->listedFolderIds($user));
    }

    /**
     * Folders the user may upload to and edit in.
     *
     * @param  Builder<MediaFolder>  $query
     * @return Builder<MediaFolder>
     */
    public function scopeManageableBy(Builder $query, ?Authenticatable $user = null): Builder
    {
        return $query->whereIntegerInRaw($this->getTable().'.id', app(FolderAccess::class)->manageableFolderIds($user));
    }

    /** @return BelongsTo<MediaFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<MediaFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<MediaAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }

    /**
     * Members of a members-only folder, written by {@see MediaFolderService::addMember()}.
     *
     * @return HasMany<MediaFolderMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(MediaFolderMember::class, 'media_folder_id');
    }

    /** The folder with this public uuid, or null. */
    public static function findByUuid(string $uuid): ?self
    {
        return self::query()->where('uuid', $uuid)->first();
    }

    /**
     * The ids on the path above this folder, outermost first, own id excluded.
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        $ids = self::idsOf($this->path);

        return array_values(array_filter($ids, fn (int $id): bool => $id !== $this->id));
    }

    /**
     * Parent, grandparent … up to the top-level folder, outermost first.
     *
     * @return list<MediaFolder>
     */
    public function ancestors(): array
    {
        $ids = $this->ancestorIds();

        if ($ids === []) {
            return [];
        }

        return array_values(self::query()->whereIn('id', $ids)->orderBy('depth')->get()->all());
    }

    /**
     * Ancestors and this folder, outermost first: what a breadcrumb trail shows.
     *
     * @return list<MediaFolder>
     */
    public function breadcrumbs(): array
    {
        return [...$this->ancestors(), $this];
    }

    /**
     * This folder and everything below it.
     *
     * @return Builder<MediaFolder>
     */
    public function subtreeQuery(): Builder
    {
        $like = LikeEscape::escape($this->path).'%';

        return self::query()->whereRaw("path like ? escape '!'", [$like]);
    }

    /**
     * Everything below this folder, not the folder itself.
     *
     * @return Builder<MediaFolder>
     */
    public function descendantsQuery(): Builder
    {
        return $this->subtreeQuery()->where($this->getTable().'.id', '!=', $this->id);
    }

    /**
     * Every asset in this folder or below it, whoever may see them. For counting
     * what a change would affect; never for showing to a user.
     *
     * @return Collection<int, MediaAsset>
     */
    public function subtreeAssets(): Collection
    {
        return MediaAsset::query()->inFolder($this, true)->get();
    }

    /** True when `$other` is this folder or sits anywhere below it. */
    public function contains(self $other): bool
    {
        return str_starts_with($other->path, $this->path);
    }

    /**
     * "Reports / 2025 / Q1" for each folder, resolved with one query however many folders.
     *
     * @param  iterable<MediaFolder>  $folders
     * @return array<int, string> folder id => path label
     */
    public static function pathLabels(iterable $folders): array
    {
        $needed = [];

        foreach ($folders as $folder) {
            foreach (self::idsOf($folder->path) as $id) {
                $needed[$id] = true;
            }
        }

        if ($needed === []) {
            return [];
        }

        $names = self::query()->whereIn('id', array_keys($needed))->pluck('name', 'id')->all();
        $labels = [];

        foreach ($folders as $folder) {
            $parts = [];

            foreach (self::idsOf($folder->path) as $id) {
                $name = $names[$id] ?? null;
                $parts[] = is_string($name) ? $name : '?';
            }

            $labels[$folder->id] = implode(' / ', $parts);
        }

        return $labels;
    }

    /** @return list<int> */
    private static function idsOf(string $path): array
    {
        return array_values(array_map(intval(...), array_filter(explode('/', $path), static fn (string $part): bool => $part !== '')));
    }
}
