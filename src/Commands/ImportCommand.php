<?php

namespace Sevaske\Zatca\Commands;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Sevaske\Zatca\Invoice\SigningCredentials;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;

/**
 * Adopt a unit onboarded elsewhere (for example with 1.x): its private key and production CSID from files, and
 * the position of its invoice chain.
 */
class ImportCommand extends ZatcaCommand
{
    protected $signature = 'zatca:import
        {unit? : key of the unit (default: config zatca.unit)}
        {--env= : sandbox, simulation or production (default: config zatca.env)}
        {--name= : a label for the unit}
        {--vat= : seller VAT number, as in the CSR}
        {--invoice-types= : 1000 standard, 0100 simplified, 1100 both}
        {--disk=local : filesystem disk of the files}
        {--private-key=zatca/private_key.pem : path of the private key}
        {--credentials=zatca/production_credentials.json : path of the production CSID (JSON)}
        {--csr= : path of the CSR (optional)}
        {--icv=0 : counter value (ICV) of the last document the unit generated, accepted or rejected}
        {--hash= : hash of that document (its PIH for the next one)}
        {--force : replace the credentials of an existing unit}';

    protected $description = 'Import an EGS unit onboarded elsewhere: private key, production CSID and the position of its invoice chain.';

    public function handle(): int
    {
        $unit = $this->findOrNewUnit();

        if ($unit->exists && ! $this->option('force')) {
            $this->components->error("EGS unit \"{$unit->key}\" ({$unit->environment}) exists already. Use --force to replace its credentials.");

            return self::FAILURE;
        }

        $disk = Storage::disk((string) $this->option('disk'));

        foreach (['private-key', 'credentials'] as $file) {
            if (! $disk->exists((string) $this->option($file))) {
                $this->components->error("File not found: {$this->option($file)}");

                return self::FAILURE;
            }
        }

        $privateKey = (string) $disk->get((string) $this->option('private-key'));
        $credentials = json_decode((string) $disk->get((string) $this->option('credentials')), true);

        try {
            $signing = new SigningCredentials(ProductionCsid::fromArray(self::csidData($credentials)), $privateKey);
        } catch (InvalidArgumentException $e) {
            $this->components->error('Invalid credentials or private key: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $signing->keyMatchesCertificate()) {
            $this->components->error('The private key does not belong to the certificate of the production CSID.');

            return self::FAILURE;
        }

        $unit->fill([
            'name' => $this->option('name') ?: $unit->name,
            'vat_number' => $this->askVatNumber($unit->vat_number),
            'invoice_types' => $this->askInvoiceTypes($unit->exists ? $unit->invoice_types : null),
            'private_key' => $privateKey,
            'production_csid' => $signing->csid->toArray(),
            'csr' => $this->option('csr') ? $disk->get((string) $this->option('csr')) : $unit->csr,
        ]);

        $icv = (int) $this->option('icv');
        $hash = $this->option('hash') ?: null;

        if (($icv > 0) !== ($hash !== null) || ($hash !== null && strlen((string) base64_decode($hash, true)) !== 32)) {
            $this->components->error('Give --icv and --hash of the last generated document together; the hash is a base64 SHA-256 digest.');

            return self::FAILURE;
        }

        // a unit that issued documents here keeps its chain; an existing one keeps its position unless a new one is given
        if ($hash !== null || ! $unit->exists) {
            if ($unit->documents()->exists()) {
                $this->components->error("EGS unit \"{$unit->key}\" has documents already: its chain position cannot be changed.");

                return self::FAILURE;
            }

            $unit->forceFill(['last_icv' => $icv, 'last_hash' => $hash]);
        }

        $unit->save();

        $this->components->info(sprintf(
            'EGS unit "%s" (%s) imported. Its next document takes ICV %d and PIH %s.',
            $unit->key,
            $unit->environment,
            $unit->last_icv + 1,
            $unit->previousHash(),
        ));

        return self::SUCCESS;
    }

    /**
     * Credentials JSON as written by 2.x (`binarySecurityToken`) or 1.x (`certificate`: the decoded token).
     *
     * @return array<array-key, mixed>
     */
    private static function csidData(mixed $data): array
    {
        $data = is_array($data) ? $data : [];

        if (! isset($data['binarySecurityToken']) && isset($data['certificate'])) {
            $data['binarySecurityToken'] = base64_encode((string) $data['certificate']);
        }

        return $data;
    }
}
