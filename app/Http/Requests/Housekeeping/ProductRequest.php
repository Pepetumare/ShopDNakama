<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');
        $name = $this->input('name');
        if ($slug === null || is_string($slug)) {
            $this->merge(['slug' => filled($slug) ? Str::slug($slug) : ($this->route('product')?->slug ?? Str::slug(is_string($name) ? $name : ''))]);
        }
    }

    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products')->ignore($product)],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products')->ignore($product)],
            'short_description' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:50000'],
            'product_type' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'sale_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'lt:price'],
            'stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'is_new' => ['required', 'boolean'],
            'is_customizable' => ['exclude'],
            'uploads' => ['sometimes', 'array', 'max:10'],
            'uploads.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'primary_image_id' => ['nullable', 'integer', Rule::exists('product_images', 'id')->where('product_id', $product?->id ?? 0)],
            'images_meta' => ['sometimes', 'array', 'max:500'],
            'images_meta.*' => ['array:id,sort_order,alt_text'],
            'images_meta.*.id' => ['required', 'integer', 'distinct', Rule::exists('product_images', 'id')->where('product_id', $product?->id ?? 0)],
            'images_meta.*.sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'images_meta.*.alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'slug.unique' => 'Este slug ya está en uso.',
            'slug.regex' => 'Usa letras minúsculas, números y guiones en el slug.',
            'sku.unique' => 'Este SKU ya está en uso.',
            'category_id.exists' => 'Selecciona una categoría existente.',
            'price.required' => 'El precio es obligatorio.',
            'price.numeric' => 'Ingresa un precio válido.',
            'price.min' => 'El precio no puede ser negativo.',
            'sale_price.lt' => 'El precio de oferta debe ser menor que el precio normal.',
            'sale_price.min' => 'El precio de oferta no puede ser negativo.',
            'stock.min' => 'El stock no puede ser negativo.',
            'stock.integer' => 'El stock debe ser un número entero.',
            'uploads.max' => 'Puedes subir hasta 10 imágenes a la vez.',
            'uploads.*.image' => 'Todos los archivos deben ser imágenes válidas.',
            'uploads.*.mimes' => 'Solo se permiten imágenes JPG, PNG o WebP.',
            'uploads.*.max' => 'Cada imagen puede pesar hasta 5 MB.',
            'primary_image_id.exists' => 'La imagen principal debe pertenecer a este producto.',
            'images_meta.*.id.exists' => 'La imagen debe pertenecer a este producto.',
        ];
    }
}
