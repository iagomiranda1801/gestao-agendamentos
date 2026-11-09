<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CompanyLogoController
{
    public function __invoke(Company $company)
    {
        $path = $company->logo_path;
        abort_if(blank($path) || str_starts_with($path, '/') || str_contains($path, '://'), 404);

        // Logos are public branding; resolve only this company's saved file.
        // Stream through the app so private S3 objects work without browser CORS.
        try {
            $disk = Storage::disk($company->logo_disk ?: 'public');
            abort_unless($disk->exists($path), 404);
            $mime = $disk->mimeType($path);
            abort_unless(is_string($mime) && str_starts_with($mime, 'image/'), 404);

            return $disk->response($path, null, [
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
            ]);
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            abort(404);
        }
    }
}
