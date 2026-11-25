<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/reset-password', function () {
    return view('auth.reset-password');
})->name('password.reset');

Route::get('/storage/avatars/{filename}', function (string $filename) {
    abort_unless(
        preg_match('/\A[A-Za-z0-9_-]+\.(?:jpe?g|png|gif|webp)\z/i', $filename) === 1,
        404,
    );

    $path = 'avatars/'.$filename;
    abort_unless(Storage::disk('public')->exists($path), 404);

    return Storage::disk('public')->response($path, $filename, [
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('filename', '[A-Za-z0-9_.-]+');
