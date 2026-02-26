<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'KeyValue Store',
    'description' => 'Redis/Valkey integration for TYPO3 with PHPRedis >= 6.3, Sentinel and TLS/mTLS.',
    'category' => 'misc',
    'author' => 'Moselwal',
    'author_email' => '',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-14.99.99',
            'php' => '8.1.0-8.5.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
