<?php

namespace App\Services\Clinical;

use App\Models\Client;
use App\Models\ClinicalAttachment;
use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ClinicalStorageService
{
    public function disk(): string
    {
        return (string) config('filesystems.clinical_disk', 's3');
    }

    public function store(Company $company, Client $client, UploadedFile $file, string $type): string|false
    {
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';

        return $file->storeAs(
            $this->directory($company, $client, $type),
            Str::uuid().'.'.Str::lower($extension),
            $this->disk(),
        );
    }

    public function pathForAttachment(Company $company, Client $client, ClinicalAttachment $attachment): string
    {
        $extension = pathinfo($attachment->original_name, PATHINFO_EXTENSION);
        $extension = filled($extension) ? Str::lower($extension) : 'bin';
        $filename = pathinfo($attachment->path, PATHINFO_FILENAME) ?: Str::uuid()->toString();

        return $this->directory($company, $client, $attachment->type).'/'.$filename.'.'.$extension;
    }

    public function directory(Company $company, Client $client, string $type): string
    {
        $patient = $client->dentalProfile?->record_number ?: 'paciente-'.$client->getKey();
        $patientFolder = preg_replace('/[^A-Za-z0-9-]+/', '-', $patient) ?: 'paciente-'.$client->getKey();
        $category = $type === 'photo' ? 'fotos' : 'documentos';

        return 'agendaqui/'.Str::slug($company->slug).'/pacientes/'.$patientFolder.'/'.$category;
    }
}
