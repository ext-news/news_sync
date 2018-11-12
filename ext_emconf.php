<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Sync news from a different site',
    'description' => 'Import news of a different installation',
    'category' => 'module',
    'author' => 'Georg Ringer',
    'author_email' => 'mail@ringer.it',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '8.7.13-9.5.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'news' => '7.0.0-7.99.99'
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'GeorgRinger\\NewsSync\\' => 'Classes/',
        ]
    ]
];
