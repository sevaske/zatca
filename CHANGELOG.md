# Changelog

All notable changes to `zatca` will be documented in this file.

## 2.0.0 - Unreleased

A rewrite on top of zatca-api v3 and php-zatca-xml v4, centred on EGS units stored in the database.

### Added

- EGS units in the database (`zatca_egs_units`): private key and CSIDs encrypted with the application key, the
  position of the invoice chain, any number of units, sellers and environments. `Zatca::unit($key)`.
- `EgsUnit::issue($data, $owner)`: fills in ICV, PIH and UUID, signs and stores the document in one transaction
  with the unit's row locked, safe across queue workers and servers. Refuses data of another seller or a profile the
  unit did not declare, before the chain moves.
- Documents in the database (`zatca_documents`): the signed XML byte for byte, cleared XML, QR code, status,
  errors, warnings, attempts, polymorphic owner. The signed part cannot be changed.
- `Document::submit()`: clears or reports, stores the outcome, idempotent. A standard document is reported with
  `Clearance-Status: 0` when ZATCA answers 303.
- `zatca:onboard` (key, CSR, compliance CSID, generated compliance samples, production CSID; resumable),
  `zatca:renew`, `zatca:import` (adopt a unit onboarded elsewhere and continue its chain), `zatca:units`.
- `SigningCredentials`: sign with any unit's CSID and private key. `InvoiceChain::INITIAL_HASH`.
- Events `DocumentIssued` and `DocumentSubmitted` (ZATCA's decision), dispatched after the transaction commits.
- `HasZatcaDocuments` trait for the application's models. Configurable tables, connection and models.

### Changed

- `Zatca::invoices()` takes the environment and needs explicit credentials: `sign($data, SigningCredentials)`,
  `report|clear|submit($document, ProductionCsid)`, `check($document, ComplianceCsid)`.
- `Zatca::api()` is `Zatca::client($environment)` (`Sevaske\ZatcaApi\Client`). Rejections and "already reported"
  are results, not exceptions.
- `SignedDocument` exposes `profile` and `type` instead of `standard`.

### Removed

- File storage of the CSR, private key and CSIDs (`Zatca::files()`, `storage.*` config keys). Import existing files
  with `zatca:import`.
- `zatca:generate-csr`, `zatca:compliance-certificate`, `zatca:production-certificate` (replaced by `zatca:onboard`),
  `Http::zatca()`, the translations of the command prompts.

### Fixed

- Commands no longer call `Command::fail()`, which does not exist in Laravel 10.

## v1.1.0 - 2025-08-18

**Full Changelog**: https://github.com/sevaske/zatca/compare/v1.0.2...v1.1.0

## v1.0.2 - 2025-08-16

### What's Changed

* Bump actions/checkout from 4 to 5 by @dependabot[bot] in https://github.com/sevaske/zatca/pull/3

**Full Changelog**: https://github.com/sevaske/zatca/compare/v1.0.1...v1.0.2

## v.1.0.1 - 2025-08-11

**Full Changelog**: https://github.com/sevaske/zatca/compare/v1.0.0...v.1.0.1
