<?php

declare(strict_types=1);

$reportPath = $argv[1] ?? '';
if (!is_file($reportPath)) {
    fwrite(STDERR, "Missing Clover coverage report. Run make coverage.\n");
    exit(1);
}

$report = simplexml_load_file($reportPath, options: LIBXML_NONET);
if (false === $report) {
    fwrite(STDERR, "Invalid Clover coverage report.\n");
    exit(1);
}

$totals = ['Overall' => [0, 0], 'Domain/Application' => [0, 0]];
foreach ($report->xpath('//file') ?: [] as $file) {
    $path = str_replace('\\', '/', (string) $file['name']);
    $metrics = $file->metrics;
    if (!isset($metrics['statements'], $metrics['coveredstatements'])) {
        fwrite(STDERR, "Missing coverage metrics for {$path}.\n");
        exit(1);
    }
    $total = (int) $metrics['statements'];
    $covered = (int) $metrics['coveredstatements'];
    $totals['Overall'][0] += $total;
    $totals['Overall'][1] += $covered;
    if (preg_match('~(?:^|/)src/(?:Domain|Application)/~', $path)) {
        $totals['Domain/Application'][0] += $total;
        $totals['Domain/Application'][1] += $covered;
    }
}

$failed = false;
foreach ($totals as $scope => [$total, $covered]) {
    if (0 === $total) {
        if ('Overall' === $scope) {
            fwrite(STDERR, "Coverage report contains no executable source lines.\n");
            $failed = true;
        } else {
            echo "{$scope}: no executable lines yet\n";
        }
        continue;
    }
    $minimum = 'Overall' === $scope ? 98 : 100;
    $passes = $covered * 100 >= $total * $minimum;
    printf("%s: %.2f%% (%d/%d lines), minimum %d%% — %s\n", $scope, $covered / $total * 100, $covered, $total, $minimum, $passes ? 'PASS' : 'FAIL');
    $failed = $failed || !$passes;
}
exit($failed ? 1 : 0);
