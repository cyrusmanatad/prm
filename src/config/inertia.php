<?php

return [
    'ssr' => ['enabled' => false],
    'pages' => [
        'ensure_pages_exist' => true,
        'paths' => [resource_path('js/pages')],
        'extensions' => ['vue'],
    ],
    'history' => ['encrypt' => true],
    // No local prop recording: foundation pages can contain identity/reset data.
    'devtools' => ['enabled' => false],
];
