<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Uploaded files live on the private `local` disk and are only reachable through
 * short-lived signed URLs issued by the API after an authorization check.
 *
 * Files uploaded before this change sit on the `public` disk; they are still served
 * (through the same signed route) until `gordeh:privatize-files` moves them.
 */
class PrivateFiles
{
    public const DISK = 'local';
    public const LEGACY_DISK = 'public';

    public static function store(UploadedFile $file, string $directory): string|false
    {
        return $file->store($directory, self::DISK);
    }

    /**
     * Reduce a stored value (relative path, /storage/... path or full URL of our own
     * storage) to the relative path on disk. Returns null for empty/external values.
     */
    public static function path(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (Str::contains($value, '/storage/')) {
            $value = Str::after($value, '/storage/');
        } elseif (Str::startsWith($value, ['http://', 'https://', '/'])) {
            return null;
        }

        $value = ltrim($value, '/');

        return $value === '' || Str::contains($value, '..') ? null : $value;
    }

    /**
     * Signed URL for a stored file. External URLs and empty values are returned untouched.
     * $minutes = null gives a URL that stays identical within the current hour, so browsers
     * can cache things like avatars.
     */
    public static function signedUrl(?string $value, ?int $minutes = 10): ?string
    {
        $path = self::path($value);
        if ($path === null) {
            return $value;
        }

        $expires = $minutes === null
            ? now()->startOfHour()->addHours(2)
            : now()->addMinutes($minutes);

        return URL::temporarySignedRoute('files.serve', $expires, ['path' => $path]);
    }

    /** The disk that currently holds the file, or null if it is missing. */
    public static function diskFor(string $path): ?string
    {
        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    public static function size(?string $value): ?int
    {
        $path = self::path($value);
        $disk = $path ? self::diskFor($path) : null;

        return $disk ? Storage::disk($disk)->size($path) : null;
    }

    public static function delete(?string $value): void
    {
        $path = self::path($value);
        if ($path === null) {
            return;
        }

        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            Storage::disk($disk)->delete($path);
        }
    }
}
