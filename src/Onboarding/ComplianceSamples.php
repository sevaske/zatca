<?php

namespace Sevaske\Zatca\Onboarding;

use Illuminate\Support\Str;
use Sevaske\Zatca\Enums\DocumentType;
use Sevaske\Zatca\Enums\Profile;
use Sevaske\Zatca\Models\EgsUnit;

/**
 * Sample documents for the compliance checks: an invoice, a credit note and a debit note for every profile the
 * unit declared (1000 standard, 0100 simplified, 1100 both). One line, 100.00 + 15% VAT.
 */
final class ComplianceSamples
{
    /**
     * @return array<string, array<string, mixed>> invoice data by label, e.g. "simplified credit"
     */
    public static function for(EgsUnit $unit): array
    {
        $samples = [];

        foreach (Profile::cases() as $profile) {
            if (! $unit->allows($profile)) {
                continue;
            }

            foreach (DocumentType::cases() as $type) {
                $samples["{$profile->value} {$type->value}"] = self::make($unit, $profile, $type);
            }
        }

        return $samples;
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(EgsUnit $unit, Profile $profile, DocumentType $type): array
    {
        $now = now('Asia/Riyadh')->format('Y-m-d H:i:s');
        $address = [
            'street' => 'King Fahd Road',
            'buildingNumber' => '1234',
            'subdivision' => 'Al Olaya',
            'city' => 'Riyadh',
            'postalZone' => '12345',
            'country' => 'SA',
        ];

        $data = [
            'uuid' => (string) Str::uuid(),
            'id' => 'SAMPLE-'.strtoupper(substr($profile->value, 0, 2).'-'.$type->value),
            'issueDate' => $now,
            'issueTime' => $now,
            'currencyCode' => 'SAR',
            'taxCurrencyCode' => 'SAR',
            'invoiceType' => ['invoice' => $profile->value, 'type' => $type->value],
            'supplier' => [
                'registrationName' => $unit->csr_options['organization_name'] ?? $unit->name ?? 'Seller',
                'taxId' => $unit->vat_number,
                'identificationId' => '1010010000',
                'identificationType' => 'CRN',
                'address' => $address,
            ],
            'paymentMeans' => ['code' => '10'],
            'taxTotal' => [
                'taxAmount' => 15.00,
                'subTotals' => [[
                    'taxableAmount' => 100.00,
                    'taxAmount' => 15.00,
                    'taxCategory' => ['percent' => 15, 'taxScheme' => ['id' => 'VAT']],
                ]],
            ],
            'legalMonetaryTotal' => [
                'lineExtensionAmount' => 100.00,
                'taxExclusiveAmount' => 100.00,
                'taxInclusiveAmount' => 115.00,
                'allowanceTotalAmount' => 0.00,
                'prepaidAmount' => 0.00,
                'payableAmount' => 115.00,
            ],
            'invoiceLines' => [[
                'id' => 1,
                'unitCode' => 'PCE',
                'quantity' => 1,
                'lineExtensionAmount' => 100.00,
                'item' => [
                    'name' => 'Sample item',
                    'classifiedTaxCategory' => [['percent' => 15, 'taxScheme' => ['id' => 'VAT']]],
                ],
                'price' => ['amount' => 100.00, 'unitCode' => 'PCE'],
                'taxTotal' => ['taxAmount' => 15.00, 'roundingAmount' => 115.00],
            ]],
        ];

        if ($profile === Profile::Standard) {
            $data['delivery'] = ['actualDeliveryDate' => $now];
            $data['customer'] = [
                'registrationName' => 'Sample Buyer LLC',
                'taxId' => '399999999800003',
                'address' => ['buildingNumber' => '7176', 'postalZone' => '12251', 'street' => 'Al Urubah Road'] + $address,
            ];
        }

        if ($type !== DocumentType::Invoice) {
            // a note refers to the invoice it corrects and gives the reason
            $data['billingReferences'] = [['id' => 'SAMPLE-'.strtoupper(substr($profile->value, 0, 2)).'-INVOICE']];
            $data['paymentMeans']['note'] = 'Correction of the sample invoice';
        }

        return $data;
    }
}
