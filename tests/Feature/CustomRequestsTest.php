<?php

namespace Tests\Feature;

use App\Models\CustomRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('custom_orders');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['product_type' => 'mug', 'variant' => 'white', 'quantity' => 2, 'customer_name' => 'Nakama', 'customer_email' => 'nakama@example.com', 'design' => UploadedFile::fake()->image('original.png')], $overrides);
    }

    private function makeRequest(?User $user = null): CustomRequest
    {
        $this->actingAs($user ?? User::factory()->create())->post('/personalizados', $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        return CustomRequest::latest('id')->firstOrFail();
    }

    public function test_public_page_navigation_and_guest_login_return(): void
    {
        $this->get('/personalizados')->assertOk()->assertSee('Personalizados')->assertSee('Polera negra')->assertSee('Iniciar sesión para enviar');
        $this->get('/personalizados/continuar')->assertRedirect('/login');
        $this->assertSame(url('/personalizados/continuar'), session('url.intended'));
    }

    public function test_guest_cannot_create(): void
    {
        $this->post('/personalizados', $this->payload())->assertRedirect('/login');
        $this->assertDatabaseCount('custom_requests', 0);
        $this->assertCount(0, Storage::disk('custom_orders')->allFiles());
    }

    public function test_user_can_create_with_private_randomly_named_image_and_cannot_set_admin_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/personalizados', $this->payload(['status' => 'approved', 'admin_notes' => 'injected', 'quoted_price' => 1, 'user_id' => 999]))->assertSessionHasNoErrors()->assertRedirect();
        $request = CustomRequest::firstOrFail();
        $file = $request->files()->firstOrFail();
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame('pending', $request->status);
        $this->assertNull($request->admin_notes);
        $this->assertNull($request->quoted_price);
        $this->assertSame('original.png', $file->original_name);
        $this->assertSame('image/png', $file->mime_type);
        $this->assertNotSame('original.png', $file->stored_path);
        Storage::disk('custom_orders')->assertExists($file->stored_path);
        Storage::disk('public')->assertMissing('custom-orders/'.$file->stored_path);
        $this->get(route('custom-requests.files.show', $file))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('custom-requests.files.show', [$file, 'download' => 1]))->assertDownload();
    }

    public function test_disallowed_spoofed_and_oversize_files_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([
            UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('fake.png', '<?php echo 1;')->mimeType('text/x-php'),
            UploadedFile::fake()->createWithContent('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->image('shell.php'),
            UploadedFile::fake()->image('large.png')->size(20481),
        ] as $file) {
            $this->post('/personalizados', $this->payload(['design' => $file]))->assertSessionHasErrors('design');
        }
        $this->assertDatabaseCount('custom_requests', 0);
        $this->assertCount(0, Storage::disk('custom_orders')->allFiles());
    }

    public function test_pdf_is_accepted_and_downloaded_as_attachment(): void
    {
        $this->actingAs(User::factory()->create())->post('/personalizados', $this->payload(['design' => UploadedFile::fake()->createWithContent('design.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")]))->assertSessionHasNoErrors();
        $file = CustomRequest::firstOrFail()->files()->firstOrFail();
        $this->assertFalse($file->is_image);
        $this->get(route('custom-requests.files.show', $file))->assertDownload();
    }

    public function test_real_mime_detection_rejects_php_disguised_as_png(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nakama-upload-');
        try {
            file_put_contents($path, '<?php echo "unsafe";');
            $file = new UploadedFile($path, 'design.png', 'image/png', null, true);
            $this->actingAs(User::factory()->create())->post('/personalizados', $this->payload(['design' => $file]))->assertSessionHasErrors('design');
            $this->assertDatabaseCount('custom_requests', 0);
        } finally {
            unlink($path);
        }
    }

    public function test_user_can_only_see_their_own_requests_and_files_and_never_internal_notes(): void
    {
        $user = User::factory()->create();
        $own = $this->makeRequest($user);
        $own->update(['admin_notes' => 'SECRETO INTERNO', 'quoted_price' => 15000]);
        $other = $this->makeRequest();
        $this->actingAs($user)->get('/mi-cuenta/personalizados')->assertOk()->assertSee($own->request_number)->assertDontSee($other->request_number);
        $this->get(route('account.custom-requests.show', $own))->assertOk()->assertSee('$15.000')->assertDontSee('SECRETO INTERNO');
        $this->get(route('account.custom-requests.show', $other))->assertForbidden();
        $this->get(route('custom-requests.files.show', $other->files->first()))->assertForbidden();
        $this->get(route('custom-requests.files.show', [$other->files->first(), 'download' => 1]))->assertForbidden();
    }

    public function test_admin_can_see_all_requests_files_and_quote_then_reject(): void
    {
        $one = $this->makeRequest();
        $two = $this->makeRequest();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/housekeeping/personalizados')->assertOk()->assertSee($one->request_number)->assertSee($two->request_number);
        $this->get(route('housekeeping.custom-requests.show', $one))->assertOk()->assertSee($one->customer_email);
        $this->get(route('custom-requests.files.show', $one->files->first()))->assertOk();
        foreach (['quoted', 'rejected'] as $status) {
            $this->patch(route('housekeeping.custom-requests.update', $one), ['status' => $status, 'quoted_price' => 12500, 'admin_notes' => 'Nota privada'])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame($status, $one->fresh()->status);
            $this->assertSame('12500.00', $one->fresh()->quoted_price);
            $this->assertSame('Nota privada', $one->fresh()->admin_notes);
        }
        $this->patch(route('housekeeping.custom-requests.update', $one), ['status' => 'invalid', 'quoted_price' => -1])->assertSessionHasErrors(['status', 'quoted_price']);
    }

    public function test_normal_user_cannot_access_housekeeping_or_update_request(): void
    {
        $request = $this->makeRequest();
        $this->get('/housekeeping/personalizados')->assertForbidden();
        $this->get(route('housekeeping.custom-requests.show', $request))->assertForbidden();
        $this->patch(route('housekeeping.custom-requests.update', $request), ['status' => 'approved', 'quoted_price' => 1])->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_product_specific_options_are_validated_and_color_is_derived(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([['product_type' => 'shirt_black'], ['variant' => 'invalid'], ['product_type' => 'stickers'], ['product_type' => 'patch']] as $options) {
            $this->post('/personalizados', $this->payload($options))->assertSessionHasErrors();
        }
        $this->post('/personalizados', $this->payload(['product_type' => 'shirt_black', 'size' => 'XL', 'print_position' => 'Frontal', 'color' => 'Blanca']))->assertSessionHasNoErrors();
        $this->assertSame('Negra', CustomRequest::firstOrFail()->color);
    }

    public function test_admin_filters_search_and_private_file_path_guard(): void
    {
        $one = $this->makeRequest();
        $two = $this->makeRequest();
        $two->update(['status' => 'rejected']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([$one->request_number, $one->customer_name, $one->customer_email] as $q) {
            $this->get('/housekeeping/personalizados?'.http_build_query(['status' => 'pending', 'product_type' => 'mug', 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(), 'q' => $q]))->assertOk()->assertSee($one->request_number)->assertDontSee($two->request_number);
        }
        $this->getJson('/housekeeping/personalizados?status=invalid&q[]=bad')->assertUnprocessable();
        $file = $one->files->first();
        $file->update(['stored_path' => '../secret.png']);
        $this->get(route('custom-requests.files.show', $file))->assertNotFound();
    }
}
