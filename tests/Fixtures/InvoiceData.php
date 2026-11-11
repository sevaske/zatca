<?php

namespace Sevaske\Zatca\Tests\Fixtures;

use Illuminate\Support\Str;

class InvoiceData
{
    public const FIRST_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    /**
     * A valid simplified (or standard) tax invoice: 2 x 100.00 + 15% VAT.
     *
     * @return array<string, mixed>
     */
    public static function make(string $kind = 'simplified', int $icv = 1, string $pih = self::FIRST_PIH): array
    {
        $now = date('Y-m-d H:i:s');

        $data = [
            'uuid' => (string) Str::uuid(),
            'id' => 'INV-'.$icv.'-'.time(),
            'issueDate' => $now,
            'issueTime' => $now,
            'currencyCode' => 'SAR',
            'taxCurrencyCode' => 'SAR',
            'invoiceType' => ['invoice' => $kind, 'type' => 'invoice'],
            'additionalDocuments' => [
                ['id' => 'ICV', 'uuid' => (string) $icv],
                ['id' => 'PIH', 'attachment' => ['content' => $pih]],
            ],
            'supplier' => [
                'registrationName' => 'Maximum Speed Tech Supply LTD',
                'taxId' => '399999999900003',
                'identificationId' => '1010010000',
                'identificationType' => 'CRN',
                'address' => [
                    'street' => 'King Fahd Road',
                    'buildingNumber' => '1234',
                    'subdivision' => 'Al Olaya',
                    'city' => 'Riyadh',
                    'postalZone' => '12345',
                    'country' => 'SA',
                ],
            ],
            'paymentMeans' => ['code' => '10'],
            'taxTotal' => [
                'taxAmount' => 30.00,
                'subTotals' => [[
                    'taxableAmount' => 200.00,
                    'taxAmount' => 30.00,
                    'taxCategory' => ['percent' => 15, 'taxScheme' => ['id' => 'VAT']],
                ]],
            ],
            'legalMonetaryTotal' => [
                'lineExtensionAmount' => 200.00,
                'taxExclusiveAmount' => 200.00,
                'taxInclusiveAmount' => 230.00,
                'allowanceTotalAmount' => 0.00,
                'prepaidAmount' => 0.00,
                'payableAmount' => 230.00,
            ],
            'invoiceLines' => [[
                'id' => 1,
                'unitCode' => 'PCE',
                'quantity' => 2,
                'lineExtensionAmount' => 200.00,
                'item' => [
                    'name' => 'Office chair',
                    'classifiedTaxCategory' => [['percent' => 15, 'taxScheme' => ['id' => 'VAT']]],
                ],
                'price' => ['amount' => 100.00, 'unitCode' => 'PCE'],
                'taxTotal' => ['taxAmount' => 30.00, 'roundingAmount' => 230.00],
            ]],
        ];

        if ($kind === 'standard') {
            $data['delivery'] = ['actualDeliveryDate' => $now];
            $data['customer'] = [
                'registrationName' => 'Buyer Company LLC',
                'taxId' => '399999999800003',
                'address' => [
                    'street' => 'Al Urubah Road',
                    'buildingNumber' => '7176',
                    'subdivision' => 'Al Olaya',
                    'city' => 'Riyadh',
                    'postalZone' => '12251',
                    'country' => 'SA',
                ],
            ];
        }

        return $data;
    }
}
