<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

trait HasPublicImage
{
    public static function bootHasPublicImage(): void
    {
        static::updating(function (self $model): void {
            if (! $model->isDirty('image_path')) {
                return;
            }

            $model->deleteStoredPublicImage($model->getOriginal('image_path'));
        });

        static::deleting(function (self $model): void {
            $model->deleteStoredPublicImage($model->image_path);
        });
    }

    public function imageUrl(): ?string
    {
        $path = $this->normalizedPublicImagePath($this->image_path);

        if ($path === null) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function deleteStoredPublicImage(mixed $path): void
    {
        $normalized = $this->normalizedPublicImagePath($path);

        if ($normalized === null) {
            return;
        }

        Storage::disk('public')->delete($normalized);
    }

    public static function publicImageDirectory(int $companyId): string
    {
        return static::publicImageDirectoryPrefix().'/'.$companyId;
    }

    protected function normalizedPublicImagePath(mixed $path): ?string
    {
        if (! is_string($path) || blank($path)) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        $prefix = static::publicImageDirectoryPrefix().'/';

        if ($path === '' || str_contains($path, '..') || ! str_starts_with($path, $prefix)) {
            return null;
        }

        return $path;
    }
}
