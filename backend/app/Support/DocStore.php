<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where private documents live. New uploads go to the non-web-reachable 'documents' disk; files
 * uploaded before that move are still on the web-reachable 'public' disk, so every read falls back
 * to it until `php artisan files:privatize` has moved them across.
 */
class DocStore
{
    private const PRIVATE = 'documents';

    private const LEGACY = 'public';

    public static function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, self::PRIVATE);
    }

    private static function diskFor(string $path): ?string
    {
        foreach ([self::PRIVATE, self::LEGACY] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    public static function exists(?string $path): bool
    {
        return $path && self::diskFor($path) !== null;
    }

    public static function response(string $path): StreamedResponse
    {
        $disk = self::diskFor($path);
        abort_unless($disk, 404);

        return Storage::disk($disk)->response($path, Str::afterLast($path, '/'));
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            foreach ([self::PRIVATE, self::LEGACY] as $disk) {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    public static function deleteDirectory(string $directory): int
    {
        $count = 0;
        foreach ([self::PRIVATE, self::LEGACY] as $disk) {
            $count += count(Storage::disk($disk)->allFiles($directory));
            Storage::disk($disk)->deleteDirectory($directory);
        }

        return $count;
    }

    /** Moves every legacy file to the private disk. @return int files moved */
    public static function privatize(): int
    {
        $moved = 0;
        $public = Storage::disk(self::LEGACY);
        foreach (['customers', 'loans', 'speed-drafts'] as $root) {
            foreach ($public->allFiles($root) as $file) {
                Storage::disk(self::PRIVATE)->put($file, $public->get($file));
                $public->delete($file);
                $moved++;
            }
        }

        return $moved;
    }
}
