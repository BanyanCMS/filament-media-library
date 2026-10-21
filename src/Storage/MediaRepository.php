<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Storage;

use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Exceptions\AltTextRequired;
use Banyan\FilamentMediaLibrary\Exceptions\AssetReferenced;
use Banyan\FilamentMediaLibrary\Exceptions\UnreadableImage;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaTag;
use Banyan\FilamentMediaLibrary\Models\MediaVersion;
use Banyan\FilamentMediaLibrary\Rendering\ImageProcessor;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The writer for the four media tables and the disk beneath them.
 *
 * It owns validation that must hold for every caller (alt text, decodable
 * bytes) and the asset/version invariant: a replacement appends a version and
 * moves `current_version_id`, in one transaction, and never touches the
 * superseded file.
 *
 * It dispatches no events and does not decide who may call it — both belong
 * to {@see MediaGovernanceService}.
 */
final readonly class MediaRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private FilesystemFactory $filesystems,
        private ImageProcessor $processor,
        private UsageResolver $usages,
        private string $disk,
        private string $originalsPath,
        private string $renditionsPath,
    ) {}

    /**
     * Create an asset from raw bytes, together with its first version.
     *
     * @throws AltTextRequired|UnreadableImage
     */
    public function store(
        string $binary,
        string $filename,
        string $mimeType,
        string $altText,
        ?int $uploadedBy = null,
        ?DateTimeInterface $licenseExpiresAt = null,
    ): MediaAsset {
        $altText = trim($altText);

        if ($altText === '') {
            throw AltTextRequired::make();
        }

        $dimensions = $this->processor->dimensions($binary);

        if ($dimensions === null) {
            throw UnreadableImage::make($filename);
        }

        $uuid = (string) Str::uuid();

        return $this->connection->transaction(function () use (
            $binary, $filename, $mimeType, $altText, $uploadedBy, $licenseExpiresAt, $dimensions, $uuid
        ): MediaAsset {
            $path = $this->write($this->disk, $uuid, 1, $filename, $binary);

            $asset = MediaAsset::query()->create([
                'uuid' => $uuid,
                'disk' => $this->disk,
                'path' => $path,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($binary),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'alt_text' => $altText,
                'focal_x' => 0.5,
                'focal_y' => 0.5,
                'license_expires_at' => $licenseExpiresAt,
                'uploaded_by' => $uploadedBy,
            ]);

            $version = $this->appendVersion($asset, $path, $binary, $dimensions, $uploadedBy);

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
     * @throws UnreadableImage
     */
    public function replace(
        MediaAsset $asset,
        string $binary,
        string $filename,
        string $mimeType,
        ?int $uploadedBy = null,
    ): MediaVersion {
        $dimensions = $this->processor->dimensions($binary);

        if ($dimensions === null) {
            throw UnreadableImage::make($filename);
        }

        return $this->connection->transaction(function () use (
            $asset, $binary, $filename, $mimeType, $uploadedBy, $dimensions
        ): MediaVersion {
            $ordinal = $asset->versions()->count() + 1;
            // The asset's own disk, not the configured one: an asset's versions
            // stay together even after the default disk changes.
            $path = $this->write($asset->disk, $asset->uuid, $ordinal, $filename, $binary);

            $version = $this->appendVersion($asset, $path, $binary, $dimensions, $uploadedBy);

            $asset->forceFill([
                'current_version_id' => $version->id,
                'path' => $path,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($binary),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
            ])->save();

            // Every cached derivative described the old bytes. The cache path
            // carries the version id, so they are already unreachable; clearing
            // them is housekeeping, not correctness.
            $this->forgetRenditions($asset);

            return $version;
        });
    }

    /** @throws AltTextRequired */
    public function updateDetails(
        MediaAsset $asset,
        string $altText,
        ?DateTimeInterface $licenseExpiresAt,
    ): void {
        $altText = trim($altText);

        if ($altText === '') {
            throw AltTextRequired::make();
        }

        $asset->forceFill([
            'alt_text' => $altText,
            'license_expires_at' => $licenseExpiresAt,
        ])->save();
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

    /**
     * @param  array{0: int, 1: int}  $dimensions
     */
    private function appendVersion(
        MediaAsset $asset,
        string $path,
        string $binary,
        array $dimensions,
        ?int $uploadedBy,
    ): MediaVersion {
        return MediaVersion::query()->create([
            'media_asset_id' => $asset->id,
            'disk' => $asset->disk,
            'path' => $path,
            'checksum' => hash('sha256', $binary),
            'size_bytes' => strlen($binary),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Write bytes and return the stored path.
     *
     * The path carries the version ordinal, so a replacement can never land on
     * the file it replaces even if the two uploads share a filename.
     *
     * Called inside the write's transaction. If that transaction rolls back —
     * a listener refusing the change, say — the file is removed with it, so a
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
