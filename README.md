# ges/ocr

Laravel package for document OCR, classification, extraction, and normalization.

This package is built for French business and identity documents, with current support for:
- `identity_card`
- `residence_permit`
- `passport`
- `visa`
- `crew_card`
- `travel_document`
- `other_identity_document`
- `kbis`
- `acte_propriete` (land-title deed only)

## What This Package Does

Input pipeline:
- detect technical input type: `image`, `pdf_text`, `pdf_scan`
- transcribe images and scanned PDFs
- classify the business document type
- extract structured data
- normalize values into a stable shape
- return a `ProcessedDocumentResult`

Current model strategy:
- `qwen2.5vl:7b` for visual transcription only
- `qwen2.5:7b` for classification and structured extraction

## Package Boundaries

This package contains:
- OCR/transcription services
- classifier
- extractor
- normalizer
- schema factory
- Ollama client
- package `DocumentProcessing` model
- package migration and factory
- install command

This package does not own your application workflow.

Typical app-specific code stays outside:
- accepted `Document` model
- upload flow
- matching an identity document against a user
- deciding whether to persist a final document
- queue jobs tied to your app domain

## Install

```bash
composer require ges/ocr
```

Then install package assets:

```bash
php artisan ocr:install
```

Or install and migrate immediately:

```bash
php artisan ocr:install --migrate
```

Optional install flags:

```bash
php artisan ocr:install --check
php artisan ocr:install --no-config
php artisan ocr:install --no-migrations
php artisan ocr:install --force
```

What this command does:
- publishes `config/ges-ocr.php`
- publishes package migrations
- optionally runs `php artisan migrate`
- optionally runs `php artisan ocr:health`

Health check command:

```bash
php artisan ocr:health
```

It checks:
- `pdftotext`
- `pdftoppm`
- Ollama connectivity
- configured Ollama models

## Configuration

Published config file:

```php
config/ges-ocr.php
```

Main environment variables:

```env
OLLAMA_BASE_URL=http://host.docker.internal:11434
OLLAMA_TEXT_MODEL=qwen2.5:7b
OLLAMA_VISION_MODEL=qwen2.5vl:7b
OLLAMA_CONNECT_TIMEOUT=10
OLLAMA_TIMEOUT=120
OLLAMA_RETRY_TIMES=2
OLLAMA_RETRY_SLEEP_MS=500
OLLAMA_CLASSIFICATION_CONFIDENCE_THRESHOLD=0.75
OLLAMA_MAX_PAGES=0
OLLAMA_BASIC_AUTH_ENABLED=false
OLLAMA_BASIC_AUTH_USERNAME=
OLLAMA_BASIC_AUTH_PASSWORD=
GES_OCR_MRZ_OCR_ENABLED=true
GES_OCR_CLEANUP_TEMPORARY_FILES=true
```

`OLLAMA_MAX_PAGES=0` means unlimited pages.

Main config areas:
- `ollama`
- `mrz`
- `processing`

Optional Ollama upstream basic auth:
- `OLLAMA_BASIC_AUTH_ENABLED=true` enables HTTP basic auth on requests sent to `OLLAMA_BASE_URL`
- `OLLAMA_BASIC_AUTH_USERNAME` sets the upstream username
- `OLLAMA_BASIC_AUTH_PASSWORD` sets the upstream password

## Public API

Main service:

```php
use Ges\Ocr\DocumentProcessor;

$result = app(DocumentProcessor::class)->processFile(
    path: $absolutePath,
    mimeType: $mimeType,
    originalName: $originalName,
);
```

Returned DTO:
- `originalName`
- `mimeType`
- `path`
- `inputType`
- `documentType`
- `status`
- `pagesCount`
- `rawClassificationJson`
- `rawExtractionJson`
- `normalizedJson`
- `errorMessage`

Main statuses:
- `pending`
- `processing`
- `done`
- `failed`
- `needs_review`

## Supported Output Shapes

### Identity Card

Normalized keys:
- `document_type`
- `civility`
- `first_name`
- `last_name`
- `date_of_birth`
- `place_of_birth`
- `document_number`
- `expiry_date`
- `nationality`
- `sex`
- `street_address`
- `postal_code`
- `city`

### Residence Permit

Normalized keys:
- `document_type`
- `civility`
- `first_name`
- `last_name`
- `date_of_birth`
- `place_of_birth`
- `document_number`
- `expiry_date`
- `nationality`
- `sex`
- `street_address`
- `postal_code`
- `city`

### KBIS

Normalized keys:
- `document_type`
- `company_name`
- `trade_name`
- `legal_form`
- `capital`
- `registration_number`
- `siret`
- `sirene`
- `street_address`
- `postal_code`
- `city`
- `naf_code`
- `registration_date`
- `registry_city`
- `legal_representatives`

Representative shape:
- `entity_type`
- `company_name`
- `legal_form`
- `civility`
- `first_name`
- `last_name`
- `street_address`
- `postal_code`
- `city`
- `registration_number`
- `registry_city`
- `role`

### Acte Propriete

Important: this currently means French land-title deed only.

Normalized keys:
- `document_type`
- `cadastral_parcels`
- `owners`

Parcel shape:
- `prefixe`
- `section`
- `numero`
- `street_address`
- `postal_code`
- `city`

Owner shape:
- `entity_type`
- `company_name`
- `civility`
- `first_name`
- `last_name`

Rules:
- owners are acquirers only
- sellers must not be returned as owners
- municipalities and administrations are treated as `company`
- `lieudit` / `leudit` may be used as parcel `street_address`

## Package Model

The package provides:

```php
Ges\Ocr\Models\DocumentProcessing
```

This model stores:
- source file metadata
- detected input type
- business document type
- status
- raw classification JSON
- raw extraction JSON
- normalized JSON
- error message

If your app wants its own subclass, it can extend the package model.

## AI Notes

If you are an AI agent working in a project using this package:

- Use `DocumentProcessor::processFile(...)` as the main entry point.
- Treat `rawClassificationJson` as model output, not final truth.
- Treat `normalizedJson` as the stable application-facing payload.
- For images and scanned PDFs, the package uses two LLM stages:
  - vision transcription
  - text classification/extraction
- Do not assume `acte_propriete` means generic property deed. In this package it currently means land-title deed only.
- Distinguish `identity_card` from `residence_permit`.
- Use `residence_permit` for French residence permits and `identity_card` for French identity cards.
- For KBIS:
  - `registration_number` is the raw `Immatriculation RCS`
  - `sirene` is 9 digits
  - `siret` is optional and only if explicitly present

## Tests

Package tests live under:

```bash
tests/Unit
```

Manual OCR fixture tests exist for:
- CIN
- titre de séjour
- KBIS
- land-title deeds

They are gated by:

```bash
RUN_MANUAL_OCR_TESTS=1
```

## Current Assumptions

- documents are French documents
- Ollama is reachable from the Laravel app
- `pdftotext` and `pdftoppm` are available for PDF handling

## Non-Goals

This package does not currently provide:
- user/document matching workflow
- approval workflow
- final accepted document persistence
- domain-specific queue orchestration
- UI components

Those belong in the consuming application.
