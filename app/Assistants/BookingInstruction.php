<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;

class BookingInstruction extends PdfClient
{
    public static function validateFormat(array $lines)
    {
        $lines = array_values(array_filter($lines, function ($line) {
            return trim($line) !== '';
        }));

        return $lines[4] == "BOOKING"
        && $lines[5] == "INSTRUCTION";
    }


    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        if (!static::validateFormat($lines)) {
            throw new \Exception("Invalid PDF format");
        }

        $lines = array_values(array_filter($lines, function ($line) {
            return trim($line) !== '';
        }));

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
            'side' => 'sender', // usually "sender" or "receiver"
            'details' => [
                'company' => 'ZIEGLER UK LTD',
                'street_address' => 'LONDON GATEWAY LOGISTICS PARK',
                'city' => 'NORTH 4, NORTH SEA CROSSING',
                'postal_code' => 'SS17 9FJ',
                'country' => GeonamesCountry::getIso('GB'),
                'vat_code' => '',        // not available
                'contact_person' => '',  // not available
                'phone' => '+ 44 (0) 1375 802900',
                'email' => '',           // not available
            ],
        ];

        /************************* BUILD loading location *************************/
        $loading_locations = [];

$collectionIndexes = array_keys($lines, 'Collection');

foreach ($collectionIndexes as $index) {
    $companyName = $lines[$index + 1] ?? '';

    $addressLines = [];
    $dateLine = '';
    $timeLine = '';

    // Start from 2 lines after 'Collection' (company name)
    for ($i = $index + 2; $i < count($lines); $i++) {
        $line = $lines[$i];

        // Stop at next "Collection" to avoid overlapping
        if ($line === 'Collection') {
            break;
        }

        // If line matches a date, stop collecting address
        if (preg_match('/\d{2}\/\d{2}\/\d{4}/', $line)) {
            $dateLine = $line;
            $timeLine = $lines[$i - 1] ?? '';
            break;
        }

        $addressLines[] = $line;
    }

    // Combine address lines into street_address
    $streetAddress = implode(', ', $addressLines);

    // Try to parse postal code and city from last address line if possible
    $postal_code = '';
    $city = '';
    if (!empty($addressLines)) {
        $lastLine = end($addressLines);
        if (preg_match('/([A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2})$/i', $lastLine, $matches)) {
            $postal_code = $matches[1];
            $city = trim(str_replace($matches[1], '', $lastLine));
        }
    }

    $loading_locations[] = [
        'company_address' => [
            'company' => $companyName,
            'street_address' => $streetAddress,
            'postal_code' => $postal_code ?: '',
            'city' => $city ?: '',
            'country' => 'GB',
            'contact_person' => '', // Not provided in lines
            'email' => '',
            'vat_code' => '',
        ],
        'time' => [
            'datetime_from' => $dateLine ? Carbon::createFromFormat('d/m/Y', $dateLine)->toIsoString() : '',
            'time_from_to' => $timeLine,
        ],
    ];
}
dd($loading_locations);
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
                if (isset($lines[$i]) && preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $lines[$i])) {
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
        $cargo_index = null;
        $cargo_keywords = ['ROLL', 'PAPER', 'CARGO', 'GOODS', 'ITEM', 'LOAD'];

        foreach ($lines as $i => $line) {
            foreach ($cargo_keywords as $keyword) {
                if (stripos($line, $keyword) !== false) {
                    $cargo_index = $i;
                    break 2; // break both loops
                }
            }
        }
        $title = $cargo_index !== null ? trim($lines[$cargo_index]) : 'Unknown Cargo';

        // Find weight and volume (look for nearest numeric values)
        $weight = 0;
        $volume = 0;

        for ($i = $cargo_index ?? 0; $i < min(($cargo_index ?? 0) + 10, count($lines)); $i++) {
            if (preg_match('/^\d+(?:[.,]\d+)?$/', trim($lines[$i] ?? ''))) {
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

        $pkg_width = null;

        foreach ($lines as $line) {
            // Remove commas first
            $num = str_replace(',', '', $line);
        
            // Match exactly 8 digits
            if (preg_match('/^\d{8}$/', $num)) {
                $pkg_width = (float) $num;
                break; // stop at the first match
            }
        }


        // Fill cargos array
        $cargos = [[
            'title' => $title,
            'package_count' => $package_count,
            'package_type' => "pallet",
            'number' => 'PO-20250911-001', // replace with actual if found in PDF
            'type' => 'partial', // can be dynamically set based on order info
            'value' => 0,        // optional: extract if available
            'currency' => GeonamesCountry::getIso('EUR'), // default or extract
            'pkg_width' => $pkg_width,
            'pkg_length' => 13600,
            'pkg_height' => 1,
            'ldm' => 0,
            'volume' => $volume,
            'weight' => $weight,
            'chargeable_weight' => $weight, // or calculate differently
            'temperature_min' => 0,
            'temperature_max' => 0,
            'temperature_mode' => "auto (start / stop)",
            'adr' => false,
            'extra_lift' => false,
            'palletized' => true,
            'manual_load' => false,
            'vehicle_make' => "",
            'vehicle_model' => "",
            'currency' => 'EUR'
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
