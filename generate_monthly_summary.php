<?php

set_time_limit(0);

require_once __DIR__ . '/monthly_stats_lib.php';

$pdo = null;

try {
    $config = listenerTrackerLoadConfig();
    $snapshotIntervalMinutes = (int)$config['app']['snapshot_interval_minutes'];
    $defaultUtcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $summarySource = (string)listenerTrackerReadOption('source', 'local-snapshots');
    $defaultTimezone = listenerTrackerResolveTimezone($config['app']['default_timezone'], 'UTC');

    $year = max(2000, (int)listenerTrackerReadOption('year', $defaultUtcNow->format('Y')));
    $rawMonth = (string)listenerTrackerReadOption('month', $defaultUtcNow->format('m'));
    $months = $rawMonth === 'all'
        ? range(1, 12)
        : [max(1, min(12, (int)$rawMonth))];

    $pdo = listenerTrackerGetPdo();
    listenerTrackerEnsureSchema($pdo);

    $aggregateStatement = $pdo->prepare(
        sprintf(
            'SELECT
                station_id,
                COUNT(*) AS snapshots_count,
                ROUND(AVG(listeners_current), 2) AS avg_listeners,
                MAX(listeners_current) AS peak_listeners,
                MIN(listeners_current) AS min_listeners,
                SUM(listeners_current * %1$d) AS listener_minutes,
                ROUND(SUM(listeners_current * %1$d) / 60, 2) AS listener_hours
            FROM listener_snapshots
            WHERE captured_at_utc >= :range_start
              AND captured_at_utc < :range_end
            GROUP BY station_id
            ORDER BY station_id ASC',
            $snapshotIntervalMinutes
        )
    );
    $deleteStatement = $pdo->prepare(
        'DELETE FROM listener_monthly_summary WHERE month_key = :year_month'
    );
    $insertStatement = $pdo->prepare(
        'INSERT INTO listener_monthly_summary (
            station_id,
            month_key,
            snapshots_count,
            unique_listeners,
            avg_listeners,
            peak_listeners,
            min_listeners,
            listener_minutes,
            listener_hours
        ) VALUES (
            :station_id,
            :year_month,
            :snapshots_count,
            :unique_listeners,
            :avg_listeners,
            :peak_listeners,
            :min_listeners,
            :listener_minutes,
            :listener_hours
        )'
    );

    $processedMonths = [];
    $writtenRows = 0;

    if ($summarySource === 'azuracast-history') {
        $stationRows = listenerTrackerFetchStationsCatalog();

        foreach ($stationRows as $stationRow) {
            if (!is_array($stationRow)) {
                continue;
            }

            listenerTrackerUpsertStation($pdo, listenerTrackerNormalizeStation($stationRow));
        }

        foreach ($months as $month) {
            $monthKey = sprintf('%04d-%02d', $year, $month);

            $pdo->beginTransaction();

            $deleteStatement->execute([
                'year_month' => $monthKey,
            ]);

            foreach ($stationRows as $stationRow) {
                if (!is_array($stationRow)) {
                    continue;
                }

                $station = listenerTrackerNormalizeStation($stationRow);
                $stationTimezone = listenerTrackerResolveTimezone($station['timezone'], $defaultTimezone->getName());
                $range = listenerTrackerMonthRangeForTimezone($year, $month, $stationTimezone);
                $historyRows = listenerTrackerFetchStationListenerHistory(
                    (int)$station['station_id'],
                    $range['start'],
                    $range['end']
                );
                $summaryRow = listenerTrackerSummarizeHistoryRows(
                    $historyRows,
                    $range['start'],
                    $range['end'],
                    $snapshotIntervalMinutes
                );

                if (
                    (int)$summaryRow['listener_minutes'] === 0
                    && (float)$summaryRow['listener_hours'] === 0.0
                    && (int)$summaryRow['peak_listeners'] === 0
                ) {
                    continue;
                }

                $insertStatement->execute([
                    'station_id' => (int)$station['station_id'],
                    'year_month' => $monthKey,
                    'snapshots_count' => (int)$summaryRow['snapshots_count'],
                    'unique_listeners' => (int)$summaryRow['unique_listeners'],
                    'avg_listeners' => $summaryRow['avg_listeners'],
                    'peak_listeners' => (int)$summaryRow['peak_listeners'],
                    'min_listeners' => (int)$summaryRow['min_listeners'],
                    'listener_minutes' => (int)$summaryRow['listener_minutes'],
                    'listener_hours' => $summaryRow['listener_hours'],
                ]);
                $writtenRows++;
            }

            $pdo->commit();
            $processedMonths[] = $monthKey;
        }
    } else {
        foreach ($months as $month) {
            $range = listenerTrackerMonthRangeUtc($year, $month);

            $pdo->beginTransaction();

            $deleteStatement->execute([
                'year_month' => $range['year_month'],
            ]);

            $aggregateStatement->execute([
                'range_start' => $range['start']->format('Y-m-d H:i:s'),
                'range_end' => $range['end']->format('Y-m-d H:i:s'),
            ]);

            $summaryRows = $aggregateStatement->fetchAll();
            $stationMap = listenerTrackerFetchStoredStationMap($pdo);

            foreach ($summaryRows as $summaryRow) {
                $stationId = (int)$summaryRow['station_id'];
                $stationTimezoneName = (string)($stationMap[$stationId]['timezone'] ?? $defaultTimezone->getName());
                $stationTimezone = listenerTrackerResolveTimezone($stationTimezoneName, $defaultTimezone->getName());
                $historyRange = listenerTrackerMonthRangeForTimezone($year, $month, $stationTimezone);
                $uniqueListeners = 0;

                try {
                    $historyRows = listenerTrackerFetchStationListenerHistory(
                        $stationId,
                        $historyRange['start'],
                        $historyRange['end']
                    );
                    $uniqueListeners = listenerTrackerCountUniqueListeners($historyRows);
                } catch (Throwable $historyException) {
                    $uniqueListeners = 0;
                }

                $insertStatement->execute([
                    'station_id' => $stationId,
                    'year_month' => $range['year_month'],
                    'snapshots_count' => (int)$summaryRow['snapshots_count'],
                    'unique_listeners' => $uniqueListeners,
                    'avg_listeners' => $summaryRow['avg_listeners'],
                    'peak_listeners' => (int)$summaryRow['peak_listeners'],
                    'min_listeners' => (int)$summaryRow['min_listeners'],
                    'listener_minutes' => (int)$summaryRow['listener_minutes'],
                    'listener_hours' => $summaryRow['listener_hours'],
                ]);
                $writtenRows++;
            }

            $pdo->commit();
            $processedMonths[] = $range['year_month'];
        }
    }

    $message = sprintf(
        'Rebuilt %d monthly summary rows for %s using source=%s.',
        $writtenRows,
        implode(', ', $processedMonths),
        $summarySource
    );

    if (PHP_SAPI === 'cli') {
        echo $message . PHP_EOL;
    } else {
        echo nl2br(listenerTrackerH($message));
    }
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = 'Monthly summary generation failed: ' . $exception->getMessage();

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        http_response_code(500);
        echo nl2br(listenerTrackerH($message));
    }

    exit(1);
}
