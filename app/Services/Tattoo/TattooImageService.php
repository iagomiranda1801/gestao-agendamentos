<?php

namespace App\Services\Tattoo;

use App\Models\TattooRequest;
use App\Models\TattooRequestImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TattooImageService
{
    public function upload(TattooRequest $request, UploadedFile $file, ?string $messageId = null): TattooRequestImage
    {
        if ($request->images()->count() >= 5) {
            throw ValidationException::withMessages(['image' => 'Este pedido já tem cinco imagens.']);
        }
        $mime = $this->normalizedMime((string) $file->getMimeType());
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['image' => 'Envie uma imagem JPEG, PNG ou WebP de até 10 MB.']);
        }
        if ($messageId !== null && ($existing = TattooRequestImage::query()->where('company_id', $request->company_id)->where('whatsapp_message_id', $messageId)->first())) {
            return $existing;
        }
        $disk = config('filesystems.tattoo_disk', 'local');
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $path = 'agendaqui/'.$request->company_id.'/tatuagem/'.$request->getKey().'/'.Str::uuid().'.'.$extension;
        if (! Storage::disk($disk)->put($path, $file->getContent(), ['visibility' => 'private'])) {
            throw ValidationException::withMessages(['image' => 'Não foi possível salvar a imagem.']);
        }
        try {
            $image = new TattooRequestImage([
                'tattoo_request_id' => $request->getKey(), 'disk' => $disk, 'path' => $path,
                'mime_type' => $mime, 'size_bytes' => $file->getSize(),
                'original_name' => $file->getClientOriginalName(), 'whatsapp_message_id' => $messageId,
            ]);
            $image->company_id = $request->company_id;
            $image->save();

            return $image;
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    protected function normalizedMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        if (in_array($mime, ['image/jpg', 'image/pjpeg'], true)) {
            return 'image/jpeg';
        }

        return $mime;
    }
}
