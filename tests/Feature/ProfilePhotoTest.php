<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Profile photo upload
|--------------------------------------------------------------------------
|
| Pehle poora flow toota hua tha: `public/storage` link nahi tha (saare
| avatar 404), student photo sirf `students` table me likhi jati thi (admin
| ka list `users.profile_image` padhta hai, isliye wahan kabhi nahi dikhti
| thi), aur badli hui photo ka file kabhi delete nahi hota tha.
|
*/

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    /** 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const SVG = 'data:image/svg+xml;base64,PHN2Zy8+';

    private function makeStudent(): User
    {
        $user = User::create([
            'name' => 'Karan',
            'email' => 'karan@example.com',
            'password' => Hash::make('secret1234'),
            'role' => 'student',
        ]);

        Student::create(['user_id' => $user->id, 'college' => 'MIT']);

        return $user;
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Anish',
            'email' => 'anish@example.com',
            'password' => Hash::make('secret1234'),
            'role' => 'admin',
        ]);
    }

    public function test_it_stores_an_uploaded_photo_and_returns_a_reachable_url(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->makeStudent());

        $response = $this->postJson('/api/auth/upload-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ]);

        $response->assertOk();

        $url = $response->json('url');
        $this->assertStringContainsString('avatars/', $url);
        Storage::disk('public')->assertExists('avatars/'.basename(parse_url($url, PHP_URL_PATH)));

        $this->get(parse_url($url, PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_student_photo_is_saved_to_both_students_and_users_tables(): void
    {
        Storage::fake('public');
        $user = $this->makeStudent();
        Sanctum::actingAs($user);

        $upload = $this->postJson('/api/auth/upload-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->assertOk();

        $url = $upload->json('url');

        $this->putJson('/api/student/profile', ['profile_image' => $url])->assertOk();

        // `users` row bhi update honi chahiye — admin ka student list yahi padhta hai.
        $this->assertSame($url, $user->fresh()->profile_image);
        $this->assertSame($url, Student::where('user_id', $user->id)->value('profile_image'));
    }

    public function test_profile_photo_upload_and_student_profile_update_use_one_request(): void
    {
        Storage::fake('public');
        $user = $this->makeStudent();
        $oldPath = 'avatars/old-student.png';
        Storage::disk('public')->put($oldPath, 'old');
        $oldUrl = Storage::disk('public')->url($oldPath);
        $user->update(['profile_image' => $oldUrl]);
        Student::where('user_id', $user->id)->update(['profile_image' => $oldUrl]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/profile-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->assertOk()
            ->assertJsonStructure(['user', 'student']);

        $url = $response->json('student.profile_image');
        $path = 'avatars/'.basename(parse_url($url, PHP_URL_PATH));

        $this->assertSame($url, $user->fresh()->profile_image);
        $this->assertSame($url, Student::where('user_id', $user->id)->value('profile_image'));
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_profile_photo_upload_updates_admin_profile_in_one_request(): void
    {
        Storage::fake('public');
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/auth/profile-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->assertOk()
            ->assertJsonStructure(['user'])
            ->assertJsonMissingPath('student');

        $this->assertSame($response->json('user.profile_image'), $admin->fresh()->profile_image);
    }

    public function test_cloudinary_upload_saves_the_returned_url_to_user_and_student_profiles(): void
    {
        config([
            'services.cloudinary.cloud_name' => 'test-cloud',
            'services.cloudinary.api_key' => 'test-key',
            'services.cloudinary.api_secret' => 'test-secret',
        ]);
        Http::fake([
            'https://api.cloudinary.com/v1_1/test-cloud/image/upload' => Http::response([
                'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v123/avatars/new-photo.png',
                'public_id' => 'avatars/new-photo',
            ]),
        ]);

        $user = $this->makeStudent();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/profile-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->assertOk();

        $url = 'https://res.cloudinary.com/test-cloud/image/upload/v123/avatars/new-photo.png';
        $this->assertSame($url, $response->json('user.profile_image'));
        $this->assertSame($url, $response->json('student.profile_image'));
        $this->assertSame($url, $response->json('student.user.profile_image'));
        $this->assertSame($url, $user->fresh()->profile_image);
        $this->assertSame($url, Student::where('user_id', $user->id)->value('profile_image'));
        $this->getJson('/api/auth/user')->assertJsonPath('user.profile_image', $url);
        $this->getJson('/api/student/profile')->assertJsonPath('student.profile_image', $url);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.cloudinary.com/v1_1/test-cloud/image/upload');
    }

    public function test_cloudinary_upload_failure_does_not_save_a_profile_or_return_success(): void
    {
        config([
            'services.cloudinary.cloud_name' => 'test-cloud',
            'services.cloudinary.api_key' => 'test-key',
            'services.cloudinary.api_secret' => 'test-secret',
        ]);
        Http::fake([
            'https://api.cloudinary.com/v1_1/test-cloud/image/upload' => Http::response([
                'error' => ['message' => 'Upload failed'],
            ], 503),
        ]);

        $user = $this->makeAdmin();
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/profile-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->assertServerError();

        $this->assertNull($user->fresh()->profile_image);
    }

    public function test_production_does_not_claim_success_when_cloudinary_is_unconfigured(): void
    {
        $this->app['env'] = 'production';
        Storage::fake('public');
        $user = $this->makeAdmin();
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/profile-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->assertServerError();

        $this->assertNull($user->fresh()->profile_image);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_replacing_a_photo_deletes_the_old_file_but_keeps_the_new_one(): void
    {
        Storage::fake('public');
        $user = $this->makeStudent();
        Sanctum::actingAs($user);

        // Real flow: photo upload hoti hai aur turant save bhi ho jaati hai.
        $first = $this->postJson('/api/auth/upload-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->json('url');

        $firstPath = 'avatars/'.basename(parse_url($first, PHP_URL_PATH));
        $this->putJson('/api/student/profile', ['profile_image' => $first])->assertOk();
        Storage::disk('public')->assertExists($firstPath);

        $second = $this->postJson('/api/auth/upload-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->json('url');

        $secondPath = 'avatars/'.basename(parse_url($second, PHP_URL_PATH));
        $this->putJson('/api/student/profile', ['profile_image' => $second])->assertOk();

        // Purani file saaf, nayi file bachi hui.
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
        $this->assertSame($second, $user->fresh()->profile_image);
    }

    public function test_photo_is_not_deleted_when_saved_again_without_changing(): void
    {
        Storage::fake('public');
        $user = $this->makeStudent();
        Sanctum::actingAs($user);

        $url = $this->postJson('/api/auth/upload-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->json('url');

        $path = 'avatars/'.basename(parse_url($url, PHP_URL_PATH));
        $this->putJson('/api/student/profile', ['profile_image' => $url])->assertOk();
        $this->putJson('/api/student/profile', ['profile_image' => $url])->assertOk();

        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_photo_is_saved_and_old_file_is_deleted(): void
    {
        Storage::fake('public');
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $old = 'http://localhost:8000/storage/avatars/old-admin.png';
        Storage::disk('public')->put('avatars/old-admin.png', 'x');
        $admin->forceFill(['profile_image' => $old])->save();

        $url = $this->postJson('/api/auth/upload-image', [
            'image' => 'data:image/png;base64,'.self::PNG,
        ])->json('url');

        $this->putJson('/api/admin/profile', ['profile_image' => $url])->assertOk();

        $this->assertSame($url, $admin->fresh()->profile_image);
        Storage::disk('public')->assertMissing('avatars/old-admin.png');
    }

    public function test_it_rejects_a_file_that_only_claims_to_be_an_image(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->makeStudent());

        // SVG me <script> chala sakta hai — byte-level check use reject karta hai.
        $this->postJson('/api/auth/upload-image', ['image' => self::SVG])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That file is not a valid image. Use JPG, PNG, GIF or WEBP.');

        // PHP payload ko `data:image/png` bol kar bhejna — bytes dekh kar pakda jana chahiye.
        $this->postJson('/api/auth/upload-image', [
            'image' => 'data:image/png;base64,'.base64_encode('<?php system($_GET[0]); ?>'),
        ])->assertStatus(422);

        // `data:image/` prefix hone se hi koi farak nahi padta.
        $this->postJson('/api/auth/upload-image', [
            'image' => 'data:image/png;base64,'.base64_encode('not an image at all'),
        ])->assertStatus(422);

        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_upload_requires_authentication(): void
    {
        Storage::fake('public');

        $this->postJson('/api/auth/upload-image', ['image' => 'data:image/png;base64,'.self::PNG])
            ->assertStatus(401);
    }
}
