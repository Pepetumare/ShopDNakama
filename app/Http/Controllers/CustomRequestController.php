<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomRequest;
use App\Models\CustomRequest;
use App\Models\CustomRequestFile;
use App\Support\PreviewImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomRequestController extends Controller
{
    public function create()
    {
        return view('custom-requests.create');
    }

    public function continue()
    {
        return to_route('custom-requests.create');
    }

    public function store(StoreCustomRequest $request)
    {
        $data = $request->safe()->except(['design', 'attachments']);
        $data['design_assistance'] = $request->boolean('design_assistance');
        $data['design_assistance_fee'] = $data['design_assistance'] ? (int) config('custom_orders.design_assistance_price') : 0;
        $data['design_assistance_notes'] = $data['design_assistance'] ? $data['design_assistance_notes'] : null;
        if (! empty($data['preview_data'])) {
            $preview = $data['preview_data'];
            $data['preview_data'] = [
                'version' => (int) $preview['version'], 'side' => $preview['side'],
                'x' => (float) $preview['x'], 'y' => (float) $preview['y'],
                'scale' => (float) $preview['scale'], 'rotation' => 0,
                'color' => match ($data['product_type']) {
                    'shirt_black' => 'black', 'shirt_white' => 'white', default => 'neutral',
                },
                'file_index' => (int) $preview['file_index'],
            ];
            if ((int) $preview['version'] === 2) {
                if ($data['product_type'] === 'mug') {
                    $files = $request->file('attachments') ?? [$request->file('design')];
                    $data['preview_data'] += [
                        'product' => 'mug', 'scale_x' => (float) $preview['scale_x'], 'scale_y' => (float) $preview['scale_y'],
                        'fit_mode' => $preview['fit_mode'], 'handle_side' => $preview['handle_side'],
                        'aspect_ratio' => PreviewImage::aspectRatio($files[(int) $preview['file_index']]->getRealPath()),
                    ];
                } else {
                    $data['preview_data'] += [
                        'product' => 'shirt', 'orientation' => $preview['orientation'],
                        'preset' => $preview['preset'], 'size_preset' => $preview['size_preset'],
                    ];
                }
            }
        }
        $data['color'] = match ($data['product_type']) {
            'shirt_white' => 'Blanca', 'shirt_black' => 'Negra', '3d_print' => $data['color'], default => null
        };
        $files = $request->file('attachments') ?? [$request->file('design')];
        $paths = [];
        try {
            $customRequest = DB::transaction(function () use ($request, $data, $files, &$paths) {
                $customRequest = $request->user()->customRequests()->create($data);
                foreach ($files as $file) {
                    $extension = strtolower($file->getClientOriginalExtension());
                    $isModel = in_array($extension, ['stl', 'obj', '3mf'], true);
                    if (! $isModel) {
                        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$file->getMimeType()];
                    }
                    $path = $file->storeAs('', Str::uuid().'.'.$extension, 'custom_orders');
                    $paths[] = $path;
                    $customRequest->files()->create(['original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255), 'stored_path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'extension' => $extension, 'file_type' => $isModel ? 'model_3d' : 'reference']);
                }

                return $customRequest;
            });
        } catch (\Throwable $exception) {
            Storage::disk('custom_orders')->delete($paths);
            throw $exception;
        }

        return to_route('account.custom-requests.show', $customRequest)->with('status', 'Solicitud enviada. Revisaremos tu diseño para cotizarlo.');
    }

    public function index(Request $request)
    {
        return view('account.custom-requests.index', ['customRequests' => $request->user()->customRequests()->latest('id')->paginate(15)]);
    }

    public function show(CustomRequest $customRequest)
    {
        Gate::authorize('view', $customRequest);

        return view('account.custom-requests.show', ['customRequest' => $customRequest->load(['files', 'order'])]);
    }

    public function file(Request $request, CustomRequestFile $file)
    {
        Gate::authorize('viewFile', $file->customRequest);
        abort_unless(preg_match('/\A[a-f0-9-]+\.(jpg|png|webp|pdf|stl|obj|3mf)\z/', $file->stored_path), 404);
        $disk = Storage::disk('custom_orders');
        abort_unless($disk->exists($file->stored_path), 404);
        $headers = ['Content-Type' => $file->file_type === 'model_3d' ? 'application/octet-stream' : $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"];
        $name = 'diseno-'.$file->id.'.'.pathinfo($file->stored_path, PATHINFO_EXTENSION);

        return $request->boolean('download') || ! $file->is_image
            ? $disk->download($file->stored_path, $name, $headers)
            : $disk->response($file->stored_path, $name, $headers);
    }
}
