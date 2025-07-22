<?php

namespace Database\Seeders;

use App\Models\Flights\Airport;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class AirportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */

    public function run()
    {
        $total = 6711;
        $limit = 200;
        $offset = 0;
        $fetchedCount = 0;

        do {
            $response = Http::when(App::environment('local'), function ($http) {
                return $http->withoutVerifying();
            })->get('https://api.aviationstack.com/v1/airports', [
                'access_key' => env('AVIATIONSTACK_KEY'),
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if ($response->failed()) {
                dump("Failed at offset {$offset}");
                break;
            }

            $data = $response->json()['data'] ?? [];
            if (empty($data)) break;

            foreach ($data as $airport) {
                Airport::updateOrCreate(
                    ['airport_id' => $airport['id']],
                    [
                        'name' => $airport['airport_name'],
                        'iata_code' => $airport['iata_code'],
                        'icao_code' => $airport['icao_code'],
                        'country_code' => $airport['country_iso2'],
                        'country' => $airport['country_name'],
                        'city' => $airport['city_iata_code'] ?? 'N/A',
                        'latitude' => $airport['latitude'],
                        'longitude' => $airport['longitude'],
                    ]
                );
                $fetchedCount++;
            }

            echo "Fetched {$fetchedCount} airports so far...\n";

            // Stop if less than expected returned (likely last page)
            if (count($data) < $limit) break;

            $offset += $limit;
        } while ($offset < $total);

        echo "Seeder completed. Total airports seeded: {$fetchedCount}\n";
    }
}
