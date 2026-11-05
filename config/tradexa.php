<?php

return [
    'supabase_url' => env('SUPABASE_URL'),
    'service_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
    'didit_key' => env('DIDIT_API_KEY'),
    'didit_workflow' => env('DIDIT_WORKFLOW_ID'),
    'didit_environment' => env('DIDIT_ENVIRONMENT'),
    'media_hosts' => array_values(array_filter(array_map('trim', explode(',', env('DIDIT_MEDIA_HOSTS', ''))))),
    'document_model_url' => env('DOCUMENT_MODEL_URL', 'http://127.0.0.1:8002'),
    'document_model_secret' => env('DOCUMENT_MODEL_SECRET'),
    'document_encryption_key' => env('DOCUMENT_ENCRYPTION_KEY'),
    'web_url' => env('TRADEXA_WEB_URL', 'http://127.0.0.1:5173'),
];
