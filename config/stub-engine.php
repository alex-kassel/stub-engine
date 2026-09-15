<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Token Delimiters
    |--------------------------------------------------------------------------
    |
    | Delimiters used to enclose placeholder tokens within template files
    | and path names (e.g. {{ token_name }}).
    |
    */
    'delimiters' => [
        'open' => env('STUB_ENGINE_OPEN_DELIMITER', '{{'),
        'close' => env('STUB_ENGINE_CLOSE_DELIMITER', '}}'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Tokens
    |--------------------------------------------------------------------------
    |
    | Default key-value pairs merged into every scaffolding call. Specific
    | tokens passed at runtime will take precedence over these defaults.
    |
    */
    'global_tokens' => [
        // 'company_name' => env('STUB_ENGINE_COMPANY_NAME', 'Acme Corp'),
    ],
];
