<?php

/**
 * Azure OpenAI Configuration
 *
 * Fill in your Azure OpenAI credentials below, or set the values as
 * environment variables (recommended for production).
 */

return [
    // Azure OpenAI endpoint URL
    // Format: https://<your-resource-name>.openai.azure.com/
    'azure_openai_endpoint' => getenv('AZURE_OPENAI_ENDPOINT') ?: 'https://your-resource-name.openai.azure.com/',

    // Azure OpenAI API key
    'azure_openai_api_key' => getenv('AZURE_OPENAI_API_KEY') ?: 'your-api-key-here',

    // Azure OpenAI deployment name (the name you gave your model deployment)
    'azure_openai_deployment' => getenv('AZURE_OPENAI_DEPLOYMENT') ?: 'gpt-4',

    // Azure OpenAI API version
    'azure_openai_api_version' => getenv('AZURE_OPENAI_API_VERSION') ?: '2024-02-01',

    // Maximum tokens for the response
    'max_tokens' => (int)(getenv('AZURE_OPENAI_MAX_TOKENS') ?: 800),

    // Temperature for response creativity (0.0 - 1.0)
    'temperature' => (float)(getenv('AZURE_OPENAI_TEMPERATURE') ?: 0.7),
];
