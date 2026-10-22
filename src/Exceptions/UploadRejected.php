<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** An `AssetUploading` listener or the configured scanner refused the file. */
final class UploadRejected extends MediaException
{
    public static function because(string $reason): self
    {
        return new self('The upload was rejected: '.($reason === '' ? 'it did not pass the scan.' : $reason));
    }
}
