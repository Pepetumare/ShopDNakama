<?php

namespace App\Support;

class PreviewImage
{
    public static function aspectRatio(string $path): float
    {
        $size = getimagesize($path);
        $ratio = $size[0] / $size[1];

        // Browsers honor camera EXIF rotation. Keep the stored ratio consistent,
        // including on installations without PHP's optional EXIF extension.
        if ($size[2] !== IMAGETYPE_JPEG) {
            return $ratio;
        }
        $stream = fopen($path, 'rb');
        try {
            if (fread($stream, 2) !== "\xFF\xD8") {
                return $ratio;
            }
            while (! feof($stream)) {
                $marker = fread($stream, 2);
                if (strlen($marker) !== 2 || $marker[0] !== "\xFF" || in_array(ord($marker[1]), [0xDA, 0xD9], true)) {
                    break;
                }
                $lengthBytes = fread($stream, 2);
                if (strlen($lengthBytes) !== 2) {
                    break;
                }
                $length = unpack('n', $lengthBytes)[1] - 2;
                if ($length < 1) {
                    break;
                }
                $segment = fread($stream, $length);
                if (ord($marker[1]) !== 0xE1 || ! str_starts_with($segment, "Exif\0\0")) {
                    continue;
                }
                $tiff = substr($segment, 6);
                $little = substr($tiff, 0, 2) === 'II';
                $short = fn ($offset) => $offset >= 0 && $offset + 2 <= strlen($tiff) ? unpack($little ? 'v' : 'n', substr($tiff, $offset, 2))[1] : 0;
                $long = fn ($offset) => $offset >= 0 && $offset + 4 <= strlen($tiff) ? unpack($little ? 'V' : 'N', substr($tiff, $offset, 4))[1] : 0;
                if (! in_array(substr($tiff, 0, 2), ['II', 'MM'], true) || $short(2) !== 42) {
                    continue;
                }
                $offset = $long(4);
                $entries = min($short($offset), 512);
                for ($entry = 0; $entry < $entries; $entry++) {
                    $tag = $offset + 2 + $entry * 12;
                    if ($short($tag) === 0x0112 && $short($tag + 2) === 3 && $long($tag + 4) === 1) {
                        return in_array($short($tag + 8), [5, 6, 7, 8], true) ? 1 / $ratio : $ratio;
                    }
                }
            }
        } finally {
            fclose($stream);
        }

        return $ratio;
    }
}
