<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;

class CharteringConfirmationPdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines)
    {
        foreach ($lines as $line) {
            if ($line == "CHARTERING CONFIRMATION") {
                return true;
            }
        }
        return false;
    }


    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        if (!static::validateFormat($lines)) {
            throw new \Exception("Invalid PDF format");
        }

        $lines = array_filter($lines, function ($line) {
            return trim($line) !== '';
        });

        $vatNumber = '';
        foreach ($lines as $line) {
            // Find the line containing "VAT"
            if (stripos($line, 'VAT') !== false) {
                // Extract digits after "GB"
                if (preg_match('/GB(\d+)/', $line, $matches)) {
                    $vatNumber = $matches[1];
                    break;
                }
            }
        }

        $customer = [
            'side' => 'sender', // usually "sender" or "receiver" on invoices
            'details' => [
                'company' => 'TRANSALLIANCE TS LTD',
                'street_address' => 'SUITE 8/9 FARADAY COURT',
                'city' => 'Kramsach',
                'postal_code' => '6233',
                'country' => GeonamesCountry::getIso('AT'),
                'vat_code' => 'ATU74076812',
                'contact_person' => 'John Doe', // replace with actual if available
                'phone' => '+43 5337 12345',     // optional, based on invoice
                'email' => 'info@transalliance.at', // optional
            ],
        ];

        /************************* BUILD loading location *************************/
        $loading_index = array_search('Loading', $lines);

        $loading_location_date_time = null;
        if ($loading_index !== false) {
            for ($i = $loading_index + 1; $i < count($lines); $i++) {
                if (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $lines[$i])) {
                    $loading_location_date_time = Carbon::createFromFormat('y/m/d', $lines[$i]);
                    break;
                }
            }
        }

        $companyName = '';
        foreach ($lines as $index => $line) {
            if ($index <= $loading_index) continue;
            if (preg_match('/^[A-Z\s]+$/i', $line)) {
                $companyName = $line;
                break;
            }
        }

        $addressLine = '';
        foreach ($lines as $index => $line) {
            if ($index <= $loading_index) continue;
            if (strpos($line, 'GB') === 0) {
                $addressLine = $line;
                break;
            }
        }

        $postalCode = '';
        if (preg_match('/^(GB-[A-Z0-9]+)/', $addressLine, $matches)) {
            $postalCode = $matches[1];
        }

        $parts = explode(' ', $addressLine);
        $city = end($parts);

        $telLine = '';
        foreach ($lines as $index => $line) {
            if ($index <= $loading_index) continue;
            if (stripos($line, 'Tel') !== false) {
                $telLine = $line;
                break;
            }
        }


        $loading_locations = [
            [
                'company_address' => [
                    'company' => $companyName,
                    'street_address' => $addressLine,
                    'postal_code' => $postalCode ?: 'N/A',
                    'city' => $city,
                    'country' => 'GB',
                    'contact_person' => $telLine,
                    'email' => '',
                    'vat_code' => $vatNumber,
                ],
                'time' => [
                    'datetime_from' => Carbon::parse($loading_location_date_time)->toIsoString(),
                ],
            ]
        ];
        /************************* BUILD loading location *************************/


        /************************* BUILD destination location *************************/
        $destination_index = array_search('Delivery', $lines);

        $companyName = '';
        $destinationCompanyNameIndex = 0;
        foreach ($lines as $index => $line) {
            if ($index <= $destination_index) continue;
            if (preg_match('/^[A-Z\s]+$/i', $line)) {
                $companyName = $line;
                $destinationCompanyNameIndex = $index;
                break;
            }
        }
        $street_address = $lines[$destinationCompanyNameIndex + 1];

        $postal_code = '';
        $city = '';
        if ($destination_index !== false) {
            foreach ($lines as $index => $line) {
                // Only check lines *after* "Delivery"
                if ($index <= $destination_index) continue;

                // Match a line starting with "-" followed by digits (postal code)
                if (preg_match('/^-\s*\d+/', $line, $matches)) {
                    $postal_code = trim($matches[0], '- ');
                }
                if (preg_match('/^-\s*(\d+)\s+(.+)/', $line, $matches)) {
                    $city = trim($matches[2]); 
                }

                if(!empty($postal_code) && !empty($city)){
                    break;
                }

            }
        }

        $telLine = '';
        foreach ($lines as $index => $line) {
            if ($index <= $destination_index) continue;
            if (stripos($line, 'Tel') !== false) {
                $telLine = $line;
                break;
            }
        }

        $destination_location_date_time = null;
        if ($destination_index !== false) {
            for ($i = $destination_index + 1; $i < count($lines); $i++) {
                if (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $lines[$i])) {
                    $destination_location_date_time = Carbon::createFromFormat('y/m/d', $lines[$i]);
                    break;
                }
            }
        }

        $destination_locations = [[
            'company_address' => [
                'company' => $companyName,
                'street_address' => $street_address,
                'postal_code' => $postal_code,
                'city' => $city,
                'country' => GeonamesCountry::getIso('FR'),
                'contact_person' => $telLine,
                'email' => "",
                'vat_code' => $vatNumber,
            ],
            'time' => [
                'datetime_from' => Carbon::parse($destination_location_date_time)->toIsoString(),
            ],
        ]];
        /************************* BUILD destination location *************************/

        // Find cargo description
        
        $cargoTitle = '';
        foreach ($lines as $line) {
            // Find the line containing "VAT"
            if (stripos($line, 'M. nature') !== false) {
                // Extract digits after "GB"
                if (preg_match('/GB(\d+)/', $line, $matches)) {
                    $vatNumber = $matches[1];
                    break;
                }
            }
        }

        // Find weight and volume (look for nearest numeric values)
        $weight = 0;
        $volume = 0;

        for ($i = $cargo_index ?? 0; $i < min(($cargo_index ?? 0) + 10, count($lines)); $i++) {
            if (preg_match('/^\d+(?:[.,]\d+)?$/', trim($lines[$i]))) {
                $num = (float) str_replace(',', '.', $lines[$i]);
                if ($volume == 0) {
                    $volume = $num;
                } else {
                    $weight = $num;
                    break;
                }
            }
        }

        // Package count (Parc. nb :) and pallet info (Pal. nb. :)
        $package_count = 1;
        foreach ($lines as $i => $line) {
            if (stripos($line, 'parc') !== false && stripos($line, 'nb') !== false) {
                $next = trim($lines[$i + 1] ?? '');
                if (is_numeric($next)) {
                    $package_count = (int) $next;
                }
                break;
            }
        }

        $pallet_count = 0;
        foreach ($lines as $i => $line) {
            if (stripos($line, 'pal') !== false && stripos($line, 'nb') !== false) {
                $next = trim($lines[$i + 1] ?? '');
                if (is_numeric($next)) {
                    $pallet_count = (int) $next;
                }
                break;
            }
        }
        $package_type = $pallet_count > 0 ? 'EPAL' : 'Other';

        // Fill cargos array
        $cargos = [[
            'title' => $title,
            'package_count' => $package_count,
            'package_type' => $package_type,
            'number' => 'PO-20250911-001', // replace with actual if found in PDF
            'type' => 'partial', // can be dynamically set based on order info
            'value' => 0,        // optional: extract if available
            'currency' => GeonamesCountry::getIso('EUR'), // default or extract
            'pkg_width' => null, // optional
            'pkg_length' => null,
            'pkg_height' => null,
            'ldm' => null,
            'volume' => $volume,
            'weight' => $weight,
            'chargeable_weight' => $weight, // or calculate differently
            'temperature_min' => null,
            'temperature_max' => null,
            'temperature_mode' => null,
            'adr' => false,
            'extra_lift' => false,
            'palletized' => true,
            'manual_load' => false,
            'vehicle_make' => null,
            'vehicle_model' => null,
        ]];

        $key = array_search(true, array_map(fn($v) => str_contains($v, 'REF.'), $lines));
        $ref_number = "";
        if ($key !== false) {
            // Extract the number
            preg_match('/\d+/', $lines[$key], $matches);
            $ref_number = $matches[0];
        }
        $order_reference = $ref_number;

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'cargos',
            'order_reference',
        );

        // dd($data);

        // 9️⃣ Create order
        $this->createOrder($data);
    }
}
