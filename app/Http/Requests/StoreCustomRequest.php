<?php

namespace App\Http\Requests;

use App\Models\CustomRequest;
use App\Rules\CustomOrderFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCustomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()) {
            $this->merge(['customer_name' => $this->user()->name, 'customer_email' => $this->user()->email]);
        }
        if ($this->input('product_type') === 'patch') {
            $this->merge(['dimension_unit' => 'cm']);
        }
        // Preserve compatibility with requests made using the previous form.
        if (in_array($this->input('product_type'), ['shirt_white', 'shirt_black'], true)) {
            $position = $this->input('print_position');
            if ($position === 'Frontal') {
                $this->merge(['print_position' => 'Delantera']);
            }
            if ($position === 'Espalda') {
                $this->merge(['print_position' => 'Trasera']);
            }
        }
    }

    public function rules(): array
    {
        $shirt = in_array($this->input('product_type'), ['shirt_white', 'shirt_black'], true);
        $threeD = $this->input('product_type') === '3d_print';
        $v2 = (string) $this->input('preview_data.version') === '2';
        $mugV2 = $v2 && $this->input('product_type') === 'mug';
        $shirtV2 = $v2 && $shirt;
        $keys = 'version,side,x,y,scale,rotation,color,file_index';
        if ($mugV2) {
            $keys .= ',product,scale_x,scale_y,fit_mode,handle_side';
        } elseif ($shirtV2) {
            $keys .= ',product,orientation,preset,size_preset';
        }

        return [
            'design_assistance' => ['sometimes', 'boolean', Rule::prohibitedIf(! CustomRequest::supportsDesignAssistance($this->input('product_type')) && $this->boolean('design_assistance'))],
            'design_assistance_notes' => $this->boolean('design_assistance')
                ? ['required', 'string', 'min:10', 'max:2000', 'not_regex:/<[^>]*>|<\s*\/?\s*[a-z!]/i'] : ['exclude'],
            'preview_data' => ['nullable', $threeD ? 'prohibited' : 'sometimes', 'array:'.$keys],
            'preview_data.version' => ['required_with:preview_data', 'integer', Rule::in($shirt || $this->input('product_type') === 'mug' ? [1, 2] : [1])],
            'preview_data.product' => $mugV2 || $shirtV2 ? ['required', Rule::in([$mugV2 ? 'mug' : 'shirt'])] : ['prohibited'],
            'preview_data.scale_x' => $mugV2 ? ['required', 'numeric', 'between:0.25,3'] : ['prohibited'],
            'preview_data.scale_y' => $mugV2 ? ['required', 'numeric', 'between:0.25,3'] : ['prohibited'],
            'preview_data.fit_mode' => $mugV2 ? ['required', Rule::in(['fit', 'fill', 'stretch'])] : ['prohibited'],
            'preview_data.handle_side' => $mugV2 ? ['required', Rule::in(['left', 'right'])] : ['prohibited'],
            'preview_data.orientation' => $shirtV2 ? ['required', Rule::in(['vertical', 'horizontal'])] : ['prohibited'],
            'preview_data.preset' => $shirtV2 ? ['required', Rule::in($this->input('preview_data.side') === 'back'
                ? ['custom', 'upper_back', 'back_center', 'large_back']
                : ['custom', 'left_chest', 'upper_center', 'center', 'large_front'])] : ['prohibited'],
            'preview_data.size_preset' => $shirtV2 ? ['required', Rule::in(['custom', 'small', 'medium', 'large'])] : ['prohibited'],
            'preview_data.side' => ['required_with:preview_data', Rule::in($shirt ? ['front', 'back'] : ['front'])],
            'preview_data.x' => ['required_with:preview_data', 'numeric', 'between:0,1'],
            'preview_data.y' => ['required_with:preview_data', 'numeric', 'between:0,1'],
            'preview_data.scale' => ['required_with:preview_data', 'numeric', 'between:0.1,1.5'],
            'preview_data.rotation' => ['required_with:preview_data', 'numeric', 'in:0'],
            'preview_data.color' => ['required_with:preview_data', Rule::in(match ($this->input('product_type')) {
                'shirt_black' => ['black'], 'shirt_white' => ['white'], default => ['neutral'],
            })],
            'preview_data.file_index' => ['required_with:preview_data', 'integer', 'min:0', 'max:'.(config('custom_orders.max_files') - 1)],
            'product_type' => ['required', Rule::in(array_keys(CustomRequest::PRODUCTS))],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'variant' => $this->input('product_type') === 'mug' ? ['required', Rule::in(array_keys(config('custom_orders.mug_variants')))] : ['exclude'],
            'size' => $shirt ? ['required', Rule::in(['S', 'M', 'L', 'XL', 'XXL'])] : ['exclude'],
            'print_position' => $shirt ? ['required', Rule::in(CustomRequest::PRINT_POSITIONS)] : ['exclude'],
            'dimensions' => ['exclude_unless:product_type,stickers', 'required', 'string', 'max:255'],
            'project_name' => ['exclude_unless:product_type,3d_print', 'required', 'string', 'max:255'],
            'piece_type' => ['exclude_unless:product_type,3d_print', 'required', Rule::in(array_keys(CustomRequest::PIECE_TYPES))],
            'color' => ['exclude_unless:product_type,3d_print', 'required', 'string', 'max:255'],
            'material' => ['exclude_unless:product_type,3d_print', 'nullable', 'string', 'max:255'],
            'width' => ['exclude_unless:product_type,3d_print,patch', 'required', 'numeric', 'min:0.001', 'max:999999999.999', 'decimal:0,3'],
            'height' => ['exclude_unless:product_type,3d_print,patch', 'required', 'numeric', 'min:0.001', 'max:999999999.999', 'decimal:0,3'],
            'depth' => ['exclude_unless:product_type,3d_print', 'required', 'numeric', 'min:0.001', 'max:999999999.999', 'decimal:0,3'],
            'dimension_unit' => ['exclude_unless:product_type,3d_print,patch', 'required', Rule::in($threeD ? ['mm', 'cm'] : ['cm'])],
            'shape' => ['exclude_unless:product_type,patch', 'required', Rule::in(array_keys(CustomRequest::PATCH_SHAPES))],
            'border_color' => ['exclude_unless:product_type,patch', 'nullable', 'string', 'max:100'],
            'usage_type' => ['exclude_unless:product_type,3d_print', 'required', Rule::in(array_keys(CustomRequest::USAGE_TYPES))],
            'description' => ['exclude_unless:product_type,3d_print', 'required', 'string', 'max:10000'],
            'references' => ['exclude_unless:product_type,3d_print', 'nullable', 'string', 'max:10000'],
            'design' => $threeD || $this->has('attachments') ? ['prohibited'] : ['required', 'file', new CustomOrderFile],
            'attachments' => [$threeD ? 'required' : 'required_without:design', 'array', 'min:1', 'max:'.config('custom_orders.max_files')],
            'attachments.*' => ['required', 'file', new CustomOrderFile($threeD)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! is_array($this->input('preview_data'))) {
                return;
            }
            $files = $this->file('attachments') ?? [$this->file('design')];
            $file = $files[(int) $this->input('preview_data.file_index')] ?? null;
            if (! $file || ! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) || @getimagesize($file->getRealPath()) === false) {
                $validator->errors()->add('preview_data.file_index', 'Selecciona una imagen JPG, PNG o WebP válida para la vista previa.');
            }
            if ((int) $this->input('preview_data.version') === 2) {
                if ($this->input('product_type') === 'mug' && $this->input('preview_data.fit_mode') !== 'stretch'
                    && ((float) $this->input('preview_data.scale_x') !== 1.0 || (float) $this->input('preview_data.scale_y') !== 1.0)) {
                    $validator->errors()->add('preview_data.scale_x', 'Usa Expandir horizontalmente para modificar las proporciones del diseño.');
                }
                if (in_array($this->input('product_type'), ['shirt_white', 'shirt_black'], true)) {
                    $presets = [
                        'left_chest' => [0.75, 0.23, 0.35], 'upper_center' => [0.5, 0.25, 0.65],
                        'center' => [0.5, 0.5, 0.65], 'large_front' => [0.5, 0.5, 1],
                        'upper_back' => [0.5, 0.25, 0.65], 'back_center' => [0.5, 0.5, 0.65], 'large_back' => [0.5, 0.5, 1],
                    ];
                    $preset = $presets[$this->input('preview_data.preset')] ?? null;
                    if ($preset) {
                        foreach (['x', 'y', 'scale'] as $index => $field) {
                            if (abs((float) $this->input('preview_data.'.$field) - $preset[$index]) > 0.000001) {
                                $validator->errors()->add('preview_data.preset', 'La posición fue ajustada manualmente; selecciona un preset o utiliza el modo manual.');
                                break;
                            }
                        }
                    }
                    $size = ['small' => 0.35, 'medium' => 0.65, 'large' => 1][$this->input('preview_data.size_preset')] ?? null;
                    if ($size !== null && abs((float) $this->input('preview_data.scale') - $size) > 0.000001) {
                        $validator->errors()->add('preview_data.size_preset', 'El tamaño seleccionado no coincide con la escala del diseño.');
                    }
                }
            }
            if (($this->input('product_type') === 'badges' || ($this->input('product_type') === 'patch' && $this->input('shape') === 'circular'))
                && hypot((float) $this->input('preview_data.x') - 0.5, (float) $this->input('preview_data.y') - 0.5) > 0.480001) {
                $validator->errors()->add('preview_data.x', 'Mantén el centro del diseño dentro del área circular imprimible.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['width' => 'ancho aproximado', 'height' => 'alto aproximado', 'shape' => 'forma del parche', 'border_color' => 'color de borde', 'dimension_unit' => 'unidad de medida'];
    }
}
