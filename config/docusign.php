<?php

return [
    'integration_key' => env('DOCUSIGN_INTEGRATION_KEY', ''),
    'secret_key' => env('DOCUSIGN_SECRET_KEY', ''),
    'oauth_base' => env('DOCUSIGN_OAUTH_BASE', 'https://account-d.docusign.com'),
    'redirect_uri' => env('DOCUSIGN_REDIRECT_URI', 'http://localhost:8000/api/v1/integrations/docusign/callback'),
];
