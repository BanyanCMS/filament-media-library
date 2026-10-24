<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Storage;

use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Exceptions\AltTextRequired;
use Banyan\FilamentMediaLibrary\Exceptions\AssetReferenced;
use Banyan\FilamentMediaLibrary\Exceptions\CompanionNotAllowed;
use Banyan\FilamentMediaLibrary\Exceptions\KindMismatch;
use Banyan\FilamentMediaLibrary\Exceptions\MediaException;
use Banyan\FilamentMediaLibrary\Exceptions\TitleRequired;
use Banyan\FilamentMediaLibrary\Exceptions\UnreadableImage;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaTag;
use Banyan\FilamentMediaLibrary\Models\MediaVersion;
use Banyan\FilamentMediaLibrary\Uploads\Inspection;
use Banyan\FilamentMediaLibrary\Uploads\UploadGuard;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The writer for the media tables (assets, versions, tags and usages) and the disk beneath them.
 *
 * It owns validation that must hold for every caller (the {@see UploadGuard}
 * pipeline, alt text or title) and the asset/version invariant: a replacement appends a version and
 * moves `current_version_id`, in one transaction, and never touches the
 * superseded file.
 *
 * It dispatches no media events (only the guard's pre-write `AssetUploading`)
 * and does not decide who may call it, both belong
 * to {@see MediaGovernanceService}.
 */
final readonly class MediaRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private FilesystemFactory $filesystems,
        private UploadGuard $guard,
        private UsageResolver $usages,
        private string $disk,
        private string $originalsPath,
        private string $renditionsPath,
    ) {}

    /**
     * Create an asset from raw bytes, together with its first version.
     *
     * The type is detected from the bytes ({@see UploadGuard}), never taken
     * from the client, and that is what is stored.
     *
     * @throws AltTextRequired|TitleRequired|UnreadableImage|MediaException
     */
    public function store(
        string $binary,
        string $filename,
        string $altText,
        ?int $uploadedBy = null,
        ?DateTimeInterface $licenseExpiresAt = null,
        ?string $title = null,
        ?int $folderId = null,
    ): MediaAsset {
        $inspection = $this->guard->inspect($binary, $filename);
        $altText = trim($altText);
        $title = trim((string) $title);

        if ($inspection->kind === MediaKind::Image ? $altText === '' : $title === '') {
            throw $inspection->kind === MediaKind::Image ? AltTextRequired::make() : TitleRequired::make();
        }

        $uuid = (string) Str::uuid();

        return $this->connection->transaction(function () use (
            $binary, $filename, $altText, $title, $uploadedBy, $licenseExpiresAt, $inspection, $uuid, $folderId
        ): MediaAsset {
            $path = $this->write($this->disk, $uuid, 1, $filename, $binary);

            $asset = MediaAsset::query()->create([
                'uuid' => $uuid,
                'disk' => $this->disk,
                'path' => $path,
                'filename' => $filename,
                'mime_type' => $inspection->mimeType,
                'kind' => $inspection->kind,
                'size_bytes' => strlen($binary),
                'width' => $inspection->width,
                'height' => $inspection->height,
                'page_count' => $inspection->pageCount,
                'title' => $title === '' ? null : $title,
                'alt_text' => $altText === '' ? null : $altText,
                'focal_x' => 0.5,
                'focal_y' => 0.5,
                'license_expires_at' => $licenseExpiresAt,
                'uploaded_by' => $uploadedBy,
                'folder_id' => $folderId,
            ]);

            $version = $this->appendVersion($asset, $path, $binary, $inspection, $uploadedBy);

            $asset->forceFill(['current_version_id' => $version->id])->save();

            return $asset->refresh();
        });
    }

    /**
     * Replace an asset's bytes.
     *
     * The superseded file is left on the disk deliberately: the version row that
     * names it is the only record of what the asset used to look like, and a
     * history that 404s is not a history. Reclaiming that space is a separate,
     * explicit operation, not a side effect of an editor fixing a crop.
     *
     * @throws KindMismatch|UnreadableImage|MediaException
     */
    public function replace(
        MediaAsset $asset,
        string $binary,
        string $filename,
        ?int $uploadedBy = null,
    ): MediaVersion {
        $inspection = $this->guard->inspect($binary, $filename, $asset->kind);

        return $this->connection->transaction(function () use (
            $asset, $binary, $filename, $uploadedBy, $inspection
        ): MediaVersion {
            $ordinal = $asset->versions()->count() + 1;
            // The asset's own disk, not the configured one: an asset's versions
            // stay together even after the default disk changes.
            $path = $this->write($asset->disk, $asset->uuid, $ordinal, $filename, $binary);

            $version = $this->appendVersion($asset, $path, $binary, $inspection, $uploadedBy);

            $asset->forceFill([
                'current_version_id' => $version->id,
                'path' => $path,
                'filename' => $filename,
                'mime_type' => $inspection->mimeType,
                'size_bytes' => strlen($binary),
                'width' => $inspection->width,
                'height' => $inspection->height,
                'page_count' => $inspection->pageCount,
            ])->save();

            // Every cached derivative described the old bytes. The cache path
            // carries the version id, so they are already unreachable; clearing
            // them is housekeeping, not correctness.
            $this->forgetRenditions($asset);

            return $version;
        });
    }

    /**
     * Edit the text and licence of an asset.
     *
     * An image needs alt text; every other kind needs a title. A null `$title`
     * leaves the stored title alone, so existing image callers are unaffected.
     *
     * @throws AltTextRequired|TitleRequired
     */
    public function updateDetails(
        MediaAsset $asset,
        string $altText,
        ?DateTimeInterface $licenseExpiresAt,
        ?string $title = null,
    ): void {
        $altText = trim($altText);
        $title = $title === null ? $asset->title : trim($title);

        if ($asset->isImage() && $altText === '') {
            throw AltTextRequired::make();
        }

        if (! $asset->isImage() && trim((string) $title) === '') {
            throw TitleRequired::make();
        }

        $asset->forceFill([
            'alt_text' => $altText === '' ? null : $altText,
            'title' => $title === '' ? null : $title,
            'license_expires_at' => $licenseExpiresAt,
        ])->save();
    }

    /**
     * Attach a WebVTT file as a video's captions, or detach with null.
     *
     * @throws CompanionNotAllowed
     */
    public function setCaptions(MediaAsset $asset, ?MediaAsset $captions): void
    {
        if ($asset->kind !== MediaKind::Video) {
            throw CompanionNotAllowed::captionsFor($asset->kind);
        }

        if ($captions !== null && ! $captions->isWebVtt()) {
            throw CompanionNotAllowed::notWebVtt();
        }

        $asset->forceFill(['captions_asset_id' => $captions?->id])->save();
        $asset->unsetRelation('captions');
    }

    /**
     * Write the transcript of a video or audio file; blank clears it.
     *
     * @throws CompanionNotAllowed
     */
    public function setTranscript(MediaAsset $asset, ?string $transcript): void
    {
        if (! in_array($asset->kind, [MediaKind::Video, MediaKind::Audio], true)) {
            throw CompanionNotAllowed::transcriptFor($asset->kind);
        }

        $transcript = trim((string) $transcript);

        $asset->forceFill(['transcript' => $transcript === '' ? null : $transcript])->save();
    }

    /**
     * Store a focal point as fractions of the image, clamped into 0–1.
     *
     * Clamping here and not only in the crosshair means a hand-rolled API call
     * cannot store (1.4, -0.2) and produce a rendition nobody can explain.
     */
    public function setFocalPoint(MediaAsset $asset, float $x, float $y): void
    {
        $asset->forceFill([
            'focal_x' => round(max(0.0, min(1.0, $x)), 5),
            'focal_y' => round(max(0.0, min(1.0, $y)), 5),
        ])->save();

        $this->forgetRenditions($asset);
    }

    /**
     * Replace an asset's tags with the given names.
     *
     * @param  list<string>  $names
     */
    public function syncTags(MediaAsset $asset, array $names): void
    {
        $this->connection->transaction(function () use ($asset, $names): void {
            $ids = [];

            foreach ($names as $name) {
                $normalised = MediaTag::normalise($name);

                if ($normalised === '') {
                    continue;
                }

                $tag = MediaTag::query()->firstOrCreate(['name' => $normalised]);
                $ids[] = $tag->id;
            }

            $asset->tags()->sync(array_values(array_unique($ids)));
        });
    }

    /**
     * Delete an unreferenced asset, its versions and every file it owns.
     *
     * The live-reference check is inside the transaction and follows a locked
     * asset read. Confirmation in a UI is useful, but cannot be the safety
     * boundary because imports, APIs and queue workers also call repositories.
     *
     * A used asset is refused, so a delete that succeeds always finds 0 usages.
     * The return value is that count, kept so existing callers still compile.
     *
     * @return int always 0
     *
     * @throws AssetReferenced
     */
    public function delete(MediaAsset $asset): int
    {
        /** @var array{files: list<array{0: string, 1: string}>, usage_count: int} $deleted */
        $deleted = $this->connection->transaction(function () use ($asset): array {
            $lockedAsset = MediaAsset::query()
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->first();

            if ($lockedAsset === null) {
                return ['files' => [], 'usage_count' => 0];
            }

            $usageCount = $this->usages->count($lockedAsset->id);

            if ($usageCount > 0) {
                throw new AssetReferenced($lockedAsset->id, $usageCount);
            }

            // Each file on the disk its row names: a version written before
            // the default disk changed still lives where it was written.
            $files = [[$lockedAsset->disk, $lockedAsset->path]];

            foreach ($lockedAsset->versions()->get(['disk', 'path']) as $version) {
                $files[] = [$version->disk, $version->path];
            }

            $lockedAsset->delete();

            return [
                'files' => array_values(array_filter(
                    $files,
                    static fn (array $file): bool => $file[0] !== '' && $file[1] !== '',
                )),
                'usage_count' => $usageCount,
            ];
        });

        foreach ($deleted['files'] as [$diskName, $path]) {
            $this->filesystems->disk($diskName)->delete($path);
        }

        $this->forgetRenditions($asset);

        return $deleted['usage_count'];
    }

    /** The bytes of an asset's current file. Null when the file has gone missing beneath us. */
    public function contents(MediaAsset $asset): ?string
    {
        $disk = $this->filesystems->disk($asset->disk);

        if (! $disk->exists($asset->path)) {
            return null;
        }

        $contents = $disk->get($asset->path);

        return is_string($contents) ? $contents : null;
    }

    public function disk(): Filesystem
    {
        return $this->filesystems->disk($this->disk);
    }

    public function diskName(): string
    {
        return $this->disk;
    }

    public function renditionsPath(): string
    {
        return $this->renditionsPath;
    }

    /** The disk one asset's files live on, which is not always the configured default. */
    public function diskFor(MediaAsset $asset): Filesystem
    {
        return $this->filesystems->disk($asset->disk);
    }

    /** Drop every cached derivative of one asset. */
    public function forgetRenditions(MediaAsset $asset): void
    {
        $this->diskFor($asset)->deleteDirectory($this->renditionsPath.'/'.$asset->uuid);
    }

    private function appendVersion(
        MediaAsset $asset,
        string $path,
        string $binary,
        Inspection $inspection,
        ?int $uploadedBy,
    ): MediaVersion {
        return MediaVersion::query()->create([
            'media_asset_id' => $asset->id,
            'disk' => $asset->disk,
            'path' => $path,
            'checksum' => hash('sha256', $binary),
            'size_bytes' => strlen($binary),
            'kind' => $inspection->kind,
            'width' => $inspection->width,
            'height' => $inspection->height,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Write bytes and return the stored path.
     *
     * The path carries the version ordinal, so a replacement can never land on
     * the file it replaces even if the two uploads share a filename.
     *
     * Called inside the write's transaction. If that transaction rolls back
     * (a listener refusing the change, say), the file is removed with it, so a
     * refused upload leaves no orphan on the disk.
     */
    private function write(string $diskName, string $uuid, int $ordinal, string $filename, string $binary): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,5}$/', $extension) === 1 ? $extension : 'bin';

        $path = sprintf('%s/%s/%s-v%d.%s', $this->originalsPath, substr($uuid, 0, 2), $uuid, $ordinal, $extension);

        $disk = $this->filesystems->disk($diskName);
        $disk->put($path, $binary);

        if ($this->connection instanceof Connection) {
            $this->connection->afterRollBack(static fn () => $disk->delete($path));
        }

        return $path;
    }
}
