<?php
declare(strict_types=1);

/**
 * Build scripts/allowed-skipped-tests.txt from a TeamCity log (testIgnored events without details).
 *
 * Usage:
 *   php scripts/build-allowed-skipped-tests.php <teamcity-log>
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/build-allowed-skipped-tests.php <teamcity-log>\n");
    exit(2);
}

$teamcityLog = $argv[1];
$teamcity = file_get_contents($teamcityLog);
if ($teamcity === false) {
    fwrite(STDERR, "Cannot read {$teamcityLog}\n");
    exit(2);
}

/**
 * @return array<string, string>
 */
function parseTeamcityParams(string $raw): array
{
    $params = [];
    if (preg_match_all("/(\w+)='([^']*)'/", $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $params[$match[1]] = str_replace(['|n', "|'"], ["\n", "'"], $match[2]);
        }
    }

    return $params;
}

$currentClass = null;
$skips = [];

foreach (preg_split('/\R/', $teamcity) as $line) {
    if (!preg_match('/^##teamcity\[(\w+)\s(.*)\]$/', $line, $eventMatch)) {
        continue;
    }

    $event = $eventMatch[1];
    $params = parseTeamcityParams($eventMatch[2]);

    if (($event === 'testStarted' || $event === 'testSuiteStarted') && isset($params['locationHint'])) {
        $hint = $params['locationHint'];
        if (preg_match('/::\\\\(.+)::([^:]+)$/', $hint, $classMatch)) {
            $currentClass = $classMatch[1];
        } elseif (preg_match('/::\\\\(.+)$/', $hint, $classMatch)) {
            $currentClass = $classMatch[1];
        }
        continue;
    }

    if ($event !== 'testIgnored') {
        continue;
    }

    $method = $params['name'] ?? '';
    if ($currentClass === null || $method === '') {
        continue;
    }
    if (array_key_exists('details', $params) && $params['details'] !== '') {
        continue;
    }

    $skips[] = $currentClass . '::' . $method;
}

$skips = array_values(array_unique($skips));
sort($skips, SORT_STRING);

echo "# PHPUnit skipped tests allowlisted for scripts/check-phpunit-gate.php\n";
echo "# Generated from TeamCity output. Only intentional environment skips.\n";
foreach ($skips as $skipId) {
    echo $skipId . "\n";
}
