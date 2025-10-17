<?php

namespace App\Assistants;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;

class CharteringConfirmationPdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines)
    {
        return isset($lines[6], $lines[8], $lines[17], $lines[24])
            && trim($lines[6]) === "CHARTERING CONFIRMATION"
            && trim($lines[8]) === "SHIPPING PRICE"
            && Str::contains($lines[17], "Test Client")
            && Str::startsWith($lines[24], "VAT NUM:");
    }


    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        if (!static::validateFormat($lines)) {
            throw new \Exception("Invalid PDF format");
        }

        // 1️⃣ Customer
        $customer = [
            'side' => 'sender',
            'details' => [
                'company' => trim($lines[17] ?? ''),
                'street_address' => trim($lines[18] ?? ''),
                'city' => 'VILNIUS',
                'postal_code' => trim(explode(' ', $lines[20] ?? '')[0]),
                'country' => 'LT',
                'contact_person' => trim($lines[17] ?? ''),
                'vat_code' => str_replace('VAT NUM: ', '', trim($lines[24] ?? '')),
                'email' => '',
            ]
        ];

        // 2️⃣ Loading datetime
        $date_line = trim(str_replace("\r", "", $lines[2] ?? '')); // "12/09/2025"
        $time_line = trim(str_replace("\r", "", $lines[4] ?? '')); // "11:19:50"

        // Include seconds in format: H:i:s
        $loading_datetime = Carbon::createFromFormat('d/m/Y H:i:s', $date_line . ' ' . $time_line)
            ->toIsoString();

        $loading_locations = [[
            'company_address' => $customer['details'],
            'time' => [
                'datetime_from' => $loading_datetime,
            ],
        ]];

        // 3️⃣ Destination
        $destination_locations = [[
            'company_address' => [
                'company' => trim($lines[26] ?? ''),
                'street_address' => trim($lines[27] ?? '') . ', ' . trim($lines[28] ?? ''),
                'postal_code' => trim(explode(' ', $lines[29] ?? '')[0]),
                'city' => 'BURTON UPON TRENT',
                'country' => 'GB',
                'contact_person' => trim(str_replace('Contact: ', '', $lines[32] ?? '')),
                'email' => trim($lines[37] ?? ''),
            ]
        ]];

        // 4️⃣ Attachment filenames
        $attachment_filenames = [$attachment_filename ? mb_strtolower($attachment_filename) : ''];

        // 5️⃣ Order reference
        $order_reference = str_replace('REF.:', '', trim($lines[39] ?? ''));

        // 6️⃣ Cargos (simple example using SHIPPING PRICE)
        $freight_price = floatval(str_replace(',', '.', trim($lines[9] ?? '0')));
        $freight_currency = strtoupper(explode(' ', trim($lines[10] ?? 'EUR'))[0]);

        $cargos = [[
            'title' => 'Diesel', // or parse from other lines
            'weight' => floatval(str_replace(',', '.', trim($lines[92] ?? '0'))),
            'volume' => floatval(str_replace(',', '.', trim($lines[94] ?? '0'))),
            'package_count' => 1,
            'package_type' => 'EPAL',
            'value' => $freight_price,
            'currency' => $freight_currency,
        ]];

        // 7️⃣ Customer number (optional)
        $customer_number = trim($lines[5] ?? '');

        // 8️⃣ Compose data
        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            'customer_number'
        );

        // 9️⃣ Create order
        $this->createOrder($data);
    }
}
