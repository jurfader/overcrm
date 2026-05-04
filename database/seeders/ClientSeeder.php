<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        // Insert każdy rekord osobno dla kompatybilności z SQLite
        DB::table('clients')->insert([
            'type' => 'company',
            'name' => 'Firma ABC Sp. z o.o.',
            'short_name' => 'ABC',
            'nip' => '1234567890',
            'email' => 'kontakt@firmaabc.pl',
            'phone' => '+48 123 456 789',
            'street' => 'ul. Biznesowa',
            'building_number' => '15',
            'apartment_number' => '3',
            'postal_code' => '00-001',
            'city' => 'Warszawa',
            'country' => 'Polska',
            'contact_person' => 'Adam Nowak',
            'contact_email' => 'adam@firmaabc.pl',
            'contact_phone' => '+48 123 456 780',
            'status' => 'active',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('clients')->insert([
            'type' => 'company',
            'name' => 'XYZ Industries',
            'short_name' => 'XYZ',
            'nip' => '9876543210',
            'email' => 'info@xyz.com',
            'phone' => '+48 987 654 321',
            'street' => 'al. Przemysłowa',
            'building_number' => '100',
            'postal_code' => '30-001',
            'city' => 'Kraków',
            'country' => 'Polska',
            'contact_person' => 'Maria Kowalska',
            'contact_email' => 'maria@xyz.com',
            'contact_phone' => '+48 987 654 320',
            'status' => 'active',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('clients')->insert([
            'type' => 'company',
            'name' => 'Tech Solutions',
            'short_name' => 'TechSol',
            'nip' => '5555555555',
            'email' => 'hello@techsol.pl',
            'phone' => '+48 555 666 777',
            'street' => 'ul. Technologiczna',
            'building_number' => '42',
            'postal_code' => '80-001',
            'city' => 'Gdańsk',
            'country' => 'Polska',
            'contact_person' => 'Piotr Wiśniewski',
            'contact_email' => 'piotr@techsol.pl',
            'contact_phone' => '+48 555 666 778',
            'status' => 'inactive',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('clients')->insert([
            'type' => 'person',
            'name' => 'Jan Testowy',
            'email' => 'jan.testowy@email.pl',
            'phone' => '+48 111 222 333',
            'street' => 'ul. Prywatna',
            'building_number' => '5',
            'apartment_number' => '10',
            'postal_code' => '60-001',
            'city' => 'Poznań',
            'country' => 'Polska',
            'status' => 'potential',
            'birthday' => '1985-03-15',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
