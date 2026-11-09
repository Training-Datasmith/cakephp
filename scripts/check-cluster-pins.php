<?php
declare(strict_types=1);

/**
 * Verify installed cluster packages match fixed Training-Datasmith branch tips.
 */
$expected = [
    'psr/clock' => '3d3053da340ec84ff02c3765946a19ac021e0b1d',
    'psr/container' => '57d8cac2ff3db652075e2d6e44e0ede70720e8e5',
    'psr/http-client' => 'd251c92d20c7a883c2cc9c7f0e467d15c3c2a795',
    'psr/http-factory' => 'f9a0862677e7b1b9bd8dc2ed459f48c7e06ff277',
    'psr/http-message' => 'c941fca6736161fe07e79fa83d85217b39411cef',
    'psr/http-server-handler' => 'd23743bfa4fc9c1bc7d6060de7c4eb2420e8e78a',
    'psr/http-server-middleware' => '67f6905493ca6bbb639b14d57883baa511d37ec3',
    'psr/log' => 'bde874f9b44efec5901a6783185c050a3855b0f0',
    'psr/simple-cache' => 'd03ac6ca8193e8b800f667a63745f1db7806f412',
];

$installedPath = dirname(__DIR__) . '/vendor/composer/installed.json';
if (!is_readable($installedPath)) {
    fwrite(STDERR, "Missing {$installedPath}; run composer install first.\n");
    exit(1);
}

$raw = file_get_contents($installedPath);
if ($raw === false) {
    fwrite(STDERR, "Could not read {$installedPath}.\n");
    exit(1);
}

/** @var array{packages?: list<array<string, mixed>>, 'packages-dev'?: list<array<string, mixed>>} $data */
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$packages = array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []);

/** @var array<string, string> $installed */
$installed = [];
foreach ($packages as $package) {
    if (!isset($package['name'], $package['source']['reference'])) {
        continue;
    }
    $installed[$package['name']] = strtolower((string)$package['source']['reference']);
}

$failed = false;
foreach ($expected as $name => $reference) {
    if (!isset($installed[$name])) {
        fwrite(STDERR, "Cluster pin check failed for {$name}: package is not installed.\n");
        $failed = true;
        continue;
    }
    $actual = $installed[$name];
    $want = strtolower($reference);
    if ($actual !== $want) {
        fwrite(STDERR, "Cluster pin check failed for {$name}:\n");
        fwrite(STDERR, "  expected SHA: {$want}\n");
        fwrite(STDERR, "  actual SHA:   {$actual}\n");
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, "All cluster package pins match expected SHAs.\n");
exit(0);
