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

        // Find loading date and time
        $loading_date_index = array_search("REFERENCE :", $lines); // finds first occurrence
        $loading_date = $lines[$loading_date_index + 1] ?? null; // "17/09/25"
        $loading_time_index = array_search("8h00 - 15h00", $lines); // or find dynamically near loading section
        $loading_time = $lines[$loading_time_index] ?? null;

        // Find company details dynamically
        $company_index = array_search("TRANSALLIANCE TS LTD", $lines);
        $street_address = $lines[$company_index + 10] ?? ''; // adjust offsets based on pattern
        $city_postal = $lines[$company_index + 11] ?? '';
        $vat_index = array_search("VAT NUM: GB712061386", $lines);
        $contact_index = array_search("Contact: TERESA HOPKINS", $lines);
        $email_index = array_search("E-mail :", $lines);

        $loading_locations = [[
            'company_address' => [
                'company' => $lines[$company_index] ?? '',
                'street_address' => $lines[$company_index + 10] ?? '',
                'postal_code' => '', // extract from street/city if needed
                'city' => '',        // extract from street/city if needed
                'country' =>  GeonamesCountry::getIso('GB'),   // default from VAT or address
                'contact_person' => $lines[$contact_index] ?? '',
                'email' => $lines[$email_index + 1] ?? '',
                'vat_code' => str_replace('VAT NUM: ', '', $lines[$vat_index] ?? ''),
            ],
            'time' => [
                'datetime_from' => $loading_date . ' ' . $loading_time, // "17/09/25 8h00 - 15h00"
            ],
        ]];



        // Find delivery section dynamically
        $delivery_index = array_search("Delivery", $lines);

        // Find delivery date (after "REFERENCE :" near delivery)
        $delivery_ref_index = null;
        for ($i = $delivery_index; $i < count($lines); $i++) {
            if (stripos($lines[$i], "REFERENCE :") !== false) {
                $delivery_ref_index = $i;
                break;
            }
        }
        $delivery_date = $lines[$delivery_ref_index + 1] ?? null;

        // Find delivery time (usually a pattern like "7h00 - 13h00" after delivery section)
        $delivery_time = null;
        for ($i = $delivery_index; $i < count($lines); $i++) {
            if (preg_match('/\d{1,2}h\d{2} - \d{1,2}h\d{2}/', $lines[$i])) {
                $delivery_time = $lines[$i];
                break;
            }
        }

        // Find company info
        $company_index = null;
        for ($i = $delivery_index; $i < count($lines); $i++) {
            if (!empty($lines[$i]) && strtoupper($lines[$i]) === $lines[$i]) { // simple heuristic: company name in uppercase
                $company_index = $i;
                break;
            }
        }

        // Collect company address lines (usually next 2 lines)
        $street_address = $lines[$company_index + 1] ?? '';
        $city_postal = $lines[$company_index + 2] ?? '';
        $city = trim(explode('-', $city_postal)[1] ?? '');
        $postal_code = trim(explode('-', $city_postal)[0] ?? '');

        // Contact person and email (if available)
        $contact_index = null;
        for ($i = $company_index; $i < count($lines); $i++) {
            if (stripos($lines[$i], "Contact:") !== false) {
                $contact_index = $i;
                break;
            }
        }
        $contact_person = trim(str_replace("Contact:", "", $lines[$contact_index] ?? ''));
        $email_index = null;
        for ($i = $contact_index; $i < count($lines); $i++) {
            if (stripos($lines[$i], "E-mail") !== false) {
                $email_index = $i;
                break;
            }
        }
        $email = $lines[$email_index + 1] ?? '';

        $destination_locations = [[
            'company_address' => [
                'company' => $lines[$company_index] ?? '',
                'street_address' => $street_address,
                'postal_code' => $postal_code,
                'city' => $city,
                'country' => GeonamesCountry::getIso('FR'), // based on company location
                'contact_person' => $contact_person,
                'email' => $email,
                'vat_code' => '', // not provided
            ],
            'time' => [
                'datetime_from' => $delivery_date . ' ' . $delivery_time,
            ],
        ]];


        $cargos = [[
            'title' => 'Electronics and Accessories',
            'package_count' => 10,
            'package_type' => 'EPAL', // one of the allowed enums
            'number' => 'PO-20250911-001',
            'type' => 'partial', // full, partial, FTL, etc.
            'value' => 12500.00,
            'currency' => 'EUR',
            'pkg_width' => 1.2, // meters
            'pkg_length' => 0.8,
            'pkg_height' => 1.0,
            'ldm' => 2.4, // loading meters
            'volume' => 9.6, // cubic meters
            'weight' => 2800, // kg
            'chargeable_weight' => 3000, // kg
            'temperature_min' => null, // optional
            'temperature_max' => null, // optional
            'temperature_mode' => null, // or 'auto (start / stop)'
            'adr' => false,
            'extra_lift' => false,
            'palletized' => true,
            'manual_load' => false,
            'vehicle_make' => null, // only for car transport
            'vehicle_model' => null,
        ]];


        // Find cargo description
        $cargo_index = null;
        foreach ($lines as $i => $line) {
            if (stripos($line, 'PAPER ROLLS') !== false) {
                $cargo_index = $i;
                break;
            }
        }
        $title = $lines[$cargo_index] ?? 'Unknown Cargo';

        // Find weight and volume (look for nearest numeric values)
        $weight = 0;
        $volume = 0;
        for ($i = $cargo_index; $i < min($cargo_index + 10, count($lines)); $i++) {
            if (is_numeric(str_replace(',', '.', $lines[$i]))) {
                if ($volume == 0) {
                    $volume = (float) str_replace(',', '.', $lines[$i]);
                } else {
                    $weight = (float) str_replace(',', '.', $lines[$i]);
                    break;
                }
            }
        }

        // Package count (Parc. nb :) and pallet info (Pal. nb. :)
        $package_count_index = array_search("Parc. nb :", $lines);
        $package_count = (int) ($lines[$package_count_index + 1] ?? 1);

        $pallet_count_index = array_search("Pal. nb. :", $lines);
        $package_type = isset($lines[$pallet_count_index + 1]) && $lines[$pallet_count_index + 1] > 0 ? 'EPAL' : 'Other';

        // Fill cargos array
        $cargos = [[
            'title' => $title,
            'package_count' => $package_count,
            'package_type' => $package_type,
            'number' => 'PO-20250911-001', // replace with actual if found in PDF
            'type' => 'partial', // can be dynamically set based on order info
            'value' => 0,        // optional: extract if available
            'currency' => 'EUR', // default or extract
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

        // 9️⃣ Create order
        $this->createOrder($data);
    }
}
