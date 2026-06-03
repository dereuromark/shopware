<?php declare(strict_types=1);

// Experiment branch (ci/phpunit-12-preview-always): force the *entire* PHPUnit matrix onto
// PHPUnit 12 + PHP 8.3 so PHPUnit 12 readiness can be assessed across every suite at once.
// Every entry carries phpunit=12, which drives the "Force PHPUnit 12" composer step in integration.yml.
$matrix = [
    'fail-fast' => false,
    'matrix' => [
        'test' => [
            ['path' => 'Core/Checkout'],
            ['path' => 'Core/Content'],
            ['testsuite' => 'core-framework-batch1'],
            ['testsuite' => 'core-framework-batch2'],
            ['testsuite' => 'core-framework-batch3'],
            ['path' => 'Storefront'],
            ['path' => '{Administration,Elasticsearch}'],
            ['path' => '{Core/Installer,Core/Maintenance,Core/Service,Core/System}'],
            ['testsuite' => 'migration'],
            ['testsuite' => 'devops'],
            ['testsuite' => 'unit'],
        ],
        'php' => ['8.3'],
        'db' => ['mysql:8.0'],
        'opensearch' => ['opensearchproject/opensearch:3'],
        'phpunit' => ['12'],
    ],
];

echo \json_encode($matrix, \JSON_THROW_ON_ERROR);
