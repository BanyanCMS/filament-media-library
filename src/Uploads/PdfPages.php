<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Uploads;

/**
 * A best-effort page count for a PDF, without a PDF library.
 *
 * Counts page objects: `/Type /Page` that is not followed by another letter,
 * so the page tree's `/Type /Pages` and `/Type /PageLabels` are not counted.
 * It is informational only, and honest about its limits: a PDF 1.5+ file that
 * keeps its page objects inside compressed object streams shows none, and the
 * answer is then null rather than a wrong number. Duration of audio and video
 * is likewise left null (reading it needs ffmpeg or getID3, which this package
 * does not depend on); a host can fill `duration_seconds` itself.
 */
final class PdfPages
{
    public static function count(string $pdf): ?int
    {
        $count = preg_match_all('/\/Type\s*\/Page(?![A-Za-z])/', $pdf);

        return is_int($count) && $count > 0 ? $count : null;
    }
}
