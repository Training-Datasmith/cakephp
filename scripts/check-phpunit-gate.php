<?php
declare(strict_types=1);

/**
 * Strict PHPUnit log gate for scripts/system-test.sh.
 *
 * Usage:
 *   php scripts/check-phpunit-gate.php <text-log> <teamcity-log> [<junit-log> [<allowlist-path> [<gate-mode>]]]]
 *
 * gate-mode: full (default) or database — database expects zero incompletes and
 *   "OK, but some tests were skipped!" as the success summary.
 *
 * Exit 0 when the run matches the allowed success shapes; otherwise exit 1 and print reasons.
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/check-phpunit-gate.php <text-log> <teamcity-log> [<junit-log> [<allowlist-path> [<gate-mode>]]]]\n");
    exit(2);
}

$textLog = $argv[1];
$teamcityLog = $argv[2];
$junitLog = $argv[3] ?? null;
$allowlistPath = $argv[4] ?? null;
$gateMode = $argv[5] ?? 'full';
if (!in_array($gateMode, ['full', 'database'], true)) {
    fwrite(STDERR, "Invalid gate-mode: {$gateMode}\n");
    exit(2);
}

foreach ([$textLog, $teamcityLog] as $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "Cannot read log file: {$path}\n");
        exit(2);
    }
}

if ($junitLog !== null && $junitLog !== '' && !is_readable($junitLog)) {
    fwrite(STDERR, "Cannot read JUnit log file: {$junitLog}\n");
    exit(2);
}

$allowedIncompleteId = 'Interop\\Http\\Factory\\StreamFactoryTest::testCreateStreamCursorPosition';
$allowedIncompleteMessage = 'This behaviour has not been specified by PHP-FIG yet.';

if ($allowlistPath === null || $allowlistPath === '') {
    $allowlistPath = dirname(__DIR__) . '/scripts/allowed-skipped-tests.txt';
}
$allowedSkips = [];
if (is_readable($allowlistPath)) {
    $lines = file($allowlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $allowedSkips[$line] = true;
    }
}

$text = file_get_contents($textLog);
if ($text === false) {
    fwrite(STDERR, "Failed to read {$textLog}\n");
    exit(2);
}

$teamcity = file_get_contents($teamcityLog);
if ($teamcity === false) {
    fwrite(STDERR, "Failed to read {$teamcityLog}\n");
    exit(2);
}

$failures = [];

foreach (preg_split('/\R/', $text) as $line) {
    if ($line === '') {
        continue;
    }
    if (preg_match('/^(Warning|Deprecated|Notice):/', $line)
        || preg_match('/^PHP (Warning|Deprecated|Notice):/', $line)) {
        $failures[] = "Disallowed output line: {$line}";
    }
}

$summaryLines = [
    'FAILURES!',
    'ERRORS!',
    'No tests executed!',
];
foreach ($summaryLines as $needle) {
    if (str_contains($text, $needle)) {
        $failures[] = "Summary contains {$needle}";
    }
}

$badCounterPattern = '/\b(Failures|Errors|Warnings|Deprecations|PHPUnit Deprecations|Notices|Risky):\s*[1-9]/';
if (preg_match($badCounterPattern, $text, $counterMatch)) {
    $failures[] = "Summary reports a non-zero issue counter: {$counterMatch[0]}";
}

if (preg_match('/##teamcity\[testFailed\b/', $teamcity)
    || preg_match('/##teamcity\[testError\b/', $teamcity)) {
    $failures[] = 'TeamCity log contains testFailed or testError events.';
}

/**
 * @return array{incompletes: list<array{id: string, message: string}>, skips: list<string>}
 */
function parseTeamcityIgnored(string $teamcity): array
{
    $currentClass = null;
    $incompletes = [];
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

        $id = $currentClass . '::' . $method;
        $message = $params['message'] ?? '';
        if (array_key_exists('details', $params) && $params['details'] !== '') {
            $incompletes[] = ['id' => $id, 'message' => $message];
            continue;
        }

        $skips[] = $id;
    }

    return ['incompletes' => $incompletes, 'skips' => $skips];
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

$parsed = parseTeamcityIgnored($teamcity);

$summaryIncomplete = null;
$summarySkipped = null;
$hasCleanOk = (bool)preg_match(
    '/^OK \(\d+ tests?, \d+ assertions?\)$/m',
    $text,
);
$hasIssuesOk = str_contains($text, 'OK, but there were issues!');
if (preg_match('/^Tests: (\d+), Assertions: (\d+)(?:, Skipped: (\d+))?(?:, Incomplete: (\d+))?\.$/m', $text, $testsLine)) {
    $summarySkipped = isset($testsLine[3]) ? (int)$testsLine[3] : 0;
    $summaryIncomplete = isset($testsLine[4]) ? (int)$testsLine[4] : 0;
}

if (count($parsed['incompletes']) !== ($summaryIncomplete ?? count($parsed['incompletes']))) {
    // TeamCity incomplete count should match the text summary when present.
    if ($summaryIncomplete !== null && count($parsed['incompletes']) !== $summaryIncomplete) {
        $failures[] = sprintf(
            'TeamCity incomplete count (%d) does not match summary Incomplete: %d.',
            count($parsed['incompletes']),
            $summaryIncomplete,
        );
    }
}

if ($gateMode === 'database') {
    if (count($parsed['incompletes']) > 0) {
        foreach ($parsed['incompletes'] as $incomplete) {
            $failures[] = 'Unexpected incomplete test: ' . $incomplete['id'];
        }
    }
    if ($summaryIncomplete !== null && $summaryIncomplete !== 0) {
        $failures[] = 'Database gate expects zero incomplete tests.';
    }
} else {
    foreach ($parsed['incompletes'] as $incomplete) {
        if ($incomplete['id'] !== $allowedIncompleteId) {
            $failures[] = 'Unexpected incomplete test: ' . $incomplete['id'];
            continue;
        }
        if ($incomplete['message'] !== $allowedIncompleteMessage) {
            $failures[] = sprintf(
                'Incomplete allowlisted test has unexpected message: %s',
                $incomplete['message'],
            );
        }
    }

    if ($hasIssuesOk) {
        if ($summaryIncomplete !== 1) {
            $failures[] = 'Expected exactly one incomplete test when summary is "OK, but there were issues!".';
        }
        if (!$hasCleanOk) {
            $expectedSummaryPattern = '/^Tests: \d+, Assertions: \d+(, Skipped: \d+)?, Incomplete: 1\.$/m';
            if (!preg_match($expectedSummaryPattern, $text)) {
                $failures[] = 'Summary line does not match "Tests: N, Assertions: M, Skipped: S, Incomplete: 1.".';
            }
        }
    }
}

$skipCount = count($parsed['skips']);
if ($allowedSkips === []) {
    if ($skipCount > 0) {
        $failures[] = 'Skip allowlist is empty but the run reported skipped tests.';
    }
} else {
    if ($summarySkipped !== null && $skipCount !== $summarySkipped) {
        $failures[] = sprintf(
            'TeamCity skip count (%d) does not match summary Skipped: %d.',
            $skipCount,
            $summarySkipped,
        );
    }
    if (count($allowedSkips) !== $skipCount) {
        $failures[] = sprintf(
            'Skip count (%d) does not match allowlist size (%d).',
            $skipCount,
            count($allowedSkips),
        );
    }
    foreach ($parsed['skips'] as $skipId) {
        if (!isset($allowedSkips[$skipId])) {
            $failures[] = 'Unexpected skipped test not in allowlist: ' . $skipId;
        }
    }
}

$hasSkippedOk = str_contains($text, 'OK, but some tests were skipped!');

$accepted = false;
if ($failures === []) {
    if ($gateMode === 'database') {
        if ($hasSkippedOk
            && ($summaryIncomplete === null || $summaryIncomplete === 0)
            && count($parsed['incompletes']) === 0) {
            $accepted = true;
        } else {
            $failures[] = 'PHPUnit output missing required "OK, but some tests were skipped!" summary.';
        }
    } elseif ($hasCleanOk && ($summaryIncomplete === null || $summaryIncomplete === 0)) {
        $accepted = true;
    } elseif ($hasIssuesOk && $summaryIncomplete === 1 && count($parsed['incompletes']) === 1) {
        $accepted = true;
    } else {
        $failures[] = 'PHPUnit output missing required success summary line.';
    }
}

if (!$accepted) {
    foreach ($failures as $reason) {
        fwrite(STDERR, $reason . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "PHPUnit gate passed.\n");
exit(0);
