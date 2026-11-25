<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProfileImage;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class UploadController extends Controller
{
    /** Data URL me base64 payload ka upper bound (chars me). */
    private const MAX_BASE64_CHARS = 10485760;

    /** Decoded binary ka upper bound. */
    private const MAX_BYTES = 3 * 1024 * 1024;

    /** Detected MIME => extension jo hum file ko denge. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function uploadImage(Request $request)
    {
        $validated = $request->validate([
            'image' => ['required', 'string', 'max:'.self::MAX_BASE64_CHARS],
        ]);

        $relative = $this->storeValidatedImage($validated['image']);

        return response()->json([
            'message' => 'Image uploaded successfully',
            'url' => Storage::disk('public')->url($relative),
        ]);
    }

    public function updateProfileImage(Request $request)
    {
        $validated = $request->validate([
            'image' => ['required', 'string', 'max:'.self::MAX_BASE64_CHARS],
        ]);

        $user = $request->user();
        $student = $user->role === 'student'
            ? $user->student()->firstOrFail()
            : null;
        $previousImages = [$user->profile_image, $student?->profile_image];
        $relative = $this->storeValidatedImage($validated['image']);
        $url = Storage::disk('public')->url($relative);

        try {
            DB::transaction(function () use ($user, $student, $url): void {
                $user->update(['profile_image' => $url]);
                $student?->update(['profile_image' => $url]);
            });
        } catch (\Throwable $exception) {
            ProfileImage::delete($url);
            throw $exception;
        }

        ProfileImage::delete(...$previousImages);

        $payload = [
            'message' => 'Profile photo updated successfully',
            'user' => $user->fresh(),
        ];

        if ($student) {
            $payload['student'] = $student->fresh()->load('user');
        }

        return response()->json($payload);
    }

    private function storeValidatedImage(string $data): string
    {
        if (! str_starts_with($data, 'data:image/')) {
            throw new HttpResponseException(response()->json([
                'message' => 'Invalid image data.',
            ], 422));
        }

        [, $base64] = array_pad(explode(',', $data, 2), 2, null);

        if ($base64 === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'Invalid image data.',
            ], 422));
        }

        $decoded = base64_decode($base64, true);

        if ($decoded === false || $decoded === '') {
            throw new HttpResponseException(response()->json([
                'message' => 'Invalid image data.',
            ], 422));
        }

        if (strlen($decoded) > self::MAX_BYTES) {
            throw new HttpResponseException(response()->json([
                'message' => 'Image is too large. Please pick a smaller one.',
            ], 422));
        }

        // Verify bytes instead of trusting the data URL's claimed type.
        $detected = @getimagesizefromstring($decoded);
        $mime = $detected['mime'] ?? null;

        if ($mime === null || ! isset(self::EXTENSIONS[$mime])) {
            throw new HttpResponseException(response()->json([
                'message' => 'That file is not a valid image. Use JPG, PNG, GIF or WEBP.',
            ], 422));
        }

        $filename = Str::uuid().'.'.self::EXTENSIONS[$mime];
        $relative = ProfileImage::DIRECTORY.'/'.$filename;
        if (! Storage::disk('public')->put($relative, $decoded)) {
            throw new RuntimeException('Unable to store the uploaded image.');
        }

        return $relative;
    }
}
