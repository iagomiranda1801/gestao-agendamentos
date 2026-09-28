<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TattooRequestImage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

class TattooImageController
{
    public function __invoke(Company $company, TattooRequestImage $image)
    {
        abort_unless((int) $company->getKey() === (int) $image->company_id, 404);
        abort_unless(auth()->user()?->canAccessTenant($company), 403);
        Filament::setTenant($company);
        abort_unless(auth()->user()->can('view', $image->request), 403);

        abort_unless(Storage::disk($image->disk)->exists($image->path), 404);

        return Storage::disk($image->disk)->response(
            $image->path,
            $image->original_name ?: basename($image->path),
            ['Content-Type' => $image->mime_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
