<?php

declare(strict_types=1);
return [
    'Read' => 'value2',
    'Deep' => [
        'Second' => [
            'SecondDeepest' => 'buried2',
        ],
    ],
    'TestAcl' => [
        'classname' => 'Overwrite',
        'custom' => 'one',
    ],
];
