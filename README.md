# azureopenai – BOM Description Generator

A PHP script that reads an XML **Bill of Materials (BOM)** file (or an XSLT
file with an embedded BOM), then calls **Azure OpenAI** to generate a concise
product description based on the listed components.

---

## Files

| File | Purpose |
|---|---|
| `bom_description.php` | Main script – parse BOM + call Azure OpenAI |
| `config.php` | Azure OpenAI credentials / settings |
| `sample_bom.xml` | Example BOM XML file (Industrial Water Pump) |
| `.env.example` | Template for environment variable configuration |

---

## Requirements

* PHP 8.0 or later
* PHP extensions: `simplexml`, `curl`, `json` (all bundled with most PHP distributions)
* An [Azure OpenAI](https://azure.microsoft.com/en-us/products/ai-services/openai-service) resource with a deployed model (e.g. `gpt-4`)

---

## Setup

1. **Clone the repository** (or copy the files to your server).

2. **Configure credentials** – open `config.php` and fill in your Azure OpenAI
   values, *or* export the corresponding environment variables:

   ```bash
   export AZURE_OPENAI_ENDPOINT="https://your-resource-name.openai.azure.com/"
   export AZURE_OPENAI_API_KEY="your-api-key"
   export AZURE_OPENAI_DEPLOYMENT="gpt-4"
   export AZURE_OPENAI_API_VERSION="2024-02-01"
   ```

   See `.env.example` for a full list of supported variables.

---

## Usage

```bash
php bom_description.php <path-to-bom-xml>
```

### Example with the included sample file

```bash
php bom_description.php sample_bom.xml
```

Sample output:

```
Parsing BOM file: sample_bom.xml
Product: Industrial Water Pump
Components found: 8

Building prompt and calling Azure OpenAI...

=== Generated Product Description ===

The Industrial Water Pump (WP-2000X) is a heavy-duty centrifugal pump
designed for demanding industrial fluid-transfer applications ...
```

---

## BOM XML format

The script expects an XML file with the following structure:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<BillOfMaterials>
    <Product>
        <Name>Product Name</Name>
        <PartNumber>PN-001</PartNumber>
        <Revision>A</Revision>          <!-- optional -->
    </Product>
    <Components>
        <Component>
            <PartNumber>COMP-001</PartNumber>
            <Name>Component Name</Name>
            <Quantity>2</Quantity>
            <Unit>pcs</Unit>
        </Component>
        <!-- more components … -->
    </Components>
</BillOfMaterials>
```

### XSLT support

If your file is an XSLT stylesheet that contains an embedded
`<BillOfMaterials>` element, the script will locate it automatically and
extract the BOM data from it.

---

## Configuration reference

| Variable / key | Default | Description |
|---|---|---|
| `AZURE_OPENAI_ENDPOINT` | *(required)* | Azure OpenAI resource endpoint URL |
| `AZURE_OPENAI_API_KEY` | *(required)* | Azure OpenAI API key |
| `AZURE_OPENAI_DEPLOYMENT` | `gpt-4` | Deployment name in Azure OpenAI Studio |
| `AZURE_OPENAI_API_VERSION` | `2024-02-01` | API version |
| `AZURE_OPENAI_MAX_TOKENS` | `800` | Max tokens in the generated description |
| `AZURE_OPENAI_TEMPERATURE` | `0.7` | Response creativity (0.0 – 1.0) |