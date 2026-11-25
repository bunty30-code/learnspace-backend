<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/*
|--------------------------------------------------------------------------
| ProfileImage
|--------------------------------------------------------------------------
|
| Avatar URL ko users aur students dono tables me rakhna padta hai — admin
| ka student list `student.user.profile_image` padhta hai, header avatar
| `student.profile_image` padhta hai. Ek jagah likha do dusri jagah stale
| reh jaati hai, isliye sync aur purani file ki cleanup ek hi jagah hai.
|
*/

class ProfileImage
{
    public const DIRECTORY = 'avatars';

    public static function store(string $contents, string $extension, string $requestOrigin): string
    {
        $credentials = self::cloudinaryCredentials();

        if ($credentials !== null) {
            $timestamp = now()->timestamp;
            $publicId = (string) Str::uuid();
            $parameters = [
                'folder' => self::DIRECTORY,
                'public_id' => $publicId,
                'timestamp' => $timestamp,
            ];
            $signature = self::signature($parameters, $credentials['api_secret']);
            $endpoint = 'https://api.cloudinary.com/v1_1/'
                .rawurlencode($credentials['cloud_name'])
                .'/image/upload';

            $response = Http::timeout(30)
                ->attach('file', $contents, $publicId.'.'.$extension)
                ->post($endpoint, $parameters + [
                    'api_key' => $credentials['api_key'],
                    'signature' => $signature,
                ]);
            $response->throw();

            $url = $response->json('secure_url');
            $urlParts = is_string($url) ? parse_url($url) : false;

            if (
                ! is_string($url)
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || ($urlParts['scheme'] ?? null) !== 'https'
                || empty($urlParts['host'])
            ) {
                throw new RuntimeException('Cloudinary did not return a valid image URL.');
            }

            return $url;
        }

        if (app()->environment('production')) {
            throw new RuntimeException('Cloudinary is not configured for profile image storage.');
        }

        $relative = self::DIRECTORY.'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk('public')->put($relative, $contents)) {
            throw new RuntimeException('Unable to store the uploaded image.');
        }

        return rtrim($requestOrigin, '/').'/storage/'.$relative;
    }

    /**
     * Naya photo set karo, users row ko mirror karo, aur badli hui purani
     * photo ka file delete kar do (garbage na bache).
     */
    public static function replace(User $user, ?string $previousUrl, ?string $newUrl): void
    {
        if ($newUrl !== $user->profile_image) {
            $user->forceFill(['profile_image' => $newUrl])->save();
        }

        // Sirf purani file delete karo — nayi to abhi use ho rahi hai. Aur agar
        // URL badla hi nahi (double submit / form resubmit) to kuch mat karo,
        // warna live avatar delete ho jayegi.
        if ($previousUrl !== null && $previousUrl !== $newUrl) {
            self::delete($previousUrl);
        }
    }

    /**
     * Sirf tab delete karo jab path hamare avatars directory ke andar ho —
     * koi URL dekar doosri files delete na ho jayein.
     */
    public static function delete(?string ...$urls): void
    {
        $paths = [];
        $credentials = self::cloudinaryCredentials();
        $publicIds = [];

        foreach ($urls as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $publicId = $credentials === null
                ? null
                : self::cloudinaryPublicId($url, $credentials['cloud_name']);

            if ($publicId !== null) {
                $publicIds[] = $publicId;

                continue;
            }

            $path = self::toRelativePath($url);

            if ($path !== null) {
                $paths[] = $path;
            }
        }

        if ($paths !== []) {
            Storage::disk('public')->delete(array_values(array_unique($paths)));
        }

        foreach (array_unique($publicIds) as $publicId) {
            self::deleteFromCloudinary($publicId, $credentials);
        }
    }

    /**
     * Parse the conventional CLOUDINARY_URL or split Cloudinary environment values.
     *
     * @return array{cloud_name: string, api_key: string, api_secret: string}|null
     */
    private static function cloudinaryCredentials(): ?array
    {
        $url = config('services.cloudinary.url');
        $parsed = is_string($url) && $url !== '' ? parse_url($url) : false;
        $credentials = [
            'cloud_name' => config('services.cloudinary.cloud_name') ?: ($parsed['host'] ?? null),
            'api_key' => config('services.cloudinary.api_key') ?: ($parsed['user'] ?? null),
            'api_secret' => config('services.cloudinary.api_secret') ?: ($parsed['pass'] ?? null),
        ];
        $provided = array_filter($credentials, fn ($value) => is_string($value) && $value !== '');

        if ($provided === []) {
            return null;
        }

        if (count($provided) !== count($credentials)) {
            throw new RuntimeException('Cloudinary credentials are incomplete.');
        }

        return $credentials;
    }

    private static function signature(array $parameters, string $apiSecret): string
    {
        ksort($parameters);

        $signed = [];
        foreach ($parameters as $key => $value) {
            $signed[] = $key.'='.$value;
        }

        return sha1(implode('&', $signed).$apiSecret);
    }

    /**
     * @param  array{cloud_name: string, api_key: string, api_secret: string}  $credentials
     */
    private static function deleteFromCloudinary(string $publicId, array $credentials): void
    {
        $parameters = [
            'public_id' => $publicId,
            'timestamp' => now()->timestamp,
        ];

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post(
                    'https://api.cloudinary.com/v1_1/'
                        .rawurlencode($credentials['cloud_name'])
                        .'/image/destroy',
                    $parameters + [
                        'api_key' => $credentials['api_key'],
                        'signature' => self::signature($parameters, $credentials['api_secret']),
                    ],
                );
            $response->throw();

            if (! in_array($response->json('result'), ['ok', 'not found'], true)) {
                Log::warning('Cloudinary did not confirm profile image cleanup.', [
                    'public_id' => $publicId,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Unable to clean up replaced Cloudinary profile image.', [
                'public_id' => $publicId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function cloudinaryPublicId(string $url, string $cloudName): ?string
    {
        $parts = parse_url($url);
        if (($parts['host'] ?? null) !== 'res.cloudinary.com') {
            return null;
        }

        $segments = explode('/', trim(rawurldecode($parts['path'] ?? ''), '/'));
        if (
            count($segments) < 5
            || $segments[0] !== $cloudName
            || $segments[1] !== 'image'
            || $segments[2] !== 'upload'
        ) {
            return null;
        }

        $versionIndex = null;
        foreach (array_slice($segments, 3) as $index => $segment) {
            if (preg_match('/^v[0-9]+$/', $segment) === 1) {
                $versionIndex = $index + 4;
                break;
            }
        }

        if ($versionIndex === null) {
            return null;
        }

        $assetPath = implode('/', array_slice($segments, $versionIndex));
        $extensionPosition = strrpos($assetPath, '.');
        if ($extensionPosition === false) {
            return null;
        }

        $publicId = substr($assetPath, 0, $extensionPosition);

        return str_starts_with($publicId, self::DIRECTORY.'/')
            ? $publicId
            : null;
    }

    /**
     * `{API_ORIGIN}/storage/avatars/x.jpg` ya `avatars/x.jpg` ko disk-relative
     * path me badlo. `avatars/` prefix ke bahar ho to null (delete mat karo).
     */
    private static function toRelativePath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim(rawurldecode($path), '/');

        // `/storage/avatars/x.jpg` ka sirf `avatars/x.jpg` hissa chahiye.
        $prefix = self::DIRECTORY.'/';
        $index = strpos($path, 'storage/'.$prefix);

        if ($index !== false) {
            $path = substr($path, $index + strlen('storage/'));
        }

        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        // `..` se disk ke bahar nikalne se roko.
        if (in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }
}
