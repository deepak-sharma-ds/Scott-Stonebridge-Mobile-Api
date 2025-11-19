<?php
return [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    'scopes' => env('GOOGLE_SCOPES'),
    'project_id' => env('PROJECT_ID'),
    'auth_uri' => env('AUTH_URI'),
    'token_uri' => env('TOKEN_URI'),
    'auth_provider_x509_cert_url' => env('AUTH_PROVIDER_X509_CERT_URL'),
];
