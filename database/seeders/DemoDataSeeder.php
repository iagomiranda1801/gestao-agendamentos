<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'estudio-ana')->first();

        if (! $company) {
            return;
        }

        $anaAdmin = User::query()->where('email', 'ana@estudioana.test')->first();

        Professional::query()->updateOrCreate(
            [
                'company_id' => $company->getKey(),
                'email' => 'ana@estudioana.test',
            ],
            [
                'name' => 'Ana',
                'specialty' => 'Designer de sobrancelhas',
                'user_id' => $anaAdmin?->getKey(),
                'is_bookable' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        $clients = [
            [
                'name' => 'Cliente Teste Maria',
                'phone' => '(34) 99999-0001',
                'email' => 'maria.teste@example.test',
            ],
            [
                'name' => 'Cliente Teste Juliana',
                'phone' => '(34) 99999-0002',
                'email' => 'juliana.teste@example.test',
            ],
            [
                'name' => 'Cliente Teste Carolina',
                'phone' => '(34) 99999-0003',
                'email' => 'carolina.teste@example.test',
            ],
        ];

        foreach ($clients as $clientData) {
            Client::query()->updateOrCreate(
                [
                    'company_id' => $company->getKey(),
                    'email' => $clientData['email'],
                ],
                [
                    'name' => $clientData['name'],
                    'phone' => $clientData['phone'],
                    'phone_normalized' => PhoneNormalizer::normalize($clientData['phone']) ?? '',
                    'is_active' => true,
                ],
            );
        }
    }
}
