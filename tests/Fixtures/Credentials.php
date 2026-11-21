<?php

namespace Sevaske\Zatca\Tests\Fixtures;

use Saleh7\Zatca\CertificateBuilder;
use Sevaske\Zatca\Invoice\SigningCredentials;
use Sevaske\ZatcaApi\Credentials\ComplianceCsid;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;

/**
 * A private key and a matching self-signed certificate, shaped like a ZATCA CSID. Signing works offline with it.
 */
final class Credentials
{
    /** @var array<string, array{key: string, token: string}> */
    private static array $cache = [];

    public static function production(string $name = 'a'): SigningCredentials
    {
        $pair = self::pair($name);

        return new SigningCredentials(new ProductionCsid($pair['token'], "secret-{$name}"), $pair['key']);
    }

    public static function compliance(string $name = 'a'): SigningCredentials
    {
        $pair = self::pair($name);

        return new SigningCredentials(new ComplianceCsid($pair['token'], "secret-{$name}", "request-{$name}"), $pair['key']);
    }

    /**
     * The JSON body of a CSID response from ZATCA.
     *
     * @return array<string, string>
     */
    public static function response(string $name = 'a'): array
    {
        return [
            'requestID' => "request-{$name}",
            'dispositionMessage' => 'ISSUED',
            'binarySecurityToken' => self::pair($name)['token'],
            'secret' => "secret-{$name}",
        ];
    }

    /**
     * @return array{key: string, token: string}
     */
    private static function pair(string $name): array
    {
        if (! isset(self::$cache[$name])) {
            $key = (new CertificateBuilder)
                ->setOrganizationIdentifier('399999999900003')
                ->setSerialNumber('Tests', '1', $name)
                ->setCommonName("Test unit {$name}")
                ->setCountryName('SA')
                ->setOrganizationName('Maximum Speed Tech Supply LTD')
                ->setOrganizationalUnitName('Riyadh Branch')
                ->setAddress('Riyadh')
                ->setInvoiceType(1100)
                ->setBusinessCategory('Supply activities')
                ->generate()
                ->getPrivateKey();

            // an explicit OpenSSL config: Windows runners may have none
            $config = tempnam(sys_get_temp_dir(), 'zatca');
            file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n");
            $options = ['digest_alg' => 'sha256', 'config' => $config];

            $privateKey = openssl_pkey_get_private($key);
            $csr = openssl_csr_new(['commonName' => "Test unit {$name}"], $privateKey, $options);
            openssl_x509_export(openssl_csr_sign($csr, null, $privateKey, 365, $options), $pem);
            unlink($config);

            // ZATCA's binarySecurityToken is the base64 of the base64-encoded DER certificate
            $der = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);

            self::$cache[$name] = ['key' => $key, 'token' => base64_encode($der)];
        }

        return self::$cache[$name];
    }
}
