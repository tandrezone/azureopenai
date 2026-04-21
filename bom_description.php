#!/usr/bin/env php
<?php

/**
 * BOM Description Generator
 *
 * This script reads an XML, XLSX, or JSON file containing a Bill of Materials (BOM),
 * extracts the product and component information, then calls Azure OpenAI
 * to generate a product description based on the BOM.
 *
 * Usage:
 *   php bom_description.php <path-to-bom-file>
 *
 * Examples:
 *   php bom_description.php sample_bom.xml
 *   php bom_description.php sample_bom.xlsx
 *   php bom_description.php sample_bom.json
 */

// ── Autoloader ───────────────────────────────────────────────────────────────

$autoloadFile = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloadFile)) {
    require $autoloadFile;
}

// ── Configuration ────────────────────────────────────────────────────────────

$configFile = __DIR__ . '/config.php';

if (!file_exists($configFile)) {
    fwrite(STDERR, "Error: config.php not found.\n");
    fwrite(STDERR, "Please ensure config.php exists in the same directory and contains your Azure OpenAI credentials,\n");
    fwrite(STDERR, "or set them as environment variables (see .env.example).\n");
    exit(1);
}

$config = require $configFile;

// ── Input validation ─────────────────────────────────────────────────────────

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bom_description.php <path-to-bom-file>\n");
    fwrite(STDERR, "Example: php bom_description.php sample_bom.xml\n");
    fwrite(STDERR, "         php bom_description.php sample_bom.xlsx\n");
    exit(1);
}

$bomFile = $argv[1];

if (!file_exists($bomFile)) {
    fwrite(STDERR, "Error: File not found: {$bomFile}\n");
    exit(1);
}

// ── Parse the BOM file ───────────────────────────────────────────────────────

/**
 * Detect file type and parse BOM data accordingly.
 *
 * @param  string $filePath Path to the BOM file (XML or XLSX).
 * @return array{product: array<string,string>, components: list<array<string,string>>}
 */
function parseBomFile(string $filePath): array
{
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    return match ($extension) {
        'xml', 'xslt', 'xsl' => parseBomXml($filePath),
        'xlsx' => parseBomXlsx($filePath),
        default => throw new RuntimeException(
            "Unsupported file format: .{$extension}. Supported formats: .xml, .xsl, .xslt, .xlsx"
        ),
    };
}

/**
 * Parse a BOM XML file and return structured data.
 *
 * Supports both plain XML BOM files and XSLT files that embed BOM data.
 *
 * @param  string $filePath Path to the XML/XSLT file.
 * @return array{product: array<string,string>, components: list<array<string,string>>}
 */
function parseBomXml(string $filePath): array
{
    libxml_use_internal_errors(true);

    $xml = simplexml_load_file($filePath);

    if ($xml === false) {
        $errors = libxml_get_errors();
        $messages = array_map(fn($e) => trim($e->message), $errors);
        throw new RuntimeException(
            "Failed to parse XML file: " . implode('; ', $messages)
        );
    }

    // Detect XSLT documents and look for an embedded BOM element.
    // An XSLT file typically has the root element xsl:stylesheet or xsl:transform.
    $rootName = $xml->getName();
    if (in_array($rootName, ['stylesheet', 'transform'], true)) {
        // Search for a BillOfMaterials element anywhere inside the XSLT.
        $bomNodes = $xml->xpath('//*[local-name()="BillOfMaterials"]');
        if (!$bomNodes) {
            throw new RuntimeException(
                "The XSLT file does not contain an embedded <BillOfMaterials> element."
            );
        }
        $xml = $bomNodes[0];
    }

    // Extract product information.
    $product = [];
    if (isset($xml->Product)) {
        foreach ($xml->Product->children() as $key => $value) {
            $product[$key] = (string)$value;
        }
    }

    if (empty($product)) {
        throw new RuntimeException(
            "No <Product> element found inside <BillOfMaterials>."
        );
    }

    // Extract components.
    $components = [];
    if (isset($xml->Components->Component)) {
        foreach ($xml->Components->Component as $component) {
            $comp = [];
            foreach ($component->children() as $key => $value) {
                $comp[$key] = (string)$value;
            }
            $components[] = $comp;
        }
    }

    return ['product' => $product, 'components' => $components];
}

/**
 * Parse a BOM XLSX file and return structured data.
 *
 * The spreadsheet should ideally contain a "Product" sheet with product details
 * and a "Components" sheet listing each component.  If those named sheets are
 * not found the parser falls back to positional sheets: the first sheet is used
 * for product data and the second sheet (if present) for components.
 *
 * Both sheets should have a header row whose values are used as field names
 * (e.g. Name, PartNumber, Quantity, Unit, Revision).
 *
 * @param  string $filePath Path to the XLSX file.
 * @return array{product: array<string,string>, components: list<array<string,string>>}
 */
function parseBomXlsx(string $filePath): array
{
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        throw new RuntimeException(
            "PhpSpreadsheet is required to read XLSX files.\n"
            . "Install it with: composer require phpoffice/phpspreadsheet"
        );
    }

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);

    if ($spreadsheet->getSheetCount() === 0) {
        throw new RuntimeException(
            'The XLSX file does not contain any sheets.'
        );
    }

    // ── Product sheet ────────────────────────────────────────────────────
    // Try the named sheet first, then fall back to the first sheet.
    $productSheet = $spreadsheet->getSheetByName('Product')
                 ?? $spreadsheet->getSheet(0);

    $productData = $productSheet->toArray(null, true, true, true);

    if (count($productData) < 2) {
        throw new RuntimeException(
            'The product sheet must have a header row and at least one data row.'
        );
    }

    $headers = array_map('trim', array_map('strval', array_values($productData[1])));
    $values  = array_map('trim', array_map('strval', array_values($productData[2])));
    $product = [];
    foreach ($headers as $i => $header) {
        if ($header !== '' && isset($values[$i]) && $values[$i] !== '') {
            $product[$header] = $values[$i];
        }
    }

    if (empty($product)) {
        throw new RuntimeException(
            'No product data found in the product sheet.'
        );
    }

    // ── Components sheet ─────────────────────────────────────────────────
    // Try the named sheet first, then fall back to the second sheet (if any).
    $compSheet = $spreadsheet->getSheetByName('Components');
    if ($compSheet === null && $spreadsheet->getSheetCount() > 1) {
        $compSheet = $spreadsheet->getSheet(1);
    }

    // Components are optional – some BOM files only have a product sheet.
    if ($compSheet === null) {
        return ['product' => $product, 'components' => []];
    }

    $compData = $compSheet->toArray(null, true, true, true);

    if (count($compData) < 2) {
        // The components sheet exists but has no usable rows – treat as empty.
        return ['product' => $product, 'components' => []];
    }

    $compHeaders = array_map('trim', array_map('strval', array_values($compData[1])));
    $components  = [];

    for ($row = 2, $rowCount = count($compData); $row <= $rowCount; $row++) {
        if (!isset($compData[$row])) {
            continue;
        }
        $rowValues = array_map('trim', array_map('strval', array_values($compData[$row])));
        // Skip entirely empty rows.
        if (implode('', $rowValues) === '') {
            continue;
        }
        $comp = [];
        foreach ($compHeaders as $i => $header) {
            if ($header !== '' && isset($rowValues[$i])) {
                $comp[$header] = $rowValues[$i];
            }
        }
        $components[] = $comp;
    }

    return ['product' => $product, 'components' => $components];
}

/**
 * Build the text prompt sent to Azure OpenAI.
 *
 * @param  array{product: array<string,string>, components: list<array<string,string>>} $bom
 * @return string
 */
function buildPrompt(array $bom): string
{
    $product = $bom['product'];
    $components = $bom['components'];

    $productName = $product['Name'] ?? 'Unknown Product';
    $partNumber  = $product['PartNumber'] ?? '';
    $revision    = $product['Revision'] ?? '';

    $lines = [];
    $lines[] = "You are a technical writer. Based on the following Bill of Materials (BOM), "
             . "write a clear and informative product description. "
             . "The description should explain what the product is, its main purpose, "
             . "and highlight key components and materials used.";
    $lines[] = '';
    $lines[] = "Product: {$productName}";

    if ($partNumber !== '') {
        $lines[] = "Part Number: {$partNumber}";
    }

    if ($revision !== '') {
        $lines[] = "Revision: {$revision}";
    }

    if (!empty($components)) {
        $lines[] = '';
        $lines[] = "Bill of Materials:";
        foreach ($components as $comp) {
            $name     = $comp['Name'] ?? 'Unknown';
            $qty      = $comp['Quantity'] ?? '';
            $unit     = $comp['Unit'] ?? '';
            $pn       = isset($comp['PartNumber']) ? " (PN: {$comp['PartNumber']})" : '';
            $qtyStr   = ($qty !== '' && $unit !== '') ? "{$qty} {$unit}" : $qty;
            $lines[] = "  - {$name}{$pn}" . ($qtyStr !== '' ? ": {$qtyStr}" : '');
        }
    }

    $lines[] = '';
    $lines[] = "Please write a concise product description (2-4 paragraphs).";

    return implode("\n", $lines);
}

/**
 * Call the Azure OpenAI Chat Completions API.
 *
 * @param  string               $prompt  The user prompt.
 * @param  array<string,mixed>  $config  Configuration values from config.php.
 * @return string The assistant's reply text.
 */
function callAzureOpenAI(string $prompt, array $config): string
{
    $endpoint   = rtrim($config['azure_openai_endpoint'], '/');
    $deployment = $config['azure_openai_deployment'];
    $apiVersion = $config['azure_openai_api_version'];
    $apiKey     = $config['azure_openai_api_key'];

    $url = "{$endpoint}/openai/deployments/{$deployment}/chat/completions?api-version={$apiVersion}";

    $payload = json_encode([
        'messages' => [
            [
                'role'    => 'system',
                'content' => 'You are a helpful technical writer that generates product descriptions.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ],
        'max_tokens'  => $config['max_tokens'],
        'temperature' => $config['temperature'],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "api-key: {$apiKey}",
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new RuntimeException("cURL error: {$curlError}");
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $errorMessage = $data['error']['message'] ?? $response;
        throw new RuntimeException(
            "Azure OpenAI API error (HTTP {$httpCode}): {$errorMessage}"
        );
    }

    $content = $data['choices'][0]['message']['content'] ?? null;

    if ($content === null) {
        throw new RuntimeException(
            "Unexpected response format from Azure OpenAI: " . $response
        );
    }

    return trim($content);
}

// ── Main execution ───────────────────────────────────────────────────────────

try {
    echo "Parsing BOM file: {$bomFile}\n";
    $bom = parseBomFile($bomFile);

    $productName = $bom['product']['Name'] ?? 'Unknown Product';
    $componentCount = count($bom['components']);
    echo "Product: {$productName}\n";
    echo "Components found: {$componentCount}\n\n";

    echo "Building prompt and calling Azure OpenAI...\n\n";
    $prompt = buildPrompt($bom);

    $description = callAzureOpenAI($prompt, $config);

    echo "=== Generated Product Description ===\n\n";
    echo $description . "\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
