<?php

namespace App\Services\Clinical;

use App\Enums\CompanyPermission;
use App\Models\Client;
use App\Models\ClinicalAttachment;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ClinicalAttachmentService
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
        'application/dicom', 'application/octet-stream',
    ];

    public function __construct(
        protected ClinicalAuthorizationService $authorization,
        protected ClinicalAuditService $audit,
        protected ClinicalStorageService $storage,
    ) {}

    public function upload(
        Company $company,
        Client $client,
        User $user,
        UploadedFile $file,
        string $type,
        string $title,
        ?Model $attachable = null,
        ?string $description = null,
        ?string $documentDate = null,
    ): ClinicalAttachment {
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);

        if ($file->getSize() > 20 * 1024 * 1024 || ! in_array((string) $file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages(['file' => 'Arquivo inválido. Use PDF, imagem ou DICOM com até 20 MB.']);
        }

        if ($attachable !== null) {
            abort_unless((int) $attachable->getAttribute('company_id') === (int) $company->getKey(), 404);
        }

        return DB::transaction(function () use ($company, $client, $user, $file, $type, $title, $attachable, $description, $documentDate): ClinicalAttachment {
            $disk = $this->storage->disk();
            $path = $this->storage->store($company, $client, $file, $type);

            if ($path === false) {
                throw ValidationException::withMessages(['file' => 'Não foi possível armazenar o arquivo.']);
            }

            try {
                $attachment = new ClinicalAttachment([
                    'attachable_type' => $attachable?->getMorphClass(),
                    'attachable_id' => $attachable?->getKey(),
                    'type' => $type,
                    'title' => $title,
                    'description' => $description,
                    'document_date' => $documentDate,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                ]);
                $attachment->company_id = $company->getKey();
                $attachment->client_id = $client->getKey();
                $attachment->uploaded_by = $user->getKey();
                $attachment->save();
                $this->audit->record($company, $client, $user, 'attachment.uploaded', $attachment, ['type' => $type]);

                return $attachment->refresh();
            } catch (\Throwable $exception) {
                Storage::disk($disk)->delete($path);
                throw $exception;
            }
        });
    }

    public function download(Company $company, ClinicalAttachment $attachment, User $user): mixed
    {
        abort_unless((int) $attachment->company_id === (int) $company->getKey(), 404);
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($attachment->client_id);
        $this->authorization->authorize($user, $company, CompanyPermission::ViewClinicalRecords, $client);
        $this->audit->record($company, $client, $user, 'attachment.downloaded', $attachment);

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function softDelete(Company $company, ClinicalAttachment $attachment, User $user, string $reason): void
    {
        abort_unless((int) $attachment->company_id === (int) $company->getKey(), 404);
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($attachment->client_id);
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);
        DB::transaction(function () use ($company, $client, $attachment, $user, $reason): void {
            $attachment->forceFill(['deleted_by' => $user->getKey(), 'deletion_reason' => $reason])->save();
            $attachment->delete();
            $this->audit->record($company, $client, $user, 'attachment.deleted', $attachment, ['reason' => $reason]);
        });
    }
}
