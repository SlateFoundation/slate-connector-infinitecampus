<?php

Git::$repositories['slate-connector-infinitecampus'] = [
    'remote' => 'https://github.com/SlateFoundation/slate-connector-infinitecampus.git',
    'originBranch' => 'master',
    'workingBranch' => 'master',
    'trees' => [
        'html-templates/connectors/infinite-campus/createJob.tpl',
        'php-classes/Emergence/Connectors/AbstractSpreadsheetConnector.php', // TODO: merge upstream
        'php-classes/Slate/Connectors/AbstractSpreadsheetConnector.php', // TODO: merge upstream
        'php-classes/Slate/Connectors/InfiniteCampus/Connector.php',
        'php-config/Git.config.d/slate-connector-infinitecampus.php',
        'site-root/connectors/infinite-campus.php'
    ]
];