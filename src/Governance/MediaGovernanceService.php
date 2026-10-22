<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Governance;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Events\AssetDeleted;
use Banyan\FilamentMediaLibrary\Events\AssetReplaced;
use Banyan\FilamentMediaLibrary\Events\AssetTagged;
use Banyan\FilamentMediaLibrary\Events\AssetUpdated;
use Banyan\FilamentMediaLibrary\Events\AssetUploaded;
use Banyan\FilamentMediaLibrary\Events\CaptionsChanged;
use Banyan\FilamentMediaLibrary\Events\FocalPointChanged;
use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Banyan\FilamentMediaLibrary\Events\TranscriptChanged;
use Banyan\FilamentMediaLibrary\Exceptions\AltTextRequired;
use Banyan\FilamentMediaLibrary\Exceptions\CompanionNotAllowed;
use Banyan\FilamentMediaLibrary\Exceptions\MediaException;
use Banyan\FilamentMediaLibrary\Exceptions\TitleRequired;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
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
 * Event payloads carry references — asset id, uuid, kind, version id, byte
 * count — and never the alt text, title or filename: a filename is frequently personal data
 * ("cv-jane-doe.png").
 *
 * **Not gated by folder access.** Like {@see MediaFolderService}
 * this service trusts its caller (an import job, a console command, a test). The
 * surfaces that act for a signed-in user, the library and asset pages and the
 * picker, ask {@see FolderAccess::canManage()} inside their
 * handlers before calling it. Code that calls the service on a user's behalf must do the same.
 */
final readonly class MediaGovernanceService
{
    public function __construct(
        private ConnectionInterface $connection,
        private MediaRepository $media,
        private Dispatcher $events,
    ) {}

    /** @throws MediaException */
    public function upload(
        string $binary,
        string $filename,
        string $mimeType,
        string $altText,
        string $actorId,
        ?DateTimeInterface $licenseExpiresAt = null,
        ?string $title = null,
        ?MediaFolder $folder = null,
    ): MediaAsset {
        return $this->connection->transaction(function () use (
            $binary, $filename, $mimeType, $altText, $actorId, $licenseExpiresAt, $title, $folder
        ): MediaAsset {
            $asset = $this->media->store(
                $binary,
                $filename,
                $mimeType,
                $altText,
                is_numeric($actorId) ? (int) $actorId : null,
                $licenseExpiresAt,
                $title,
                $folder?->id,
            );

            $this->events->dispatch(new AssetUploaded($asset, $actorId));

            return $asset;
        });
    }

    /** @throws MediaException */
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

    /** @throws AltTextRequired|TitleRequired */
    public function updateDetails(
        MediaAsset $asset,
        string $altText,
        ?DateTimeInterface $licenseExpiresAt,
        string $actorId,
        ?string $title = null,
    ): void {
        $this->connection->transaction(function () use ($asset, $altText, $licenseExpiresAt, $actorId, $title): void {
            $altChanged = trim($altText) !== (string) $asset->alt_text;
            $titleChanged = $title !== null && trim($title) !== (string) $asset->title;

            $this->media->updateDetails($asset, $altText, $licenseExpiresAt, $title);

            $this->events->dispatch(new AssetUpdated($asset, $altChanged, $licenseExpiresAt, $actorId, $titleChanged));
        });
    }

    /**
     * Attach (or, with null, remove) a video's WebVTT captions.
     *
     * @throws CompanionNotAllowed
     */
    public function setCaptions(MediaAsset $asset, ?MediaAsset $captions, string $actorId): void
    {
        $this->connection->transaction(function () use ($asset, $captions, $actorId): void {
            $this->media->setCaptions($asset, $captions);

            $this->events->dispatch(new CaptionsChanged($asset, $captions?->id, $actorId));
        });
    }

    /**
     * Write or clear the transcript of a video or audio file. The event says
     * whether one exists, never what it says: a transcript is speech, often by named people.
     *
     * @throws CompanionNotAllowed
     */
    public function setTranscript(MediaAsset $asset, ?string $transcript, string $actorId): void
    {
        $this->connection->transaction(function () use ($asset, $transcript, $actorId): void {
            $this->media->setTranscript($asset, $transcript);

            $this->events->dispatch(new TranscriptChanged($asset, filled($asset->transcript), $actorId));
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
        $kind = $asset->kind->value;
        $folderId = $asset->folder_id;

        $this->connection->transaction(function () use ($asset, $assetId, $uuid, $kind, $folderId, $actorId): void {
            $usageCount = $this->media->delete($asset);

            $this->events->dispatch(new AssetDeleted($assetId, $uuid, $usageCount, $actorId, $kind, $folderId));
        });
    }
}
