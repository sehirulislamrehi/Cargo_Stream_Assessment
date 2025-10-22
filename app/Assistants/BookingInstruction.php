<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;

class BookingInstruction extends PdfClient
{

    public $cargoTypes = [
        'full',
        'partial',
        'FCL',
        'LCL',
        'FTL',
        'LTL',
        'PTL',
        'parcel',
        'air shipment',
        'container',
        'car',
    ];

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
                'country' => GeonamesCountry::getIso('UK'),
                'vat_code' => '',        // not available
                'contact_person' => '',  // not available
                'phone' => '+ 44 (0) 1375 802900',
                'email' => '',           // not available
            ],
        ];

        /************************* BUILD loading location *************************/
        $collectionIndexes = array_keys($lines, 'Collection');
        $postcodePattern = '([A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}|FR-?\d{4,6})';
        $collectionIndexes = array_keys($lines, 'Collection');
        $loading_locations = [];

        for ($i = 0; $i < count($collectionIndexes); $i++) {
            $start = $collectionIndexes[$i];
            $end = isset($collectionIndexes[$i + 1]) ? $collectionIndexes[$i + 1] : count($lines);

            $subset = array_slice($lines, $start + 1, $end - $start - 1);

            // Company name
            $company = $subset[0] ?? '';

            // Date (dd/mm/yyyy)
            $foundDate = null;
            foreach ($subset as $line) {
                if (preg_match('/\b(\d{1,2}\/\d{1,2}\/\d{4})\b/', $line, $m)) {
                    $foundDate = $m[1];
                    break;
                }
            }
            $iso = $this->parseDateToIso($foundDate);

            // Postal + city (e.g. "IP14 2QU STOWMARKET")
            $postalCode = '';
            $city = '';
            $candidates = []; // for optional debugging

            foreach ($subset as $line) {
                $raw = trim($line);
                if ($raw === '') continue;

                // normalize spaces and commas
                $norm = preg_replace('/\s+/', ' ', $raw);
                $norm = preg_replace('/\s*,\s*/', ', ', $norm);
                $candidates[] = $norm;

                // 1) City first, with comma: "CITY, POSTCODE" or "CITY, POSTCODE EXTRA"
                if (preg_match('/^(.+?),\s*' . $postcodePattern . '(?:\s*(.*))?$/i', $norm, $m)) {
                    $city = trim($m[1]);
                    $postalCode = trim($m[2]);
                    break;
                }

                // 2) City first, space separated: "CITY POSTCODE" (no comma)
                if (preg_match('/^(.+?)\s+' . $postcodePattern . '(?:\s*(.*))?$/i', $norm, $m)) {
                    // This can accidentally match when postal-first lines are like "IP14 2QU STOWMARKET",
                    // but we check postal-first later if city empty.
                    $city = trim($m[1]);
                    $postalCode = trim($m[2]);
                    break;
                }

                // 3) Postal first: "POSTCODE CITY..." (e.g. "IP14 2QU STOWMARKET")
                if (preg_match('/^' . $postcodePattern . '\s+(.+)$/i', $norm, $m)) {
                    $postalCode = trim($m[1]);
                    // careful: $m[1] may actually be whole match due to grouping; adjust:
                    // In this pattern m[1] is same as postcodePattern match; city is m[2] in full capture
                    // Re-evaluate with explicit indexes:
                    if (count($m) >= 3) {
                        $postalCode = trim($m[1]);
                        $city = trim($m[2]);
                    } else {
                        // fallback: take the rest after the first space
                        $parts = preg_split('/\s+/', $norm, 2);
                        if (isset($parts[1])) $city = trim($parts[1]);
                    }
                    break;
                }

                // 4) Postal anywhere in line: capture postal and then try to infer city from remainder
                if (preg_match('/' . $postcodePattern . '/i', $norm, $m)) {
                    $postalCode = trim($m[1]);
                    // remove postcode portion from line to get city text
                    $cityCandidate = trim(preg_replace('/' . preg_quote($m[0], '/') . '/i', '', $norm));
                    // sanitize trailing punctuation
                    $cityCandidate = trim($cityCandidate, " ,.-");
                    if ($cityCandidate) {
                        $city = $cityCandidate;
                    }
                    // keep looking for a stronger match but break if we at least have postal
                    if ($city) break;
                }
            }

            // Fallback: if city empty but postal line was found with comma on later lines, try reverse scan
            if (!$city && $postalCode) {
                foreach ($subset as $line) {
                    $norm = preg_replace('/\s+/', ' ', trim($line));
                    if (stripos($norm, $postalCode) !== false) {
                        // Look left of the postcode for a preceding token (maybe separated by comma or on previous line)
                        $parts = preg_split('/' . preg_quote($postalCode, '/') . '/i', $norm);
                        if (!empty($parts[0])) {
                            $left = trim($parts[0], " ,.-");
                            if ($left) {
                                $city = $left;
                                break;
                            }
                        }
                    }
                }
            }

            // Final cleanups
            $city = $city ? $city : '';
            $postalCode = $postalCode ? strtoupper($postalCode) : '';

            // Title-case the city (optional)
            if ($city) {
                $city = $this->niceCity($city);
            }


            $loading_locations[] = [
                'company_address' => [
                    'company' => $company,
                    'street_address' => '', // you can extract between company and postal later
                    'postal_code' => $postalCode,
                    'city' => $city,
                    'country' => GeonamesCountry::getIso('UK'),
                    'contact_person' => '',
                    'email' => '',
                    'vat_code' => '',
                ],
                'time' => [
                    'datetime_from' => $iso
                ],
            ];
        }
        /************************* BUILD loading location *************************/


        /************************* BUILD destination location *************************/
        $deliveryIndexes = array_keys($lines, 'Delivery');
        $postcodePattern = '/\b(\d{4,5}|FR-?\d{4,6})\b/i';

        for ($i = 0; $i < count($deliveryIndexes); $i++) {
            $start = $deliveryIndexes[$i];
            $end = isset($deliveryIndexes[$i + 1]) ? $deliveryIndexes[$i + 1] : count($lines);
            $subset = array_slice($lines, $start + 1, $end - $start - 1);

            $company = '';
            $street = '';
            $postal_code = '';
            $city = '';
            $foundDate = null;

            foreach ($subset as $line) {
                $line = trim($line);

                // ✅ Find first valid date (with slash or dash)
                if (!$foundDate && preg_match('/\b(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})\b/', $line, $m)) {
                    $foundDate = $m[1];
                }

                // ✅ Postal + city (95150 TAVERNY)
                if (!$postal_code && preg_match('/^(\d{4,5})\s+([A-Z\s\-]+)$/i', $line, $m)) {
                    $postal_code = trim($m[1]);
                    $city = ucwords(strtolower(trim($m[2])));
                }

                // ✅ Postal + city (ENNERY, FR-57365)
                if (!$postal_code && preg_match('/^(.+?),\s*(FR-?\d{4,6})$/i', $line, $m)) {
                    $city = ucwords(strtolower(trim($m[1])));
                    $postal_code = strtoupper(trim($m[2]));
                }

                // ✅ Street line (contains RUE / CHEM / ROAD / AVENUE)
                if (!$street && preg_match('/\b(RUE|CHEM|ROAD|AVENUE|STREET)\b/i', $line)) {
                    $street = $line;
                }

                // ✅ Company name (first uppercase text)
                if (!$company && preg_match('/[A-Z]/', $line) && !preg_match('/\d/', $line)) {
                    $company = $line;
                }
            }

            // ✅ Guarantee a date — if still missing, fallback search globally in subset
            if (!$foundDate) {
                foreach ($subset as $line) {
                    if (preg_match('/\d{1,2}\/\d{1,2}\/\d{4}/', $line, $m)) {
                        $foundDate = $m[0];
                        break;
                    }
                }
            }

            // ✅ Final conversion (never null if there’s any date)
            $isoDate = $foundDate ? $this->parseDateToIso($foundDate) : Carbon::now()->toIsoString();

            $destination_locations[] = [
                'company_address' => [
                    'company' => $company,
                    'street_address' => $street,
                    'postal_code' => $postal_code,
                    'city' => $city,
                    'country' => GeonamesCountry::getIso('FR'),
                    'contact_person' => '',
                    'email' => '',
                    'vat_code' => '',
                ],
                'time' => [
                    'datetime_from' => $isoDate,
                ],
            ];
        }
        /************************* BUILD destination location *************************/


        /************************* CARGO *************************/
        $cargos = [];
        $currentSection = null;

        foreach ($lines as $i => $line) {
            $line = trim($line);

            // Detect start of a cargo section
            if (preg_match('/^(Collection|Delivery|Clearance)$/i', $line)) {
                $currentSection = strtolower($line);
                $cargo = [
                    'title' => '',
                    'package_count' => 0,
                    'package_type' => 'pallet',
                    'number' => '',
                    'type' => '', // will be detected later
                    'value' => 0,
                    'currency' => 'EUR',
                    'pkg_width' => 0,
                    'pkg_length' => 0,
                    'pkg_height' => 0,
                    'ldm' => 0,
                    'volume' => 0,
                    'weight' => 0,
                    'chargeable_weight' => 0,
                    'temperature_min' => 0,
                    'temperature_max' => 0,
                    'temperature_mode' => 'auto (start / stop)',
                    'adr' => false,
                    'extra_lift' => false,
                    'palletized' => true,
                    'manual_load' => false,
                    'vehicle_make' => '',
                    'vehicle_model' => '',
                ];

                // Extract next few lines for info
                $sectionLines = [];
                for ($j = $i + 1; $j < count($lines) && $j <= $i + 8; $j++) {
                    $nextLine = trim($lines[$j]);

                    // Stop if next section starts
                    if (preg_match('/^(Collection|Delivery|Clearance)$/i', $nextLine)) break;

                    $sectionLines[] = $nextLine;

                    // Pallet count
                    if (preg_match('/(\d+)\s*pallet/i', $nextLine, $m)) {
                        $cargo['package_count'] = (int) $m[1];
                    }

                    // Reference
                    if (preg_match('/REF\s*(.*)/i', $nextLine, $m)) {
                        $cargo['number'] = trim($m[1]);
                    }

                    // Title
                    if ($cargo['title'] === '' && !preg_match('/^(REF|BOOKED|Time|Date|Pallet|To|From)/i', $nextLine)) {
                        $cargo['title'] = $nextLine;
                    }
                }

                // 🔍 Detect cargo type based on section lines
                $cargo['type'] = $this->detectCargoType($sectionLines, $this->cargoTypes);

                $cargos[] = $cargo;
            }
        }
        /************************* CARGO *************************/


        $key = array_search(true, array_map(fn($v) => str_contains($v, 'Ziegler Ref'), $lines));
        $order_reference = $lines[$key + 1];

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

    function parseDateToIso(?string $dateStr)
    {
        if (!$dateStr) return null;
        $dateStr = trim($dateStr);

        // Try common formats: 'd/m/Y' (05/06/2025) and 'j/n/Y' (5/6/2025)
        $formats = ['d/m/Y', 'j/n/Y'];

        foreach ($formats as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $dateStr);
                if ($dt) return $dt->toIsoString();
            } catch (\Exception $e) {
                // try next
            }
        }

        // Fallback to parse (lets Carbon handle ambiguous cases)
        try {
            $dt = Carbon::parse($dateStr);
            return $dt->toIsoString();
        } catch (\Exception $e) {
            return null;
        }
    }

    function niceCity($text)
    {
        if (!$text) return $text;
        // Lower then ucwords
        $t = strtolower($text);
        $t = ucwords($t);
        // Keep fully uppercase words like "GXO" or "EPAC"
        $t = preg_replace_callback('/\b([A-Z]{2,})\b/', function ($m) {
            return strtoupper($m[1]);
        }, $t);
        return $t;
    }

    function detectCargoType(array $lines, array $cargoTypes): string
    {
        $joined = strtolower(implode(' ', $lines));

        $mapping = [
            '/full\s*load|ftl|fcl/' => 'full',
            '/partial|ltl|ptl/'     => 'partial',
            '/lcl|groupage/'        => 'LCL',
            '/parcel|courier|box/'  => 'parcel',
            '/air|airway|flight/'   => 'air shipment',
            '/container|cont\./'    => 'container',
            '/car|vehicle|auto/'    => 'car',
        ];

        // First, try mapping patterns
        foreach ($mapping as $pattern => $type) {
            if (preg_match($pattern, $joined)) {
                return $type;
            }
        }

        // Next, try direct match with enum keywords
        foreach ($cargoTypes as $type) {
            if (stripos($joined, $type) !== false) {
                return $type;
            }
        }

        // Default fallback
        return 'partial';
    }
}
