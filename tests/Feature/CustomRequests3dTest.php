<?php

namespace Tests\Feature;

use App\Models\CustomRequest;
use App\Models\CustomRequestFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class CustomRequests3dTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('custom_orders');
    }

    private function model(string $extension = 'obj'): UploadedFile
    {
        $content = match ($extension) {
            'obj' => "# triangle\nv 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n",
            'stl' => "solid triangle\nfacet normal 0 0 1\nouter loop\nvertex 0 0 0\nvertex 1 0 0\nvertex 0 1 0\nendloop\nendfacet\nendsolid triangle\n",
        };

        return UploadedFile::fake()->createWithContent('piece.'.$extension, $content)->mimeType('text/plain');
    }

    private function package(array $extra = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nakama-3mf-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach (array_merge(['[Content_Types].xml' => '<Types/>', '_rels/.rels' => '<Relationships/>', '3D/3dmodel.model' => '<model/>'], $extra) as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        try {
            return UploadedFile::fake()->createWithContent('piece.3mf', file_get_contents($path))->mimeType('application/zip');
        } finally {
            unlink($path);
        }
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_type' => '3d_print', 'project_name' => 'Soporte Nakama', 'piece_type' => 'stand',
            'quantity' => 3, 'width' => 12.5, 'height' => 8, 'depth' => 4, 'dimension_unit' => 'cm',
            'color' => 'Verde', 'material' => 'PLA', 'usage_type' => 'functional',
            'description' => 'Soporte para una figura.', 'references' => 'Referencia descrita por el cliente.',
            'instructions' => 'Observaciones de la pieza.', 'customer_name' => 'Cliente 3D', 'customer_email' => '3d@example.com',
            'attachments' => [$this->model()],
        ], $overrides);
    }

    public function test_3d_card_and_specific_fields_are_public_but_submission_requires_login(): void
    {
        $this->get('/personalizados')->assertOk()->assertSee('Impresión 3D')->assertSee('Nombre del proyecto o pieza')->assertSee('Profundidad')->assertSee('Material deseado');
        $this->post('/personalizados', $this->payload())->assertRedirect(route('login'));
        $this->assertDatabaseCount('custom_requests', 0);
    }

    public function test_all_three_model_formats_and_references_are_stored_privately_with_metadata(): void
    {
        $binary = UploadedFile::fake()->createWithContent('binary.stl', str_repeat("\0", 80).pack('V', 1).str_repeat("\0", 50))->mimeType('application/octet-stream');
        $attachments = [$this->model('stl'), $this->model(), $this->package(), $binary, UploadedFile::fake()->image('reference.png')];
        $user = User::factory()->create();
        $this->actingAs($user)->post('/personalizados', $this->payload(['attachments' => $attachments]))->assertSessionHasNoErrors()->assertRedirect();
        $request = CustomRequest::firstOrFail();
        $this->assertSame('Soporte Nakama', $request->project_name);
        $this->assertSame('Verde', $request->color);
        $this->assertSame('PLA', $request->material);
        $this->assertSame('cm', $request->dimension_unit);
        $this->assertEquals(12.5, $request->width);
        $this->assertSame('functional', $request->usage_type);
        $this->assertCount(5, $request->files);
        foreach ($request->files as $index => $file) {
            Storage::disk('custom_orders')->assertExists($file->stored_path);
            $this->assertNotSame($file->original_name, $file->stored_path);
            $this->assertSame($index === 4 ? 'reference' : 'model_3d', $file->file_type);
            $this->assertSame(pathinfo($file->stored_path, PATHINFO_EXTENSION), $file->extension);
            if ($file->file_type === 'model_3d') {
                $this->get(route('custom-requests.files.show', $file))->assertDownload()->assertHeader('Content-Type', 'application/octet-stream');
            }
        }
        $this->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('IMPRESIÓN 3D')->assertSee('Modelo 3D adjunto')->assertSee('Referencia descrita por el cliente.')->assertSee('Soporte Nakama');
    }

    public function test_3d_requests_can_use_only_references_and_optional_material(): void
    {
        $this->actingAs(User::factory()->create())->post('/personalizados', $this->payload(['material' => null, 'attachments' => [UploadedFile::fake()->image('idea.jpg')]]))->assertSessionHasNoErrors();
        $request = CustomRequest::firstOrFail();
        $this->assertNull($request->material);
        $this->assertSame('reference', $request->files->first()->file_type);
    }

    public function test_required_3d_fields_units_and_dimensions_are_validated(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/personalizados', $this->payload(['project_name' => '', 'piece_type' => 'invalid', 'width' => -1, 'height' => 0, 'depth' => 'bad', 'dimension_unit' => 'inch', 'usage_type' => 'invalid', 'description' => '', 'attachments' => []]))
            ->assertSessionHasErrors(['project_name', 'piece_type', 'width', 'height', 'depth', 'dimension_unit', 'usage_type', 'description', 'attachments']);
        $this->assertDatabaseCount('custom_requests', 0);
    }

    public function test_model_content_scripts_archives_and_unsupported_formats_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([
            UploadedFile::fake()->createWithContent('fake.obj', '<?php echo 1;')->mimeType('text/plain'),
            UploadedFile::fake()->createWithContent('fake.stl', 'not a model')->mimeType('application/octet-stream'),
            UploadedFile::fake()->createWithContent('fake.3mf', 'PK not a zip')->mimeType('application/zip'),
            UploadedFile::fake()->createWithContent('piece.obj', "v 0 0 0\nf 1 2 3\ncsh run.exe\n")->mimeType('text/plain'),
            UploadedFile::fake()->createWithContent('piece.stl', 'MZ executable')->mimeType('application/x-dosexec'),
            UploadedFile::fake()->createWithContent('piece.php', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('piece.step', 'ISO-10303-21;'),
            $this->package(['../escape.model' => 'unsafe']),
            $this->package(['3D/script.php' => '<?php echo 1;']),
        ] as $file) {
            $this->post('/personalizados', $this->payload(['attachments' => [$file]]))->assertSessionHasErrors('attachments.0');
        }
        $this->assertDatabaseCount('custom_requests', 0);
        $this->assertCount(0, Storage::disk('custom_orders')->allFiles());
    }

    public function test_file_size_and_count_limits_are_configurable(): void
    {
        config(['custom_orders.model_max_kb' => 1, 'custom_orders.reference_max_kb' => 1, 'custom_orders.max_files' => 2]);
        $this->actingAs(User::factory()->create());
        foreach ([$this->model()->size(2), UploadedFile::fake()->image('idea.png')->size(2)] as $file) {
            $this->post('/personalizados', $this->payload(['attachments' => [$file]]))->assertSessionHasErrors('attachments.0');
        }
        $this->post('/personalizados', $this->payload(['attachments' => [$this->model(), $this->model(), $this->model()]]))->assertSessionHasErrors('attachments');
        $this->assertDatabaseCount('custom_requests', 0);
    }

    public function test_other_users_and_guests_cannot_download_models_admin_can_manage_and_quote(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/personalizados', $this->payload())->assertSessionHasNoErrors();
        $request = CustomRequest::firstOrFail();
        $file = $request->files->first();
        $this->actingAs(User::factory()->create())->get(route('custom-requests.files.show', $file))->assertForbidden();
        $this->get(route('account.custom-requests.show', $request))->assertForbidden();
        $this->get(route('housekeeping.custom-requests.show', $request))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get(route('custom-requests.files.show', $file))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/housekeeping/personalizados?product_type=3d_print')->assertOk()->assertSee($request->request_number)->assertSee('Modelo 3D');
        $this->get(route('housekeeping.custom-requests.show', $request))->assertOk()->assertSee('Soporte Nakama')->assertSee('Funcional')->assertSee('PLA')->assertSee('Observaciones de la pieza.');
        $this->get(route('custom-requests.files.show', $file))->assertDownload();
        $this->patch(route('housekeeping.custom-requests.update', $request), ['status' => 'in_production', 'quoted_price' => 25000, 'admin_notes' => 'INTERNAL_3D_NOTE'])->assertSessionHasNoErrors();
        $this->assertSame('Cotizado', $request->fresh()->status_label);
        $this->actingAs($owner)->get(route('account.custom-requests.show', $request))->assertOk()->assertSee('Cotizado')->assertSee('$25.000')->assertDontSee('INTERNAL_3D_NOTE');
        $request->status = 'in_production';
        $this->assertSame('En impresión', $request->status_label);
        $request->status = 'ready';
        $this->assertSame('Listo para entrega', $request->status_label);
    }

    public function test_models_are_not_accepted_for_other_products(): void
    {
        $this->actingAs(User::factory()->create())->post('/personalizados', ['product_type' => 'mug', 'variant' => 'white', 'customer_name' => 'Nakama', 'customer_email' => 'test@example.com', 'quantity' => 1, 'design' => $this->model()])->assertSessionHasErrors('design');
    }

    public function test_partial_failure_rolls_back_request_and_removes_all_uploaded_files(): void
    {
        $created = 0;
        CustomRequestFile::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('Simulated metadata failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs(User::factory()->create())->post('/personalizados', $this->payload(['attachments' => [$this->model(), $this->model('stl')]]));
            $this->fail('Expected a metadata failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated metadata failure', $exception->getMessage());
            $this->assertDatabaseCount('custom_requests', 0);
            $this->assertDatabaseCount('custom_request_files', 0);
            $this->assertCount(0, Storage::disk('custom_orders')->allFiles());
        } finally {
            CustomRequestFile::flushEventListeners();
        }
    }
}
