<?php

namespace Tests\Feature;

use App\Models\CustomRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\StoreTestCase;

class CustomPreviewTest extends StoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('custom_orders');
        $this->actingAs(User::factory()->create());
    }

    private function preview(array $extra = []): array
    {
        return array_merge(['version' => 1, 'side' => 'front', 'x' => 0.5, 'y' => 0.28, 'scale' => 0.35, 'rotation' => 0, 'color' => 'neutral', 'file_index' => 0], $extra);
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['product_type' => 'badges', 'quantity' => 2, 'attachments' => [UploadedFile::fake()->image('original.png', 200, 100)], 'preview_data' => $this->preview()], $extra);
    }

    public function test_preview_is_saved_with_original_bytes_and_private_authorized_rendering(): void
    {
        $payload = $this->payload();
        $bytes = file_get_contents($payload['attachments'][0]->getRealPath());
        $this->post('/personalizados', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $request = CustomRequest::firstOrFail();
        $this->assertSame($this->preview(), $request->preview_data);
        $this->assertNull($request->size);
        $file = $request->files->first();
        $this->assertSame($bytes, Storage::disk('custom_orders')->get($file->stored_path));
        Storage::disk('public')->assertMissing($file->stored_path);
        $request->update(['admin_notes' => 'PRIVATE-PREVIEW-NOTE']);
        $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Tu vista previa')->assertSee('28%')->assertSee('35%')->assertSee(route('custom-requests.files.show', $file))->assertDontSee('PRIVATE-PREVIEW-NOTE');
        $this->get(route('custom-requests.files.show', $file))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->create());
        $this->get(route('account.custom-requests.show', $request))->assertForbidden();
        $this->get(route('custom-requests.files.show', $file))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('housekeeping.custom-requests.show', $request))->assertOk()->assertSee('Vista previa del cliente')->assertSee('28%')->assertSee('PRIVATE-PREVIEW-NOTE');
        $this->get(route('custom-requests.files.show', $file))->assertOk();
    }

    public function test_invalid_ranges_structure_and_image_selection_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach ([['x' => -0.01], ['x' => 1.01], ['y' => -1], ['y' => 2], ['x' => 0, 'y' => 0], ['scale' => 0.09], ['scale' => 1.51], ['rotation' => 90], ['side' => 'back'], ['color' => 'black'], ['version' => 2], ['file_index' => 7], ['html' => '<svg onload="alert(1)">'], ['x' => '<b>0.5</b>']] as $invalid) {
            $this->post('/personalizados', $this->payload(['preview_data' => $this->preview($invalid)]))->assertSessionHasErrors();
        }
        $this->post('/personalizados', $this->payload(['preview_data' => ['x' => 0.5]]))->assertSessionHasErrors('preview_data.side');
        $this->post('/personalizados', $this->payload(['preview_data' => 'not-an-object']))->assertSessionHasErrors('preview_data');
        $this->post('/personalizados', $this->payload(['attachments' => [UploadedFile::fake()->createWithContent('reference.pdf', "%PDF-1.4\n%%EOF")]]))->assertSessionHasErrors('preview_data.file_index');
        $this->assertDatabaseCount('custom_requests', 0);
        $this->assertCount(0, Storage::disk('custom_orders')->allFiles());
    }

    public function test_both_shirt_sides_and_colors_are_preserved(): void
    {
        foreach (['shirt_black' => 'black', 'shirt_white' => 'white'] as $type => $color) {
            foreach (['front', 'back'] as $side) {
                $this->post('/personalizados', $this->payload(['product_type' => $type, 'size' => 'M', 'print_position' => 'Ambos lados', 'preview_data' => $this->preview(['side' => $side, 'color' => $color])]))->assertSessionHasNoErrors();
                $request = CustomRequest::latest('id')->firstOrFail();
                $this->assertSame($side, $request->preview_data['side']);
                $this->assertSame($color, $request->preview_data['color']);
                $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee($side === 'back' ? 'Espalda' : 'Frontal')->assertSee($color === 'black' ? 'Negro' : 'Blanco');
            }
        }
    }

    public function test_other_products_and_multiple_attachments_keep_the_selected_design(): void
    {
        foreach ([['product_type' => 'mug', 'variant' => 'white'], ['product_type' => 'stickers', 'dimensions' => '5 × 8 cm'], ['product_type' => 'patch', 'width' => 8, 'height' => 5, 'shape' => 'rectangular']] as $options) {
            $payload = $this->payload($options + ['attachments' => [UploadedFile::fake()->createWithContent('reference.pdf', "%PDF-1.4\n%%EOF"), UploadedFile::fake()->image('selected.png')], 'preview_data' => $this->preview(['file_index' => 1])]);
            $this->post('/personalizados', $payload)->assertSessionHasNoErrors();
            $request = CustomRequest::latest('id')->firstOrFail();
            $this->assertSame(1, $request->preview_data['file_index']);
            $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Tu vista previa')->assertSee(route('custom-requests.files.show', $request->files->last()));
        }
    }

    public function test_3d_does_not_accept_compositor_data_and_legacy_requests_remain_supported(): void
    {
        $threeD = ['product_type' => '3d_print', 'project_name' => 'Figura', 'piece_type' => 'figure', 'color' => 'Rojo', 'material' => 'PLA', 'width' => 5, 'height' => 8, 'depth' => 4, 'dimension_unit' => 'cm', 'usage_type' => 'decorative', 'description' => 'Figura con referencia'];
        $this->post('/personalizados', $this->payload($threeD))->assertSessionHasErrors('preview_data');
        $this->assertDatabaseCount('custom_requests', 0);
        $this->post('/personalizados', $this->payload($threeD + ['preview_data' => null]))->assertSessionHasNoErrors();
        $threeDRequest = CustomRequest::firstOrFail();
        $this->assertNull($threeDRequest->preview_data);
        $this->assertSame('PLA', $threeDRequest->material);
        $this->get(route('account.custom-requests.show', $threeDRequest))->assertOk()->assertDontSee('Tu vista previa');
        $this->post('/personalizados', $this->payload(['preview_data' => null]))->assertSessionHasNoErrors();
        $request = CustomRequest::latest('id')->firstOrFail();
        $this->assertNull($request->preview_data);
        $this->get(route('account.custom-requests.show', $request))->assertOk()->assertDontSee('Tu vista previa');
    }

    private function mugPreview(array $extra = []): array
    {
        return $this->preview(array_merge(['version' => 2, 'product' => 'mug', 'x' => 0.5, 'y' => 0.5, 'scale' => 1, 'scale_x' => 1, 'scale_y' => 1, 'fit_mode' => 'fit', 'handle_side' => 'right'], $extra));
    }

    private function shirtPreview(array $extra = []): array
    {
        return $this->preview(array_merge(['version' => 2, 'product' => 'shirt', 'color' => 'black', 'orientation' => 'vertical', 'preset' => 'custom', 'size_preset' => 'custom'], $extra));
    }

    public function test_panoramic_mug_saves_independent_scales_fit_and_handle_and_original_ratio(): void
    {
        foreach (['left', 'right'] as $handle) {
            $preview = $this->mugPreview(['scale_x' => 1.35, 'scale_y' => 0.9, 'fit_mode' => 'stretch', 'handle_side' => $handle]);
            $payload = $this->payload(['product_type' => 'mug', 'variant' => 'magic', 'preview_data' => $preview]);
            $bytes = file_get_contents($payload['attachments'][0]->getRealPath());
            $this->post('/personalizados', $payload)->assertSessionHasNoErrors();
            $request = CustomRequest::latest('id')->firstOrFail();
            $this->assertEquals($preview + ['aspect_ratio' => 2], $request->preview_data);
            $this->assertSame($bytes, Storage::disk('custom_orders')->get($request->files->first()->stored_path));
            $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Tu vista previa')->assertSee('Vista previa panorámica')->assertSee('Vista previa sobre taza')->assertSee('Expandir horizontalmente')->assertSee('135%')->assertSee('90%')->assertSee($handle === 'left' ? 'Asa a la izquierda' : 'Asa a la derecha');
            $this->assertEquals($preview + ['aspect_ratio' => 2], $request->fresh()->preview_data);
        }
    }

    public function test_mug_keeps_each_fit_mode_without_implicit_stretching(): void
    {
        foreach (['fit', 'fill', 'stretch'] as $mode) {
            $this->post('/personalizados', $this->payload(['product_type' => 'mug', 'variant' => 'white', 'preview_data' => $this->mugPreview(['fit_mode' => $mode])]))->assertSessionHasNoErrors();
            $preview = CustomRequest::latest('id')->firstOrFail()->preview_data;
            $this->assertSame($mode, $preview['fit_mode']);
            $this->assertEquals(1, $preview['scale_x']);
            $this->assertEquals(1, $preview['scale_y']);
        }
    }

    public function test_v2_rejects_invalid_ranges_cross_product_fields_and_inconsistent_presets(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach ([['scale_x' => 0.24], ['scale_x' => 3.01], ['scale_y' => 0], ['scale_y' => 4], ['fit_mode' => 'html'], ['handle_side' => 'top'], ['product' => 'shirt'], ['scale_x' => 1.2], ['orientation' => 'horizontal'], ['aspect_ratio' => 999], ['scale_y' => 'NaN']] as $invalid) {
            $this->post('/personalizados', $this->payload(['product_type' => 'mug', 'variant' => 'white', 'preview_data' => $this->mugPreview($invalid)]))->assertSessionHasErrors();
        }
        foreach ([['orientation' => 'diagonal'], ['scale_x' => 1], ['preset' => 'large_back'], ['preset' => 'center'], ['size_preset' => 'large'], ['product' => 'mug']] as $invalid) {
            $this->post('/personalizados', $this->payload(['product_type' => 'shirt_black', 'size' => 'M', 'print_position' => 'Delantera', 'preview_data' => $this->shirtPreview($invalid)]))->assertSessionHasErrors();
        }
        $this->post('/personalizados', $this->payload(['preview_data' => $this->mugPreview()]))->assertSessionHasErrors('preview_data.version');
        $this->assertDatabaseCount('custom_requests', 0);
        $this->assertCount(0, Storage::disk('custom_orders')->allFiles());
    }

    public function test_shirt_orientation_is_independent_of_front_and_back(): void
    {
        foreach (['shirt_black' => 'black', 'shirt_white' => 'white'] as $type => $color) {
            foreach (['vertical', 'horizontal'] as $orientation) {
                foreach (['front', 'back'] as $side) {
                    $preview = $this->shirtPreview(['color' => $color, 'orientation' => $orientation, 'side' => $side]);
                    $this->post('/personalizados', $this->payload(['product_type' => $type, 'size' => 'M', 'print_position' => $side === 'front' ? 'Delantera' : 'Trasera', 'preview_data' => $preview]))->assertSessionHasNoErrors();
                    $request = CustomRequest::latest('id')->firstOrFail();
                    $this->assertSame($preview, $request->preview_data);
                    $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee($orientation === 'vertical' ? 'Vertical' : 'Horizontal')->assertSee($side === 'front' ? 'Frontal' : 'Espalda')->assertSee('Manual');
                }
            }
        }
    }

    public function test_all_position_and_size_presets_save_valid_normalized_values(): void
    {
        $presets = ['left_chest' => [0.75, 0.23, 0.35, 'small'], 'upper_center' => [0.5, 0.25, 0.65, 'medium'], 'center' => [0.5, 0.5, 0.65, 'medium'], 'large_front' => [0.5, 0.5, 1, 'large'], 'upper_back' => [0.5, 0.25, 0.65, 'medium'], 'back_center' => [0.5, 0.5, 0.65, 'medium'], 'large_back' => [0.5, 0.5, 1, 'large']];
        foreach ($presets as $name => [$x, $y, $scale, $size]) {
            $side = in_array($name, ['upper_back', 'back_center', 'large_back']) ? 'back' : 'front';
            $preview = $this->shirtPreview(['side' => $side, 'x' => $x, 'y' => $y, 'scale' => $scale, 'preset' => $name, 'size_preset' => $size]);
            $this->post('/personalizados', $this->payload(['product_type' => 'shirt_black', 'size' => 'L', 'print_position' => 'Ambos lados', 'preview_data' => $preview]))->assertSessionHasNoErrors();
            $this->assertEquals($preview, CustomRequest::latest('id')->firstOrFail()->preview_data);
        }
        foreach (['small' => 0.35, 'medium' => 0.65, 'large' => 1] as $size => $scale) {
            $this->post('/personalizados', $this->payload(['product_type' => 'shirt_black', 'size' => 'L', 'print_position' => 'Delantera', 'preview_data' => $this->shirtPreview(['size_preset' => $size, 'scale' => $scale])]))->assertSessionHasNoErrors();
            $this->assertSame($size, CustomRequest::latest('id')->firstOrFail()->preview_data['size_preset']);
        }
    }

    public function test_historical_v1_previews_keep_their_data_and_original_renderer(): void
    {
        foreach (['mug', 'shirt_white'] as $type) {
            $preview = $this->preview(['color' => $type === 'shirt_white' ? 'white' : 'neutral']);
            $this->post('/personalizados', $this->payload(['product_type' => $type, 'variant' => 'white', 'size' => 'M', 'print_position' => 'Delantera', 'preview_data' => $preview]))->assertSessionHasNoErrors();
            $request = CustomRequest::latest('id')->firstOrFail();
            $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Tu vista previa')->assertSee('28%')->assertDontSee('Vista previa panorámica')->assertDontSee('Orientación del estampado');
            $this->assertSame($preview, $request->fresh()->preview_data);
        }
    }

    public function test_housekeeping_explains_v2_mugs_and_shirts_without_exposing_notes_to_customer(): void
    {
        $this->post('/personalizados', $this->payload(['product_type' => 'mug', 'variant' => 'magic', 'preview_data' => $this->mugPreview(['fit_mode' => 'fill', 'handle_side' => 'left'])]))->assertSessionHasNoErrors();
        $mug = CustomRequest::latest('id')->firstOrFail();
        $this->post('/personalizados', $this->payload(['product_type' => 'shirt_black', 'size' => 'M', 'print_position' => 'Trasera', 'preview_data' => $this->shirtPreview(['orientation' => 'horizontal', 'side' => 'back', 'x' => 0.5, 'y' => 0.5, 'scale' => 1, 'preset' => 'large_back', 'size_preset' => 'large'])]))->assertSessionHasNoErrors();
        $shirt = CustomRequest::latest('id')->firstOrFail();
        $shirt->update(['admin_notes' => 'SECRET-V2-NOTE']);
        $this->get(route('account.custom-requests.show', $shirt))->assertOk()->assertDontSee('SECRET-V2-NOTE');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('housekeeping.custom-requests.show', $mug))->assertOk()->assertSee('Vista previa del cliente')->assertSee('Taza mágica')->assertSee('Rellenar')->assertSee('Asa a la izquierda')->assertSee('Escala horizontal')->assertSee('Escala vertical')->assertSee('Vista previa panorámica')->assertSee('Vista previa sobre taza');
        $this->get(route('housekeeping.custom-requests.show', $shirt))->assertOk()->assertSee('Negra')->assertSee('Espalda')->assertSee('Horizontal')->assertSee('Grande espalda')->assertSee('Grande')->assertSee('SECRET-V2-NOTE');
    }

    public function test_mug_preserves_phone_image_exif_orientation_without_changing_original_bytes(): void
    {
        foreach (['little', 'big'] as $order) {
            $image = UploadedFile::fake()->image('camera.jpg', 200, 100);
            $bytes = file_get_contents($image->getRealPath());
            $tiff = $order === 'little'
                ? 'II'.pack('vVvvvVv', 42, 8, 1, 0x0112, 3, 1, 6)."\0\0".pack('V', 0)
                : 'MM'.pack('nNnnnNn', 42, 8, 1, 0x0112, 3, 1, 6)."\0\0".pack('N', 0);
            $exif = "Exif\0\0".$tiff;
            $original = substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
            $this->post('/personalizados', $this->payload(['product_type' => 'mug', 'variant' => 'white', 'attachments' => [UploadedFile::fake()->createWithContent('camera.jpg', $original)], 'preview_data' => $this->mugPreview()]))->assertSessionHasNoErrors();
            $request = CustomRequest::latest('id')->firstOrFail();
            $this->assertSame(0.5, $request->preview_data['aspect_ratio']);
            $this->assertSame($original, Storage::disk('custom_orders')->get($request->files->first()->stored_path));
        }
    }
}
