#!/usr/bin/env php
<?php

/**
 * BOM Description Generator
 *
 * This script reads an XML file containing a Bill of Materials (BOM),
 * extracts the product and component information, then calls Azure OpenAI
 * to generate a product description based on the BOM.
 *
 * Usage:
 *   php bom_description.php <path-to-bom-xml>
 *
 * Example:
 *   php bom_description.php sample_bom.xml
 */

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
    fwrite(STDERR, "Usage: php bom_description.php <path-to-bom-xml>\n");
    fwrite(STDERR, "Example: php bom_description.php sample_bom.xml\n");
    exit(1);
}

$bomFile = $argv[1];

if (!file_exists($bomFile)) {
    fwrite(STDERR, "Error: File not found: {$bomFile}\n");
    exit(1);
}

// ── Parse the BOM XML ────────────────────────────────────────────────────────

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
    $bom = parseBomXml($bomFile);

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
