<?php

set_time_limit(0);

require_once __DIR__ . '/monthly_stats_lib.php';

$pdo = null;

try {
    $config = listenerTrackerLoadConfig();
    $pdo = listenerTrackerGetPdo();
    listenerTrackerEnsureSchema($pdo);

    $capturedAtInput = listenerTrackerReadOption('captured-at', 'now');
    $capturedAtUtc = new DateTimeImmutable(
        is_string($capturedAtInput) ? $capturedAtInput : 'now',
        new DateTimeZone('UTC')
    );
    $capturedAtUtc = listenerTrackerBucketUtcTimestamp(
        $capturedAtUtc,
        (int)$config['app']['snapshot_interval_minutes']
    );

    $refreshStations = listenerTrackerToBoolInt(listenerTrackerReadOption('refresh-stations', 0), 0) === 1;
    $stationRows = $refreshStations ? listenerTrackerFetchStationsCatalog() : [];
    $nowPlayingRows = listenerTrackerFetchNowPlayingAll();

    $stationCount = 0;
    $snapshotCount = 0;

    $pdo->beginTransaction();

    foreach ($stationRows as $stationRow) {
        if (!is_array($stationRow)) {
            continue;
        }

        listenerTrackerUpsertStation($pdo, listenerTrackerNormalizeStation($stationRow));
    }

    foreach ($nowPlayingRows as $nowPlayingRow) {
        if (!is_array($nowPlayingRow)) {
            continue;
        }

        $station = listenerTrackerNormalizeStation($nowPlayingRow);
        $snapshot = listenerTrackerNormalizeSnapshot($nowPlayingRow, $capturedAtUtc);

        listenerTrackerUpsertStation($pdo, $station);
        listenerTrackerInsertSnapshot($pdo, $snapshot);

        $stationCount++;
        $snapshotCount++;
    }

    $pdo->commit();

    $message = sprintf(
        'Stored %d snapshots for %d stations at %s UTC%s.',
        $snapshotCount,
        $stationCount,
        $capturedAtUtc->format('Y-m-d H:i:s'),
        $refreshStations ? ' with a station catalog refresh' : ''
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

    $message = 'Listener logging failed: ' . $exception->getMessage();

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        http_response_code(500);
        echo nl2br(listenerTrackerH($message));
    }

    exit(1);
}
