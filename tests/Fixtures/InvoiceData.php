<?php

namespace Sevaske\Zatca\Tests\Fixtures;

use Illuminate\Support\Str;
use Sevaske\Zatca\Invoice\InvoiceChain;

class InvoiceData
{
    /**
     * A valid simplified (or standard) invoice, credit or debit note: 2 x 100.00 + 15% VAT.
     *
     * Without $icv the data has no ICV and PIH: an EGS unit fills them in.
     *
     * @return array<string, mixed>
     */
    public static function make(string $kind = 'simplified', string $type = 'invoice', ?int $icv = null, string $pih = InvoiceChain::INITIAL_HASH): array
    {
        $now = date('Y-m-d H:i:s');

        $data = [
            'uuid' => (string) Str::uuid(),
            'id' => 'INV-'.Str::random(8),
            'issueDate' => $now,
            'issueTime' => $now,
            'currencyCode' => 'SAR',
            'taxCurrencyCode' => 'SAR',
            'invoiceType' => ['invoice' => $kind, 'type' => $type],
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

        if ($icv !== null) {
            $data['additionalDocuments'] = [
                ['id' => 'ICV', 'uuid' => (string) $icv],
                ['id' => 'PIH', 'attachment' => ['content' => $pih]],
            ];
        }

        if ($type !== 'invoice') {
            $data['billingReferences'] = [['id' => 'INV-ORIGINAL']];
            $data['paymentMeans']['note'] = 'Returned goods';
        }

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
