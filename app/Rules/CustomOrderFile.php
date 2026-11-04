<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class CustomOrderFile implements ValidationRule
{
    public function __construct(private bool $allowModels = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('El archivo adjunto no es válido.');

            return;
        }
        $extension = strtolower($value->getClientOriginalExtension());
        if ($value->getSize() <= 0) {
            $fail('El archivo adjunto está vacío.');

            return;
        }
        $references = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
        if (isset($references[$extension])) {
            if ($value->getSize() > config('custom_orders.reference_max_kb') * 1024 || $value->getMimeType() !== $references[$extension]) {
                $fail('La referencia debe ser JPG, PNG, WebP o PDF y respetar el tamaño máximo permitido.');
            }

            return;
        }
        if (! $this->allowModels || ! in_array($extension, ['stl', 'obj', '3mf'], true)) {
            $fail('Formato no permitido. Adjunta una referencia o un modelo STL, OBJ o 3MF.');

            return;
        }
        if ($value->getSize() > config('custom_orders.model_max_kb') * 1024) {
            $fail('El modelo 3D supera el tamaño máximo permitido.');

            return;
        }
        // MIME is detected server-side, but generic text/binary MIME is common for CAD files.
        $mimes = match ($extension) {
            'stl' => ['model/stl', 'application/sla', 'application/vnd.ms-pki.stl', 'application/octet-stream', 'text/plain'],
            'obj' => ['model/obj', 'application/octet-stream', 'text/plain'],
            '3mf' => ['model/3mf', 'application/vnd.ms-package.3dmanufacturing-3dmodel+xml', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        };
        if (! in_array($value->getMimeType(), $mimes, true) || ! $this->hasModelStructure($value, $extension)) {
            $fail('El contenido del archivo no corresponde a un modelo '.$extension.' válido.');
        }
    }

    private function hasModelStructure(UploadedFile $file, string $extension): bool
    {
        // Inspect data only: no rendering, execution, extraction or external resource loading.
        if ($extension === '3mf') {
            return $this->is3mf($file->getPathname());
        }
        $stream = fopen($file->getPathname(), 'rb');
        try {
            if ($extension === 'stl') {
                $header = fread($stream, 84);
                if (strlen($header) === 84) {
                    $triangles = unpack('Vcount', substr($header, 80, 4))['count'];
                    if ($triangles > 0 && 84 + $triangles * 50 === $file->getSize()) {
                        return true;
                    }
                }
                rewind($stream);
                $text = stream_get_contents($stream);

                return ! str_contains($text, "\0") && ! preg_match('/<\?|<script|#!/i', $text)
                    && preg_match('/\Asolid\b/i', ltrim($text)) && preg_match('/facet\s+normal\s+[-+\d.eE]+\s+[-+\d.eE]+\s+[-+\d.eE]+/i', $text)
                    && preg_match('/vertex\s+[-+\d.eE]+\s+[-+\d.eE]+\s+[-+\d.eE]+/i', $text) && preg_match('/endsolid[^\r\n]*\s*\z/i', $text);
            }
            $vertices = false;
            $faces = false;
            while (($line = fgets($stream, 65538)) !== false) {
                if (strlen($line) > 65536 || str_contains($line, "\0")) {
                    return false;
                }
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                // OBJ commands such as call/csh are deliberately excluded.
                if (! preg_match('/\A(v|vt|vn|vp|f|l|p|o|g|s|mtllib|usemtl)\s+[^<>\x00-\x08]+\z/', $line)) {
                    return false;
                }
                if (preg_match('/\Av\s+[-+\d.eE]+\s+[-+\d.eE]+\s+[-+\d.eE]+/', $line)) {
                    $vertices = true;
                }
                if (preg_match('/\Af\s+[-\d\/]+\s+[-\d\/]+\s+[-\d\/]+/', $line)) {
                    $faces = true;
                }
            }

            return $vertices && $faces;
        } finally {
            fclose($stream);
        }
    }

    private function is3mf(string $path): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            if ($zip->numFiles > 1000) {
                return false;
            }
            $model = false;
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'];
                $total += $entry['size'];
                if ($total > config('custom_orders.model_max_kb') * 1024 * 10 || ($entry['encryption_method'] ?? 0) !== 0) {
                    return false;
                }
                if (str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)|:~', $name)) {
                    return false;
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['model', 'xml', 'rels', 'png', 'jpg', 'jpeg', 'webp'], true)) {
                    return false;
                }
                if (preg_match('~\A3D/.+\.model\z~i', $name) && $entry['size'] > 0) {
                    $model = true;
                }
            }

            return $model && $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('_rels/.rels') !== false;
        } finally {
            $zip->close();
        }
    }
}
