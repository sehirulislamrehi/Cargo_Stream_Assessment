<?php

namespace App\Assistants;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;

class CharteringConfirmationPdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines)
    {
        foreach($lines as $line){
            if($line == "CHARTERING CONFIRMATION"){
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
                'country' => 'AT',
                'vat_code' => 'ATU74076812',
                'contact_person' => 'John Doe', // replace with actual if available
                'phone' => '+43 5337 12345',     // optional, based on invoice
                'email' => 'info@transalliance.at', // optional
            ],
        ];

        $loading_locations = [[
            'company_address' => [
                'company' => 'TRANSALLIANCE TS LTD',
                'street_address' => 'SUITE 8/9 FARADAY COURT',
                'postal_code' => '6233',
                'city' => 'Kramsach',
                'country' => 'AT',
                'contact_person' => 'John Doe',  // replace with actual if available
                'email' => 'info@transalliance.at', // replace with actual if available
                'vat_code' => 'ATU74076812',
            ],
            'time' => [
                'datetime_from' => '2025-10-19 08:00:00', // replace with actual loading datetime if known
            ],
        ]];

        $destination_locations = [[
            'company_address' => [
                'company' => $destination['company_address']['company'] ?? '',
                'street_address' => $destination['company_address']['street_address'] ?? '',
                'postal_code' => $destination['company_address']['postal_code'] ?? '',
                'city' => $destination['company_address']['city'] ?? '',
                'country' => $destination['company_address']['country'] ?? '',
                'contact_person' => $destination['company_address']['contact_person'] ?? '',
                'email' => $destination['company_address']['email'] ?? '',
                'vat_code' => $destination['company_address']['vat_code'] ?? '',
            ],
            'time' => [
                'datetime_from' => $delivery_datetime ?? null,
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

        // $key = array_search(true, array_map(fn($v) => str_contains($v, 'REF.'), $lines));
        // $ref_number = "";
        // if ($key !== false) {
        //     // Extract the number
        //     preg_match('/\d+/', $lines[$key], $matches);
        //     $ref_number = $matches[0];
        // }
        // $order_reference = $ref_number;

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
