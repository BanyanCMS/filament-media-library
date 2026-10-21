<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Governance;

use Banyan\FilamentMediaLibrary\Events\AssetDeleted;
use Banyan\FilamentMediaLibrary\Events\AssetReplaced;
use Banyan\FilamentMediaLibrary\Events\AssetTagged;
use Banyan\FilamentMediaLibrary\Events\AssetUpdated;
use Banyan\FilamentMediaLibrary\Events\AssetUploaded;
use Banyan\FilamentMediaLibrary\Events\FocalPointChanged;
use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Banyan\FilamentMediaLibrary\Exceptions\AltTextRequired;
use Banyan\FilamentMediaLibrary\Exceptions\UnreadableImage;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaVersion;
use Banyan\FilamentMediaLibrary\Storage\MediaRepository;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * The single entry point every media-administration surface writes through.
 *
 * {@see MediaRepository} owns the tables and the disk; this class is the
 * composition — write, then dispatch a {@see MediaEvent},
 * in the *same* transaction, so an audit listener never records a write that
 * was rolled back and a listener that throws undoes the write. An asset
 * replaced or deleted without a trail is exactly the change nobody can
 * reconstruct afterwards.
 *
 * Event payloads carry references — asset id, uuid, version id, byte count —
 * and never the alt text or filename: a filename is frequently personal data
 * ("cv-jane-doe.png").
 */
final readonly class MediaGovernanceService
{
    public function __construct(
        private ConnectionInterface $connection,
        private MediaRepository $media,
        private Dispatcher $events,
    ) {}

    /** @throws AltTextRequired|UnreadableImage */
    public function upload(
        string $binary,
        string $filename,
        string $mimeType,
        string $altText,
        string $actorId,
        ?DateTimeInterface $licenseExpiresAt = null,
    ): MediaAsset {
        return $this->connection->transaction(function () use (
            $binary, $filename, $mimeType, $altText, $actorId, $licenseExpiresAt
        ): MediaAsset {
            $asset = $this->media->store(
                $binary,
                $filename,
                $mimeType,
                $altText,
                is_numeric($actorId) ? (int) $actorId : null,
                $licenseExpiresAt,
            );

            $this->events->dispatch(new AssetUploaded($asset, $actorId));

            return $asset;
        });
    }

    /** @throws UnreadableImage */
    public function replace(
        MediaAsset $asset,
        string $binary,
        string $filename,
        string $mimeType,
        string $actorId,
    ): MediaVersion {
        return $this->connection->transaction(function () use ($asset, $binary, $filename, $mimeType, $actorId): MediaVersion {
            $supersededId = $asset->current_version_id;

            $version = $this->media->replace(
                $asset,
                $binary,
                $filename,
                $mimeType,
                is_numeric($actorId) ? (int) $actorId : null,
            );

            $this->events->dispatch(new AssetReplaced($asset, $version, $supersededId, $actorId));

            return $version;
        });
    }

    /** @throws AltTextRequired */
    public function updateDetails(
        MediaAsset $asset,
        string $altText,
        ?DateTimeInterface $licenseExpiresAt,
        string $actorId,
    ): void {
        $this->connection->transaction(function () use ($asset, $altText, $licenseExpiresAt, $actorId): void {
            $altChanged = trim($altText) !== $asset->alt_text;

            $this->media->updateDetails($asset, $altText, $licenseExpiresAt);

            $this->events->dispatch(new AssetUpdated($asset, $altChanged, $licenseExpiresAt, $actorId));
        });
    }

    public function setFocalPoint(MediaAsset $asset, float $x, float $y, string $actorId): void
    {
        $this->connection->transaction(function () use ($asset, $x, $y, $actorId): void {
            $this->media->setFocalPoint($asset, $x, $y);

            $this->events->dispatch(new FocalPointChanged($asset, $actorId));
        });
    }

    /** @param list<string> $names */
    public function syncTags(MediaAsset $asset, array $names, string $actorId): void
    {
        $this->connection->transaction(function () use ($asset, $names, $actorId): void {
            $this->media->syncTags($asset, $names);

            $this->events->dispatch(new AssetTagged($asset, count($names), $actorId));
        });
    }

    /**
     * Delete an asset. The usage count at the moment of deletion is recorded,
     * because "deleted while 4 pages still used it" is the fact an incident
     * review needs and the fact no later query can reconstruct.
     */
    public function delete(MediaAsset $asset, string $actorId): void
    {
        $assetId = $asset->id;
        $uuid = $asset->uuid;

        $this->connection->transaction(function () use ($asset, $assetId, $uuid, $actorId): void {
            $usageCount = $this->media->delete($asset);

            $this->events->dispatch(new AssetDeleted($assetId, $uuid, $usageCount, $actorId));
        });
    }
}
