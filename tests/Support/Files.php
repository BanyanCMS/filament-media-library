<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Tiny, generated, valid-enough non-image files: just what libmagic and the
 * library's own checks need to recognise them. Not playable, not openable.
 */
final class Files
{
    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** A PDF whose page tree names `$count` pages: /Type /Pages must not be counted as one. */
    public static function pdfWithPages(int $count = 2, string $separator = ' '): string
    {
        $kids = '';
        $objects = '';

        for ($i = 0; $i < $count; $i++) {
            $kids .= (3 + $i).' 0 R ';
            $objects .= (3 + $i)." 0 obj\n<< /Type{$separator}/Page /Parent 2 0 R >>\nendobj\n";
        }

        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [{$kids}] /Count {$count} >>\nendobj\n{$objects}trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** WebVTT captions. */
    public static function vtt(): string
    {
        return "WEBVTT\n\n00:00.000 --> 00:02.000\nHello and welcome.\n";
    }

    /** A JPEG signature that libmagic accepts, followed by bytes no decoder can read. */
    public static function brokenJpeg(): string
    {
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00 broken";
    }

    /** A decodable PNG well over a kilobyte: random pixels do not compress. */
    public static function noisyPng(): string
    {
        $image = imagecreatetruecolor(40, 40);

        for ($x = 0; $x < 40; $x++) {
            for ($y = 0; $y < 40; $y++) {
                imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function csv(): string
    {
        return "name,age\nAda,36\nGrace,45\n";
    }

    public static function text(): string
    {
        return "Minutes of the meeting.\n";
    }

    /** @param 'docx'|'xlsx'|'pptx' $type */
    public static function ooxml(string $type = 'docx', bool $macros = false): string
    {
        $main = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$type];
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
            $main => '<?xml version="1.0"?><root/>',
        ];

        if ($macros) {
            $entries['word/vbaProject.bin'] = "\xD0\xCF\x11\xE0 macro";
        }

        return self::zip($entries);
    }

    /** An OpenDocument text file: the `mimetype` entry names the type. */
    public static function odt(): string
    {
        return self::zip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<office:document/>']);
    }

    /** @param array<string, string> $entries */
    public static function zip(array $entries = ['readme.txt' => 'hello']): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'media-fixture-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not build the zip fixture.');
        }

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $binary = (string) file_get_contents($path);
        unlink($path);

        return $binary;
    }

    public static function zipWithEntries(int $count): string
    {
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $entries['f'.$i] = '';
        }

        return self::zip($entries);
    }

    /** An ISO base media "ftyp" box, the signature of an MP4. */
    public static function mp4(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free";
    }

    public static function webm(): string
    {
        return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x02\x42\x85\x81\x02";
    }

    /** An ID3v2 tag followed by an MPEG-1 Layer III frame header. */
    public static function mp3(): string
    {
        return "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x64".str_repeat("\x00", 413), 3);
    }

    public static function wav(): string
    {
        return "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xAC\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00";
    }

    public static function html(): string
    {
        return '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';
    }

    public static function svg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle r="5"/></svg>';
    }

    public static function elf(): string
    {
        return "\x7FELF\x02\x01\x01\x00".str_repeat("\x00", 56);
    }

    public static function exe(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 48);
    }
}
