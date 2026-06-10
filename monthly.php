<?php

require_once __DIR__ . '/monthly_stats_lib.php';

$config = listenerTrackerLoadConfig();
$displayTimezone = new DateTimeZone($config['app']['default_timezone']);
$now = new DateTimeImmutable('now', $displayTimezone);
$currentYear = (int)$now->format('Y');
$currentMonth = (int)$now->format('n');
$view = ($_GET['view'] ?? 'dashboard') === 'report' ? 'report' : 'dashboard';
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$dashboardRefreshSeconds = max(30, (int)$config['app']['snapshot_interval_minutes'] * 60);
$autoRefreshEnabled = $view === 'dashboard'
    && listenerTrackerToBoolInt($_GET['autorefresh'] ?? 1, 1) === 1;
$autoRefreshToggleQuery = http_build_query([
    'view' => 'dashboard',
    'autorefresh' => $autoRefreshEnabled ? '0' : '1',
]);
$autoRefreshLabel = $autoRefreshEnabled
    ? 'Auto-refresh is on'
    : 'Auto-refresh is off';
$autoRefreshActionLabel = $autoRefreshEnabled
    ? 'Pause Auto-refresh'
    : 'Turn On Auto-refresh';
$autoRefreshNote = sprintf(
    'Dashboard reloads every %d seconds to pick up new listener snapshots.',
    $dashboardRefreshSeconds
);

$dashboardRows = [];
$trendRows = [];
$summaryRows = [];
$availableYears = [$currentYear];
$error = null;

$selectedYear = max(2000, (int)($_GET['year'] ?? $currentYear));
$rawMonth = (string)($_GET['month'] ?? str_pad((string)$currentMonth, 2, '0', STR_PAD_LEFT));
$showAllMonths = $rawMonth === 'all';
$selectedMonth = $showAllMonths ? null : max(1, min(12, (int)$rawMonth));
$selectedYearMonth = $showAllMonths ? null : sprintf('%04d-%02d', $selectedYear, $selectedMonth);

try {
    $pdo = listenerTrackerGetPdo();
    listenerTrackerEnsureSchema($pdo);
    $availableYears = listenerTrackerFetchAvailableYears($pdo);

    if ($view === 'dashboard') {
        $dashboardRows = listenerTrackerFetchLatestSnapshotRows($pdo);
        $trendRows = listenerTrackerFetchNetworkTrendRows($pdo, 32);
    } else {
        $summaryRows = listenerTrackerFetchSummaryRows($pdo, $selectedYear, $selectedMonth);

        if (($_GET['format'] ?? '') === 'csv') {
            $csvRows = [];

            foreach ($summaryRows as $row) {
                $csvRows[] = [
                    $row['report_month'],
                    $row['station_id'],
                    $row['station_name'],
                    $row['shortcode'],
                    $row['snapshots_count'],
                    $row['unique_listeners'],
                    $row['avg_listeners'],
                    $row['peak_listeners'],
                    $row['min_listeners'],
                    $row['listener_minutes'],
                    $row['listener_hours'],
                ];
            }

            $csvFile = $showAllMonths
                ? sprintf('listener-report-%04d-all.csv', $selectedYear)
                : sprintf('listener-report-%s.csv', $selectedYearMonth);

            listenerTrackerOutputCsv(
                $csvFile,
                [
                    'report_month',
                    'station_id',
                    'station_name',
                    'shortcode',
                    'snapshots_count',
                    'unique_listeners',
                    'avg_listeners',
                    'peak_listeners',
                    'min_listeners',
                    'listener_minutes',
                    'listener_hours',
                ],
                $csvRows
            );
            exit;
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$dashboardTotalListeners = array_sum(array_column($dashboardRows, 'listeners_current'));
$dashboardUniqueListeners = array_sum(array_column($dashboardRows, 'listeners_unique'));
$dashboardStationCount = count($dashboardRows);
$dashboardTopStation = $dashboardRows[0]['station_name'] ?? 'No data yet';
$dashboardTopValue = $dashboardRows[0]['listeners_current'] ?? 0;
$dashboardCapturedAtLabel = 'No snapshots logged yet';

if ($dashboardRows) {
    $capturedAtUtc = new DateTimeImmutable($dashboardRows[0]['captured_at_utc'], new DateTimeZone('UTC'));
    $dashboardCapturedAtLabel = $capturedAtUtc
        ->setTimezone($displayTimezone)
        ->format('F j, Y g:i A');
}

$dashboardChartLabels = [];
$dashboardChartData = [];

foreach (array_slice($dashboardRows, 0, 8) as $row) {
    $dashboardChartLabels[] = $row['station_name'];
    $dashboardChartData[] = (int)$row['listeners_current'];
}

$trendChartLabels = [];
$trendChartData = [];

foreach ($trendRows as $trendRow) {
    $trendAt = new DateTimeImmutable($trendRow['captured_at_utc'], new DateTimeZone('UTC'));
    $trendChartLabels[] = $trendAt->setTimezone($displayTimezone)->format('M j g:i A');
    $trendChartData[] = (int)$trendRow['total_listeners'];
}

$reportTotalHours = 0.0;
$reportTotalMinutes = 0;
$reportTotalSnapshots = 0;
$reportStationCount = 0;
$reportCoverageCount = 0;
$reportTopStation = 'No data yet';
$reportTopHours = 0.0;
$reportPeakListeners = 0;
$reportPeriodLabel = $showAllMonths
    ? sprintf('All Months of %04d', $selectedYear)
    : listenerTrackerFormatYearMonthLabel($selectedYearMonth ?? '');

$stationTotals = [];
$monthTotals = [];

foreach ($summaryRows as $row) {
    $reportTotalHours += (float)$row['listener_hours'];
    $reportTotalMinutes += (int)$row['listener_minutes'];
    $reportTotalSnapshots += (int)$row['snapshots_count'];
    $reportPeakListeners = max($reportPeakListeners, (int)$row['peak_listeners']);
    $stationTotals[$row['station_name']] = ($stationTotals[$row['station_name']] ?? 0.0) + (float)$row['listener_hours'];

    if (!isset($monthTotals[$row['report_month']])) {
        $monthTotals[$row['report_month']] = 0.0;
    }

    $monthTotals[$row['report_month']] += (float)$row['listener_hours'];
}

if ($summaryRows) {
    $reportStationCount = count(array_unique(array_column($summaryRows, 'station_id')));
    $reportCoverageCount = count($monthTotals);
}

arsort($stationTotals);

if ($stationTotals) {
    $reportTopStation = (string)array_key_first($stationTotals);
    $reportTopHours = (float)$stationTotals[$reportTopStation];
}

$stationShareLabels = [];
$stationShareData = [];

foreach (array_slice($stationTotals, 0, 8, true) as $stationName => $hours) {
    $stationShareLabels[] = $stationName;
    $stationShareData[] = round($hours, 2);
}

$periodChartLabels = [];
$periodChartData = [];
$periodChartTitle = $showAllMonths ? 'Network Listener Hours by Month' : 'Top Stations by Listener Hours';
$periodChartTag = $showAllMonths ? 'Monthly totals' : 'Current selection';

if ($showAllMonths) {
    foreach ($monthTotals as $yearMonth => $hours) {
        $periodChartLabels[] = listenerTrackerFormatYearMonthLabel($yearMonth);
        $periodChartData[] = round($hours, 2);
    }
} else {
    foreach (array_slice($summaryRows, 0, 10) as $row) {
        $periodChartLabels[] = $row['station_name'];
        $periodChartData[] = round((float)$row['listener_hours'], 2);
    }
}

$downloadQuery = http_build_query([
    'view' => 'report',
    'year' => $selectedYear,
    'month' => $showAllMonths ? 'all' : str_pad((string)$selectedMonth, 2, '0', STR_PAD_LEFT),
    'format' => 'csv',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AzuraCast Listener Tracker</title>
    <?php if ($autoRefreshEnabled): ?>
        <meta http-equiv="refresh" content="<?= listenerTrackerH((string)$dashboardRefreshSeconds) ?>">
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap');

        :root {
            --bg: #f4f7f1;
            --panel: rgba(255, 255, 255, 0.9);
            --panel-strong: #ffffff;
            --ink: #14211d;
            --muted: #60706a;
            --border: rgba(20, 33, 29, 0.12);
            --primary: #136f63;
            --primary-deep: #0d5c53;
            --accent: #e3a018;
            --accent-soft: #fff5d8;
            --success: #2f855a;
            --shadow: 0 24px 60px rgba(20, 33, 29, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(19, 111, 99, 0.16), transparent 34%),
                radial-gradient(circle at top right, rgba(227, 160, 24, 0.14), transparent 30%),
                linear-gradient(180deg, #f8faf7 0%, #eef4ed 100%);
        }

        .page {
            max-width: 1220px;
            margin: 0 auto;
            padding: 28px 20px 40px;
        }

        .stack {
            display: grid;
            gap: 18px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 26px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 320px;
            gap: 18px;
            padding: 24px;
            overflow: hidden;
            position: relative;
        }

        .hero::after {
            content: '';
            position: absolute;
            inset: auto -80px -120px auto;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(19, 111, 99, 0.08);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(19, 111, 99, 0.12);
            color: var(--primary);
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        h1, h2, h3, p {
            margin: 0;
        }

        .hero h1 {
            margin-top: 12px;
            font-size: clamp(2rem, 4vw, 3.4rem);
            line-height: 0.98;
            max-width: 12ch;
        }

        .hero-copy {
            margin-top: 16px;
            max-width: 68ch;
            color: var(--muted);
            line-height: 1.7;
        }

        .hero-card {
            display: grid;
            gap: 16px;
            align-content: space-between;
            padding: 22px;
            border-radius: 22px;
            background: linear-gradient(180deg, var(--primary) 0%, var(--primary-deep) 100%);
            color: #ffffff;
        }

        .hero-card .label {
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            opacity: 0.84;
        }

        .hero-card .value {
            font-size: clamp(2.6rem, 4vw, 3.8rem);
            line-height: 0.94;
        }

        .hero-card .meta {
            color: rgba(255, 255, 255, 0.84);
            line-height: 1.6;
        }

        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 0 24px 24px;
        }

        .nav a {
            text-decoration: none;
            color: var(--primary);
            border: 1px solid rgba(19, 111, 99, 0.18);
            background: rgba(19, 111, 99, 0.08);
            border-radius: 999px;
            padding: 11px 15px;
            font-weight: 700;
        }

        .nav a.active {
            color: #ffffff;
            background: var(--primary);
            border-color: transparent;
        }

        .toolbar {
            padding: 22px;
        }

        .toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .toolbar-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .toolbar-head p {
            color: var(--muted);
            line-height: 1.6;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        .field label {
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }

        .field select,
        .field button,
        .field a {
            min-height: 46px;
            border-radius: 14px;
            font: inherit;
        }

        .field select {
            width: 100%;
            border: 1px solid var(--border);
            background: var(--panel-strong);
            color: var(--ink);
            padding: 0 14px;
        }

        .field button,
        .field a {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            border: 0;
            text-decoration: none;
            font-weight: 800;
            cursor: pointer;
        }

        .field button {
            background: var(--primary);
            color: #ffffff;
        }

        .field a {
            background: var(--accent-soft);
            color: #8a5d00;
            border: 1px solid rgba(227, 160, 24, 0.24);
        }

        .inline-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 800;
            color: var(--primary);
            background: rgba(19, 111, 99, 0.08);
            border: 1px solid rgba(19, 111, 99, 0.18);
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .metric {
            padding: 18px;
            border-radius: 22px;
            background: var(--panel-strong);
            border: 1px solid rgba(20, 33, 29, 0.08);
        }

        .metric:nth-child(1) {
            background: linear-gradient(180deg, rgba(19, 111, 99, 0.08), rgba(255, 255, 255, 0.98));
        }

        .metric:nth-child(2) {
            background: linear-gradient(180deg, rgba(227, 160, 24, 0.09), rgba(255, 255, 255, 0.98));
        }

        .metric:nth-child(3) {
            background: linear-gradient(180deg, rgba(47, 133, 90, 0.09), rgba(255, 255, 255, 0.98));
        }

        .metric:nth-child(4) {
            background: linear-gradient(180deg, rgba(20, 33, 29, 0.06), rgba(255, 255, 255, 0.98));
        }

        .metric .label {
            color: var(--muted);
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .metric .value {
            margin-top: 10px;
            font-size: clamp(1.9rem, 3vw, 2.7rem);
            line-height: 0.98;
        }

        .metric .note {
            margin-top: 10px;
            color: var(--muted);
            line-height: 1.55;
        }

        .charts {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 16px;
        }

        .chart-card {
            grid-column: span 6;
            padding: 18px;
            border-radius: 24px;
            background: var(--panel-strong);
            border: 1px solid rgba(20, 33, 29, 0.08);
        }

        .chart-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .chart-head p {
            margin-top: 8px;
            color: var(--muted);
            line-height: 1.55;
        }

        .tag {
            display: inline-flex;
            align-items: center;
            padding: 8px 10px;
            border-radius: 999px;
            background: rgba(19, 111, 99, 0.08);
            color: var(--primary);
            font-size: 0.8rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .canvas-wrap {
            position: relative;
            min-height: 300px;
        }

        .table-card {
            padding: 18px;
        }

        .table-shell {
            overflow: auto;
            border-radius: 22px;
            border: 1px solid rgba(20, 33, 29, 0.08);
            background: rgba(255, 255, 255, 0.95);
        }

        table {
            width: 100%;
            min-width: 820px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(20, 33, 29, 0.08);
        }

        th {
            position: sticky;
            top: 0;
            background: #12352f;
            color: #ffffff;
            z-index: 1;
        }

        tbody tr:nth-child(even) {
            background: rgba(19, 111, 99, 0.03);
        }

        tbody tr:hover {
            background: rgba(227, 160, 24, 0.08);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 800;
        }

        .status-online {
            background: rgba(47, 133, 90, 0.12);
            color: var(--success);
        }

        .status-offline {
            background: rgba(20, 33, 29, 0.1);
            color: #42534d;
        }

        .notice {
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid rgba(227, 160, 24, 0.22);
            background: rgba(255, 247, 220, 0.95);
            color: #8a5d00;
            line-height: 1.6;
        }

        .empty {
            padding: 24px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.84);
            border: 1px dashed rgba(20, 33, 29, 0.18);
            color: var(--muted);
            line-height: 1.7;
        }

        @media (max-width: 960px) {
            .hero {
                grid-template-columns: 1fr;
            }

            .metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .chart-card {
                grid-column: span 12;
            }
        }

        @media (max-width: 680px) {
            .page {
                padding: 18px 14px 30px;
            }

            .hero,
            .toolbar,
            .table-card {
                padding: 18px;
            }

            .metrics {
                grid-template-columns: 1fr;
            }

            .nav {
                padding: 0 18px 18px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="stack">
        <section class="panel">
            <div class="hero">
                <div>
                    <span class="eyebrow">SERE+ Listener Tracking</span>
                    <h1>SERE+ Listener Tracking</h1>
                    <p class="hero-copy">
                        This dashboard reads the local tracking database instead of depending on AzuraCast's temporary
                        current counters. Use the logger to capture snapshots, then rebuild monthly summaries for fast reporting.
                    </p>
                </div>
                <aside class="hero-card">
                    <div>
                        <div class="label"><?= $view === 'dashboard' ? 'Latest Snapshot' : 'Current Report' ?></div>
                        <div class="value">
                            <?= $view === 'dashboard'
                                ? listenerTrackerH(number_format($dashboardTotalListeners))
                                : listenerTrackerH(number_format($reportTotalHours, 2)) ?>
                        </div>
                    </div>
                    <div class="meta">
                        <?php if ($view === 'dashboard'): ?>
                            <?= listenerTrackerH($dashboardCapturedAtLabel) ?><br>
                            <?= listenerTrackerH((string)$dashboardStationCount) ?> stations in the latest capture.
                        <?php else: ?>
                            <?= listenerTrackerH($reportPeriodLabel) ?><br>
                            <?= listenerTrackerH(number_format($reportTotalMinutes)) ?> listener-minutes rolled up.
                        <?php endif; ?>
                    </div>
                </aside>
            </div>

            <nav class="nav">
                <a class="<?= $view === 'dashboard' ? 'active' : '' ?>" href="monthly.php?view=dashboard">Live Snapshot Dashboard</a>
                <a class="<?= $view === 'report' ? 'active' : '' ?>" href="monthly.php?view=report&year=<?= listenerTrackerH((string)$selectedYear) ?>&month=<?= listenerTrackerH($showAllMonths ? 'all' : str_pad((string)$selectedMonth, 2, '0', STR_PAD_LEFT)) ?>">Monthly Reports</a>
            </nav>
        </section>

        <?php if ($error !== null): ?>
            <div class="notice">The page could not load tracker data: <?= listenerTrackerH($error) ?></div>
        <?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <section class="panel toolbar">
                <div class="toolbar-head">
                    <div>
                        <h2><?= listenerTrackerH($autoRefreshLabel) ?></h2>
                        <p><?= listenerTrackerH($autoRefreshNote) ?></p>
                    </div>
                    <div class="toolbar-actions">
                        <a class="inline-action" href="monthly.php?<?= listenerTrackerH($autoRefreshToggleQuery) ?>">
                            <?= listenerTrackerH($autoRefreshActionLabel) ?>
                        </a>
                    </div>
                </div>
            </section>

            <section class="metrics">
                <article class="metric panel">
                    <div class="label">Total Current Listeners</div>
                    <div class="value"><?= listenerTrackerH(number_format($dashboardTotalListeners)) ?></div>
                    <div class="note">Combined listeners across the most recent snapshot stored in MySQL.</div>
                </article>
                <article class="metric panel">
                    <div class="label">Unique Listeners</div>
                    <div class="value"><?= listenerTrackerH(number_format($dashboardUniqueListeners)) ?></div>
                    <div class="note">Summed from the latest station rows returned by the 3-minute logger.</div>
                </article>
                <article class="metric panel">
                    <div class="label">Top Station</div>
                    <div class="value"><?= listenerTrackerH($dashboardTopStation) ?></div>
                    <div class="note"><?= listenerTrackerH(number_format($dashboardTopValue)) ?> current listeners lead the pack right now.</div>
                </article>
                <article class="metric panel">
                    <div class="label">Last Captured</div>
                    <div class="value"><?= listenerTrackerH($dashboardCapturedAtLabel) ?></div>
                    <div class="note">Display timezone: <?= listenerTrackerH($config['app']['default_timezone']) ?>.</div>
                </article>
            </section>

            <?php if ($dashboardRows): ?>
                <section class="charts">
                    <article class="chart-card panel">
                        <div class="chart-head">
                            <div>
                                <h3>Network Trend</h3>
                                <p>The last 32 captured totals show how the network moved over the recent snapshot window.</p>
                            </div>
                            <span class="tag">Recent totals</span>
                        </div>
                        <div class="canvas-wrap">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </article>

                    <article class="chart-card panel">
                        <div class="chart-head">
                            <div>
                                <h3>Current Station Share</h3>
                                <p>The leading stations from the newest snapshot, with smaller stations still visible in the table below.</p>
                            </div>
                            <span class="tag">Top 8 stations</span>
                        </div>
                        <div class="canvas-wrap">
                            <canvas id="stationShareChart"></canvas>
                        </div>
                    </article>
                </section>

                <section class="panel table-card">
                    <div class="toolbar-head">
                        <div>
                            <h2>Latest Snapshot Rows</h2>
                            <p>These rows are pulled from `listener_snapshots` at the newest `captured_at_utc` value.</p>
                        </div>
                    </div>
                    <div class="table-shell">
                        <table>
                            <thead>
                            <tr>
                                <th>Station</th>
                                <th>Current</th>
                                <th>Unique</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Now Playing</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($dashboardRows as $row): ?>
                                <tr>
                                    <td><?= listenerTrackerH($row['station_name']) ?></td>
                                    <td><?= listenerTrackerH(number_format((int)$row['listeners_current'])) ?></td>
                                    <td><?= listenerTrackerH(number_format((int)$row['listeners_unique'])) ?></td>
                                    <td><?= listenerTrackerH(number_format((int)$row['listeners_total'])) ?></td>
                                    <td>
                                        <span class="status-pill <?= (int)$row['is_online'] === 1 ? 'status-online' : 'status-offline' ?>">
                                            <?= (int)$row['is_online'] === 1 ? 'Online' : 'Offline' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= listenerTrackerH($row['now_playing_artist'] ?: 'Unknown artist') ?>
                                        -
                                        <?= listenerTrackerH($row['now_playing_title'] ?: 'No track title') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php else: ?>
                <div class="empty">
                    No snapshots have been logged yet. Run `listenership_log_job.php` first, then refresh this page.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <section class="panel toolbar">
                <div class="toolbar-head">
                    <div>
                        <h2>Monthly Summary Filters</h2>
                        <p>Use one month for a station ranking or switch to `All Months` for a yearly roll-up from `listener_monthly_summary`.</p>
                    </div>
                </div>
                <form method="get" class="filter-grid">
                    <input type="hidden" name="view" value="report">
                    <div class="field">
                        <label for="year">Year</label>
                        <select id="year" name="year">
                            <?php foreach ($availableYears as $yearOption): ?>
                                <option value="<?= listenerTrackerH((string)$yearOption) ?>" <?= $yearOption === $selectedYear ? 'selected' : '' ?>>
                                    <?= listenerTrackerH((string)$yearOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="month">Month</label>
                        <select id="month" name="month">
                            <option value="all" <?= $showAllMonths ? 'selected' : '' ?>>All Months</option>
                            <?php for ($monthOption = 1; $monthOption <= 12; $monthOption++): ?>
                                <?php $monthValue = str_pad((string)$monthOption, 2, '0', STR_PAD_LEFT); ?>
                                <option value="<?= listenerTrackerH($monthValue) ?>" <?= !$showAllMonths && $selectedMonth === $monthOption ? 'selected' : '' ?>>
                                    <?= listenerTrackerH((new DateTimeImmutable(sprintf('2000-%s-01', $monthValue)))->format('F')) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>&nbsp;</label>
                        <button type="submit">Load Report</button>
                    </div>
                    <div class="field">
                        <label>&nbsp;</label>
                        <a href="monthly.php?<?= listenerTrackerH($downloadQuery) ?>">Download CSV</a>
                    </div>
                </form>
            </section>

            <section class="metrics">
                <article class="metric panel">
                    <div class="label">Listener Hours</div>
                    <div class="value"><?= listenerTrackerH(number_format($reportTotalHours, 2)) ?></div>
                    <div class="note">Calculated from snapshot counts and the configured <?= listenerTrackerH((string)$config['app']['snapshot_interval_minutes']) ?>-minute interval.</div>
                </article>
                <article class="metric panel">
                    <div class="label">Snapshots Counted</div>
                    <div class="value"><?= listenerTrackerH(number_format($reportTotalSnapshots)) ?></div>
                    <div class="note">Every summary row is built from `listener_snapshots` within the selected UTC month range.</div>
                </article>
                <article class="metric panel">
                    <div class="label">Top Station</div>
                    <div class="value"><?= listenerTrackerH($reportTopStation) ?></div>
                    <div class="note"><?= listenerTrackerH(number_format($reportTopHours, 2)) ?> listener-hours across the selected period.</div>
                </article>
                <article class="metric panel">
                    <div class="label">Coverage</div>
                    <div class="value"><?= listenerTrackerH((string)$reportStationCount) ?> stations</div>
                    <div class="note"><?= listenerTrackerH((string)$reportCoverageCount) ?> month<?= $reportCoverageCount === 1 ? '' : 's' ?> represented, with a peak snapshot of <?= listenerTrackerH(number_format($reportPeakListeners)) ?> listeners.</div>
                </article>
            </section>

            <?php if ($summaryRows): ?>
                <section class="charts">
                    <article class="chart-card panel">
                        <div class="chart-head">
                            <div>
                                <h3><?= listenerTrackerH($periodChartTitle) ?></h3>
                                <p><?= $showAllMonths
                                        ? 'Monthly totals make annual patterns easier to read without scanning the raw snapshot table.'
                                        : 'Top stations for the selected month ranked by accumulated listener-hours.' ?></p>
                            </div>
                            <span class="tag"><?= listenerTrackerH($periodChartTag) ?></span>
                        </div>
                        <div class="canvas-wrap">
                            <canvas id="periodChart"></canvas>
                        </div>
                    </article>

                    <article class="chart-card panel">
                        <div class="chart-head">
                            <div>
                                <h3>Station Share</h3>
                                <p>A quick view of who owns the biggest slice of listener-hours across the selected period.</p>
                            </div>
                            <span class="tag">Top 8 stations</span>
                        </div>
                        <div class="canvas-wrap">
                            <canvas id="reportShareChart"></canvas>
                        </div>
                    </article>
                </section>

                <section class="panel table-card">
                    <div class="toolbar-head">
                        <div>
                            <h2><?= listenerTrackerH($reportPeriodLabel) ?></h2>
                            <p>The dashboard reads precomputed monthly summaries, so this table stays fast even as raw snapshots grow.</p>
                        </div>
                    </div>
                    <div class="table-shell">
                        <table>
                            <thead>
                            <tr>
                                <th>Month</th>
                                <th>Station</th>
                                <th>Unique Listeners</th>
                                <th>Minimum</th>
                                <th>Listener Minutes</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($summaryRows as $row): ?>
                                <tr>
                                    <td><?= listenerTrackerH(listenerTrackerFormatYearMonthLabel($row['report_month'])) ?></td>
                                    <td><?= listenerTrackerH($row['station_name']) ?></td>
                                    <td><?= listenerTrackerH(number_format((int)$row['unique_listeners'])) ?></td>
                                    <td><?= listenerTrackerH(number_format((float)$row['avg_listeners'], 2)) ?></td>
                                    <td><?= listenerTrackerH(number_format((int)$row['min_listeners'])) ?></td>
                                    <td><?= listenerTrackerH(number_format((int)$row['listener_minutes'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php else: ?>
                <div class="empty">
                    No summary rows are available for this period yet. Run `generate_monthly_summary.php` after the logger has collected snapshots.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    const chartTheme = {
        ink: '#2b3a35',
        grid: 'rgba(20, 33, 29, 0.12)',
        green: '#136f63',
        greenSoft: 'rgba(19, 111, 99, 0.18)',
        amber: '#e3a018',
        amberSoft: '#f2c14e',
        forest: '#2f855a'
    };

    const trendLabels = <?= json_encode($trendChartLabels, $jsonFlags) ?>;
    const trendData = <?= json_encode($trendChartData, $jsonFlags) ?>;
    const dashboardLabels = <?= json_encode($dashboardChartLabels, $jsonFlags) ?>;
    const dashboardData = <?= json_encode($dashboardChartData, $jsonFlags) ?>;
    const periodLabels = <?= json_encode($periodChartLabels, $jsonFlags) ?>;
    const periodData = <?= json_encode($periodChartData, $jsonFlags) ?>;
    const stationShareLabels = <?= json_encode($stationShareLabels, $jsonFlags) ?>;
    const stationShareData = <?= json_encode($stationShareData, $jsonFlags) ?>;

    const trendCanvas = document.getElementById('trendChart');
    if (trendCanvas && trendLabels.length) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Total listeners',
                    data: trendData,
                    borderColor: chartTheme.green,
                    backgroundColor: chartTheme.greenSoft,
                    fill: true,
                    tension: 0.28,
                    pointRadius: 3,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { color: chartTheme.ink, maxRotation: 0, autoSkip: true },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: chartTheme.ink },
                        grid: { color: chartTheme.grid }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { mode: 'index', intersect: false }
                }
            }
        });
    }

    const stationShareCanvas = document.getElementById('stationShareChart');
    if (stationShareCanvas && dashboardLabels.length) {
        new Chart(stationShareCanvas, {
            type: 'doughnut',
            data: {
                labels: dashboardLabels,
                datasets: [{
                    data: dashboardData,
                    backgroundColor: ['#136f63', '#2f855a', '#58a47a', '#e3a018', '#f2c14e', '#1f9d8a', '#12352f', '#a5b8af'],
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: chartTheme.ink, boxWidth: 12, padding: 12, usePointStyle: true }
                    }
                }
            }
        });
    }

    const periodCanvas = document.getElementById('periodChart');
    if (periodCanvas && periodLabels.length) {
        new Chart(periodCanvas, {
            type: 'bar',
            data: {
                labels: periodLabels,
                datasets: [{
                    label: 'Listener hours',
                    data: periodData,
                    backgroundColor: periodLabels.map((_, index) => index % 2 === 0 ? chartTheme.green : chartTheme.amber),
                    borderRadius: 14,
                    borderSkipped: false,
                    barThickness: 34
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { color: chartTheme.ink, maxRotation: 0, autoSkip: true },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: chartTheme.ink },
                        grid: { color: chartTheme.grid }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    const reportShareCanvas = document.getElementById('reportShareChart');
    if (reportShareCanvas && stationShareLabels.length) {
        new Chart(reportShareCanvas, {
            type: 'doughnut',
            data: {
                labels: stationShareLabels,
                datasets: [{
                    data: stationShareData,
                    backgroundColor: ['#136f63', '#2f855a', '#58a47a', '#e3a018', '#f2c14e', '#1f9d8a', '#12352f', '#a5b8af'],
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: chartTheme.ink, boxWidth: 12, padding: 12, usePointStyle: true }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed + ' listener-hours';
                            }
                        }
                    }
                }
            }
        });
    }
</script>
</body>
</html>
