<?php

namespace Tests\Feature;

use App\Models\CustomRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomRequestsCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('custom_orders');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['product_type' => 'mug', 'variant' => 'magic', 'quantity' => 2, 'customer_phone' => '+56912345678', 'instructions' => 'Diseño por ambos lados', 'attachments' => [UploadedFile::fake()->image('design.png'), UploadedFile::fake()->createWithContent('reference.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")]], $overrides);
    }

    public function test_all_normal_products_save_their_options_and_multiple_private_references(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach ([
            ['product_type' => 'mug', 'variant' => 'white'],
            ['product_type' => 'mug', 'variant' => 'magic'],
            ['product_type' => 'shirt_white', 'size' => 'S', 'print_position' => 'Delantera'],
            ['product_type' => 'shirt_black', 'size' => 'XXL', 'print_position' => 'Trasera'],
            ['product_type' => 'shirt_black', 'size' => 'M', 'print_position' => 'Ambos lados'],
            ['product_type' => 'badges'],
            ['product_type' => 'stickers', 'dimensions' => '5 × 7 cm'],
            ['product_type' => 'patch', 'width' => 8, 'height' => 5, 'dimension_unit' => 'cm', 'shape' => 'rectangular'],
        ] as $options) {
            $this->post('/personalizados', $this->payload($options))->assertSessionHasNoErrors()->assertRedirect();
            $request = CustomRequest::latest('id')->firstOrFail();
            foreach ($options as $field => $value) {
                $this->assertEquals($value, $request->$field);
            }
            $this->assertSame($user->name, $request->customer_name);
            $this->assertSame($user->email, $request->customer_email);
            $this->assertSame('+56912345678', $request->customer_phone);
            $this->assertSame(2, $request->quantity);
            $this->assertSame('Diseño por ambos lados', $request->instructions);
            $this->assertCount(2, $request->files);
            foreach ($request->files as $file) {
                $this->assertSame('reference', $file->file_type);
                Storage::disk('custom_orders')->assertExists($file->stored_path);
                $this->get(route('custom-requests.files.show', [$file, 'download' => 1]))->assertDownload();
            }
        }
    }

    public function test_contact_snapshot_comes_from_account_even_when_payload_is_forged(): void
    {
        $user = User::factory()->create(['name' => 'Cuenta Real', 'email' => 'real@example.com']);
        $other = User::factory()->create();
        $this->actingAs($user)->post('/personalizados', $this->payload(['customer_name' => 'Falso', 'customer_email' => 'fake@example.com', 'user_id' => $other->id, 'status' => 'completed', 'admin_notes' => 'Injected', 'quoted_price' => 1]))->assertSessionHasNoErrors();
        $request = CustomRequest::firstOrFail();
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame('Cuenta Real', $request->customer_name);
        $this->assertSame('real@example.com', $request->customer_email);
        $this->assertSame('pending', $request->status);
        $this->assertNull($request->admin_notes);
        $this->assertNull($request->quoted_price);
        $user->update(['name' => 'Nombre nuevo']);
        $this->assertSame('Cuenta Real', $request->fresh()->customer_name);
    }

    public function test_request_number_uses_independent_database_sequence_and_is_unique(): void
    {
        // Reserving a number does not consume a custom_requests primary key.
        DB::table('custom_request_sequences')->insert(['id' => 99, 'created_at' => now()]);
        $this->actingAs(User::factory()->create());
        $this->post('/personalizados', $this->payload())->assertSessionHasNoErrors();
        $one = CustomRequest::firstOrFail();
        $this->assertSame('PER-000100', $one->request_number);
        $this->assertSame(1, $one->id);
        $this->post('/personalizados', $this->payload())->assertSessionHasNoErrors();
        $two = CustomRequest::latest('id')->firstOrFail();
        $this->assertSame('PER-000101', $two->request_number);
        $this->assertNotSame($one->request_number, $two->request_number);
    }

    public function test_default_limit_is_twenty_mb_for_every_file_type(): void
    {
        $this->assertSame(20480, config('custom_orders.reference_max_kb'));
        $this->assertSame(20480, config('custom_orders.model_max_kb'));
        $this->actingAs(User::factory()->create());
        $this->post('/personalizados', $this->payload(['attachments' => [UploadedFile::fake()->image('boundary.png')->size(20480)]]))->assertSessionHasNoErrors();
        $this->post('/personalizados', $this->payload(['attachments' => [UploadedFile::fake()->image('oversize.png')->size(20481)]]))->assertSessionHasErrors('attachments.0');
        $this->assertDatabaseCount('custom_requests', 1);
    }

    public function test_empty_dangerous_model_and_excessive_reference_uploads_are_rejected_for_normal_products(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['exe', 'php', 'js', 'html', 'svg', 'obj'] as $extension) {
            $file = UploadedFile::fake()->createWithContent('bad.'.$extension, '<?php echo 1;');
            $this->post('/personalizados', $this->payload(['attachments' => [$file]]))->assertSessionHasErrors('attachments.0');
        }
        $this->post('/personalizados', $this->payload(['attachments' => [UploadedFile::fake()->create('empty.pdf', 0)]]))->assertSessionHasErrors('attachments.0');
        $this->post('/personalizados', $this->payload(['attachments' => []]))->assertSessionHasErrors();
        config(['custom_orders.max_files' => 1]);
        $this->post('/personalizados', $this->payload())->assertSessionHasErrors('attachments');
        $this->assertDatabaseCount('custom_requests', 0);
        $this->assertCount(0, Storage::disk('custom_orders')->allFiles());
    }

    public function test_admin_list_includes_price_and_handles_one_sided_date_filters(): void
    {
        $this->actingAs(User::factory()->create())->post('/personalizados', $this->payload())->assertSessionHasNoErrors();
        $request = CustomRequest::firstOrFail();
        $request->update(['quoted_price' => 12345, 'admin_notes' => 'Internal list secret']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([['date_to' => now()->toDateString()], ['date_from' => now()->toDateString()]] as $filter) {
            $this->get('/housekeeping/personalizados?'.http_build_query($filter))->assertOk()->assertSee($request->request_number)->assertSee('Precio cotizado')->assertSee('$12.345')->assertDontSee('Internal list secret');
        }
        $this->getJson('/housekeeping/personalizados?date_from=2026-10-27&date_to=2026-10-26')->assertUnprocessable();
    }

    public function test_internal_storage_paths_are_hidden_from_views_and_model_serialization(): void
    {
        $this->actingAs(User::factory()->create())->post('/personalizados', $this->payload())->assertSessionHasNoErrors();
        $request = CustomRequest::firstOrFail();
        $file = $request->files->first();
        $this->assertArrayNotHasKey('stored_path', $file->toArray());
        $this->get(route('account.custom-requests.show', $request))->assertOk()->assertDontSee($file->stored_path)->assertDontSee('storage/app/private');
    }
}
