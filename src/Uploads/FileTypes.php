<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Uploads;

use finfo;
use ZipArchive;

/**
 * What a file is, decided from its bytes, and which extensions may name it.
 *
 * libmagic reports an OOXML or ODF file as a generic zip on some versions and
 * as its real type on others, and a CSV as plain text. Both are normalised
 * here so the allow-list sees one answer wherever the app runs.
 */
final class FileTypes
{
    /** Extensions that may legitimately name each MIME type the library knows. */
    private const array EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
        'application/pdf' => ['pdf'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.oasis.opendocument.text' => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        'application/vnd.oasis.opendocument.presentation' => ['odp'],
        'text/csv' => ['csv'],
        'text/plain' => ['txt'],
        'text/vtt' => ['vtt'],
        'application/zip' => ['zip'],
        'video/mp4' => ['mp4', 'm4v'],
        'video/webm' => ['webm'],
        'audio/mpeg' => ['mp3'],
        'audio/mp4' => ['m4a'],
        'audio/wav' => ['wav'],
    ];

    /** Spellings of the same type that libmagic versions disagree on. */
    private const array ALIASES = [
        'image/jpg' => 'image/jpeg',
        'audio/x-m4a' => 'audio/mp4',
        'audio/x-wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/vnd.wave' => 'audio/wav',
        'audio/mp3' => 'audio/mpeg',
        'application/x-zip-compressed' => 'application/zip',
        'application/csv' => 'text/csv',
        'text/x-vtt' => 'text/vtt',
    ];

    /** Zip-based formats are refined from their contents, whatever libmagic called them. */
    private const array ZIP_BASED_PREFIXES = [
        'application/zip',
        'application/octet-stream',
        'application/vnd.openxmlformats-officedocument.',
        'application/vnd.oasis.opendocument.',
    ];

    private const array OOXML_MARKERS = [
        'word/document.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xl/workbook.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt/presentation.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** The detected MIME type of the file at `$path`; `$extension` only breaks the csv/txt tie. */
    public static function detect(string $path, string $extension): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $mime = self::ALIASES[$detected] ?? ($detected === false ? 'application/octet-stream' : $detected);

        foreach (self::ZIP_BASED_PREFIXES as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return self::refineZip($path) ?? $mime;
            }
        }

        // Whether text is "CSV" is a matter of extension: newer libmagic guesses it from the
        // content, older reports plain text, and a .txt that happens to hold commas is still text.
        // WebVTT is plain text that must begin with the WEBVTT signature (after an optional BOM).
        // Without the signature a .vtt file stays text/plain and is refused as an extension mismatch.
        if (in_array($mime, ['text/plain', 'text/csv', 'text/vtt'], true) && strtolower($extension) === 'vtt' && self::startsWithWebVtt($path)) {
            return 'text/vtt';
        }

        if ($mime === 'text/vtt') {
            $mime = 'text/plain';
        }

        if ($mime === 'text/plain' || $mime === 'text/csv') {
            return strtolower($extension) === 'csv' ? 'text/csv' : 'text/plain';
        }

        // An .m4a is an MP4 container holding only audio; libmagic often names
        // the container rather than the content.
        if ($mime === 'video/mp4' && strtolower($extension) === 'm4a') {
            return 'audio/mp4';
        }

        return $mime;
    }

    private static function startsWithWebVtt(string $path): bool
    {
        $head = file_get_contents($path, false, null, 0, 16);

        return is_string($head) && preg_match('/^(?:\xEF\xBB\xBF)?WEBVTT(?:[ \t\r\n]|$)/', $head) === 1;
    }

    /** Whether `$extension` is a fair name for `$mimeType`, in both directions. */
    public static function extensionAgrees(string $mimeType, string $extension): bool
    {
        $extension = strtolower($extension);

        if (isset(self::EXTENSIONS[$mimeType])) {
            return in_array($extension, self::EXTENSIONS[$mimeType], true);
        }

        // An unknown type is only a mismatch when the extension claims to be something we know.
        foreach (self::EXTENSIONS as $extensions) {
            if (in_array($extension, $extensions, true)) {
                return false;
            }
        }

        return true;
    }

    private static function refineZip(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $mimetype = $zip->getFromName('mimetype');

            if (is_string($mimetype) && str_starts_with($mimetype, 'application/vnd.oasis.opendocument.')) {
                return trim($mimetype);
            }

            if ($zip->locateName('[Content_Types].xml') !== false) {
                foreach (self::OOXML_MARKERS as $marker => $mime) {
                    if ($zip->locateName($marker) !== false) {
                        return $mime;
                    }
                }
            }

            return 'application/zip';
        } finally {
            $zip->close();
        }
    }
}
