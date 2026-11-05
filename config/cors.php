<?php
return [
 'paths' => ['api/*'], 'allowed_methods' => ['POST','DELETE','OPTIONS'],
 'allowed_origins' => array_values(array_unique([env('TRADEXA_WEB_URL', 'http://127.0.0.1:5173'), 'http://127.0.0.1:5173', 'http://localhost:5173'])),
 'allowed_origins_patterns' => [], 'allowed_headers' => ['Authorization','Content-Type','Accept'], 'exposed_headers' => [], 'max_age' => 600, 'supports_credentials' => false,
];
