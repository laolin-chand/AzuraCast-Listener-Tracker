<?php
/** Listener Tracker — config, AzuraCast API client, DB schema/queries, helpers */

function listenerTrackerLoadConfig(): array {
    static $config = null;
    if ($config !== null) return $config;

    $defaults = [
        'db' => [
            'host' => getenv('REPORTS_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('REPORTS_DB_PORT') !== false ? (int)getenv('REPORTS_DB_PORT') : 3306,
            'name' => getenv('REPORTS_DB_NAME') ?: 'azuracast_reports',
            'user' => getenv('REPORTS_DB_USER') ?: 'root',
            'pass' => getenv('REPORTS_DB_PASS') ?: '',
        ],
        'azuracast' => [
            'base_url' => getenv('AZURACAST_BASE_URL') ?: 'https://azuracast.sere.plus/api',
            'api_key' => getenv('AZURACAST_API_KEY') ?: '',
            'timeout_seconds' => getenv('AZURACAST_TIMEOUT_SECONDS') !== false ? (int)getenv('AZURACAST_TIMEOUT_SECONDS') : 20,
        ],
        'app' => [
            'default_timezone' => getenv('REPORTS_TIMEZONE') ?: 'Pacific/Fiji',
            'snapshot_interval_minutes' => getenv('SNAPSHOT_INTERVAL_MINUTES') !== false ? (int)getenv('SNAPSHOT_INTERVAL_MINUTES') : 3,
        ],
    ];

    $config = $defaults;
    $configFile = __DIR__ . '/config.php';
    if (is_file($configFile)) {
        $loaded = require $configFile;
        if (!is_array($loaded)) throw new RuntimeException('config.php must return an array.');
        $config = array_replace_recursive($defaults, $loaded);
    }

    // Normalize values
    $config['azuracast']['base_url'] = rtrim((string)$config['azuracast']['base_url'], '/');
    $config['azuracast']['timeout_seconds'] = max(5, (int)$config['azuracast']['timeout_seconds']);
    $config['app']['default_timezone'] = (string)($config['app']['default_timezone'] ?? 'Pacific/Fiji');
    $config['app']['snapshot_interval_minutes'] = max(1, (int)$config['app']['snapshot_interval_minutes']);

    return $config;
}

function listenerTrackerGetPdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $db = listenerTrackerLoadConfig()['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int)$db['port'], $db['name']);

    return $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function listenerTrackerGetAzuraCastApiConfig(): array {
    $azuracast = listenerTrackerLoadConfig()['azuracast'];
    if (trim((string)$azuracast['api_key']) === '') {
        throw new RuntimeException('AzuraCast API key is required in config.php or AZURACAST_API_KEY.');
    }
    return [
        'base_url' => $azuracast['base_url'],
        'api_key' => (string)$azuracast['api_key'],
        'timeout_seconds' => (int)$azuracast['timeout_seconds'],
    ];
}

/** Read a CLI flag (--name / --name=val) or GET param, with default */
function listenerTrackerReadOption(string $name, $default = null) {
    if (PHP_SAPI === 'cli') {
        global $argv;
        $args = is_array($argv ?? null) ? array_slice($argv, 1) : [];
        $flag = '--' . $name;
        foreach ($args as $arg) {
            if ($arg === $flag) return true;
            if (strpos($arg, $flag . '=') === 0) return substr($arg, strlen($flag) + 1);
        }
        return $default;
    }
    return $_GET[$name] ?? $default;
}

/** GET a URL, decode JSON, throw on transport/HTTP/JSON error */
function listenerTrackerHttpGetJson(string $url, string $apiKey = '', int $timeoutSeconds = 20): array {
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Unable to initialize cURL.');

    $headers = ['Accept: application/json'];
    if ($apiKey !== '') $headers[] = 'X-API-Key: ' . $apiKey;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => max(5, $timeoutSeconds),
        CURLOPT_TIMEOUT => max(5, $timeoutSeconds),
        CURLOPT_USERAGENT => 'sere-listener-tracker/1.0',
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('AzuraCast API request failed: ' . $error);
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($statusCode < 200 || $statusCode >= 300) throw new RuntimeException('AzuraCast API returned HTTP ' . $statusCode . '.');

    try {
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('AzuraCast API returned invalid JSON: ' . $e->getMessage());
    }

    return is_array($decoded) ? $decoded : [];
}

/** AzuraCast API: GET /nowplaying — all stations' current status */
function listenerTrackerFetchNowPlayingAll(): array {
    $c = listenerTrackerGetAzuraCastApiConfig();
    $rows = listenerTrackerHttpGetJson($c['base_url'] . '/nowplaying', $c['api_key'], $c['timeout_seconds']);
    return is_array($rows) && array_is_list($rows) ? $rows : [];
}

/** AzuraCast API: GET /stations — station catalog */
function listenerTrackerFetchStationsCatalog(): array {
    $c = listenerTrackerGetAzuraCastApiConfig();
    $rows = listenerTrackerHttpGetJson($c['base_url'] . '/stations', $c['api_key'], $c['timeout_seconds']);
    return is_array($rows) && array_is_list($rows) ? $rows : [];
}

/** AzuraCast API: GET /station/{id}/listeners?start=&end= — raw listener session history */
function listenerTrackerFetchStationListenerHistory(int $stationId, DateTimeImmutable $start, DateTimeImmutable $end): array {
    $c = listenerTrackerGetAzuraCastApiConfig();
    $query = http_build_query(['start' => $start->format('c'), 'end' => $end->format('c')]);
    $rows = listenerTrackerHttpGetJson($c['base_url'] . '/station/' . $stationId . '/listeners?' . $query, $c['api_key'], $c['timeout_seconds']);
    return is_array($rows) && array_is_list($rows) ? $rows : [];
}

// ---- Scalar/string helpers ----

function listenerTrackerTrimmedString($value): string {
    return is_scalar($value) ? trim((string)$value) : '';
}

function listenerTrackerSlice(string $value, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function listenerTrackerStringOrNull($value, int $length = 65535): ?string {
    $string = listenerTrackerTrimmedString($value);
    return $string === '' ? null : listenerTrackerSlice($string, $length);
}

function listenerTrackerStringOrFallback($value, int $length, string $fallback): string {
    return listenerTrackerStringOrNull($value, $length) ?? listenerTrackerSlice($fallback, $length);
}

/** Coerce loose truthy/falsy values (bool, numeric, "yes"/"no" etc.) to 0/1 */
function listenerTrackerToBoolInt($value, int $default = 1): int {
    if ($value === null) return $default;
    if (is_bool($value)) return $value ? 1 : 0;
    if (is_numeric($value)) return ((int)$value) === 0 ? 0 : 1;

    $normalized = strtolower(trim((string)$value));
    if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) return 0;
    if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) return 1;
    return $default;
}

// ---- Normalization (API payload -> DB row) ----

/** Normalize an AzuraCast station payload into azuracast_stations row shape */
function listenerTrackerNormalizeStation(array $payload): array {
    $source = isset($payload['station']) && is_array($payload['station']) ? $payload['station'] : $payload;
    $stationId = max(0, (int)($source['id'] ?? $payload['id'] ?? 0));
    if ($stationId === 0) throw new InvalidArgumentException('Station payload is missing a valid id.');

    $defaultTz = (string)listenerTrackerLoadConfig()['app']['default_timezone'];

    return [
        'station_id' => $stationId,
        'shortcode' => listenerTrackerStringOrFallback($source['shortcode'] ?? null, 100, 'station-' . $stationId),
        'name' => listenerTrackerStringOrFallback($source['name'] ?? null, 150, 'Station ' . $stationId),
        'description' => listenerTrackerStringOrNull($source['description'] ?? null),
        'listen_url' => listenerTrackerStringOrNull($source['listen_url'] ?? ($payload['listen_url'] ?? null)),
        'public_player_url' => listenerTrackerStringOrNull($source['public_player_url'] ?? ($payload['public_player_url'] ?? null)),
        'frontend' => listenerTrackerStringOrNull($source['frontend'] ?? ($source['frontend_type'] ?? null), 50),
        'backend' => listenerTrackerStringOrNull($source['backend'] ?? ($source['backend_type'] ?? null), 50),
        'timezone' => listenerTrackerStringOrFallback($source['timezone'] ?? null, 100, $defaultTz),
        'is_public' => listenerTrackerToBoolInt($source['is_public'] ?? ($source['public'] ?? 1), 1),
        'is_enabled' => listenerTrackerToBoolInt($source['is_enabled'] ?? ($source['enabled'] ?? 1), 1),
    ];
}

/** Normalize a /nowplaying entry into a listener_snapshots row, tagged with $capturedAtUtc */
function listenerTrackerNormalizeSnapshot(array $payload, DateTimeImmutable $capturedAtUtc): array {
    $station = listenerTrackerNormalizeStation($payload);
    $listeners = $payload['listeners'] ?? [];
    $nowPlaying = $payload['now_playing'] ?? [];
    $song = $nowPlaying['song'] ?? [];
    $listeners = is_array($listeners) ? $listeners : [];
    $nowPlaying = is_array($nowPlaying) ? $nowPlaying : [];
    $song = is_array($song) ? $song : [];

    return [
        'station_id' => $station['station_id'],
        'captured_at_utc' => $capturedAtUtc->format('Y-m-d H:i:s'),
        'listeners_current' => max(0, (int)($listeners['current'] ?? ($listeners['total'] ?? 0))),
        'listeners_unique' => max(0, (int)($listeners['unique'] ?? 0)),
        'listeners_total' => max(0, (int)($listeners['total'] ?? ($listeners['current'] ?? 0))),
        'is_online' => listenerTrackerToBoolInt($payload['is_online'] ?? 1, 1),
        'now_playing_title' => listenerTrackerStringOrNull($song['title'] ?? ($nowPlaying['text'] ?? null), 255),
        'now_playing_artist' => listenerTrackerStringOrNull($song['artist'] ?? null, 255),
    ];
}

// ---- Schema ----

/** CREATE TABLE statements: stations, raw snapshots, monthly summaries */
function listenerTrackerSchemaStatements(): array {
    return [
        <<<SQL
CREATE TABLE IF NOT EXISTS azuracast_stations (
    station_id INT UNSIGNED NOT NULL,
    shortcode VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    listen_url TEXT NULL,
    public_player_url TEXT NULL,
    frontend VARCHAR(50) NULL,
    backend VARCHAR(50) NULL,
    timezone VARCHAR(100) DEFAULT 'Pacific/Fiji',
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (station_id),
    UNIQUE KEY uq_shortcode (shortcode),
    KEY idx_station_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS listener_snapshots (
    snapshot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    station_id INT UNSIGNED NOT NULL,
    captured_at_utc DATETIME NOT NULL,
    captured_date DATE GENERATED ALWAYS AS (DATE(captured_at_utc)) STORED,
    listeners_current INT UNSIGNED NOT NULL DEFAULT 0,
    listeners_unique INT UNSIGNED NOT NULL DEFAULT 0,
    listeners_total INT UNSIGNED NOT NULL DEFAULT 0,
    is_online TINYINT(1) NOT NULL DEFAULT 1,
    now_playing_title VARCHAR(255) NULL,
    now_playing_artist VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (snapshot_id),
    UNIQUE KEY uq_station_snapshot_time (station_id, captured_at_utc),
    KEY idx_captured_at (captured_at_utc),
    KEY idx_station_time (station_id, captured_at_utc),
    KEY idx_date_station (captured_date, station_id),
    CONSTRAINT fk_snapshots_station FOREIGN KEY (station_id) REFERENCES azuracast_stations(station_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS listener_monthly_summary (
    station_id INT UNSIGNED NOT NULL,
    month_key CHAR(7) NOT NULL,
    snapshots_count INT UNSIGNED NOT NULL DEFAULT 0,
    unique_listeners INT UNSIGNED NOT NULL DEFAULT 0,
    avg_listeners DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    peak_listeners INT UNSIGNED NOT NULL DEFAULT 0,
    min_listeners INT UNSIGNED NOT NULL DEFAULT 0,
    listener_minutes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    listener_hours DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (station_id, month_key),
    KEY idx_month_key (month_key),
    CONSTRAINT fk_monthly_station FOREIGN KEY (station_id) REFERENCES azuracast_stations(station_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];
}

/** Create tables if missing, then apply incremental column migrations */
function listenerTrackerEnsureSchema(PDO $pdo): void {
    foreach (listenerTrackerSchemaStatements() as $statement) $pdo->exec($statement);
    listenerTrackerEnsureColumn($pdo, 'listener_monthly_summary', 'unique_listeners', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER snapshots_count');
}

/** Add $column to $table via ALTER TABLE if it doesn't already exist */
function listenerTrackerEnsureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name LIMIT 1'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    if ($stmt->fetch()) return;

    $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', str_replace('`', '``', $table), str_replace('`', '``', $column), $definition));
}

// ---- Writes ----

/** Insert/update a station row (upsert by station_id) */
function listenerTrackerUpsertStation(PDO $pdo, array $station): void {
    static $stmt = null;
    $stmt ??= $pdo->prepare(
        'INSERT INTO azuracast_stations (
            station_id, shortcode, name, description, listen_url, public_player_url,
            frontend, backend, timezone, is_public, is_enabled
        ) VALUES (
            :station_id, :shortcode, :name, :description, :listen_url, :public_player_url,
            :frontend, :backend, :timezone, :is_public, :is_enabled
        )
        ON DUPLICATE KEY UPDATE
            shortcode = VALUES(shortcode), name = VALUES(name), description = VALUES(description),
            listen_url = VALUES(listen_url), public_player_url = VALUES(public_player_url),
            frontend = VALUES(frontend), backend = VALUES(backend), timezone = VALUES(timezone),
            is_public = VALUES(is_public), is_enabled = VALUES(is_enabled), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute($station);
}

/** Insert/update a listener snapshot row (upsert by station_id + captured_at_utc) */
function listenerTrackerInsertSnapshot(PDO $pdo, array $snapshot): void {
    static $stmt = null;
    $stmt ??= $pdo->prepare(
        'INSERT INTO listener_snapshots (
            station_id, captured_at_utc, listeners_current, listeners_unique, listeners_total,
            is_online, now_playing_title, now_playing_artist
        ) VALUES (
            :station_id, :captured_at_utc, :listeners_current, :listeners_unique, :listeners_total,
            :is_online, :now_playing_title, :now_playing_artist
        )
        ON DUPLICATE KEY UPDATE
            listeners_current = VALUES(listeners_current), listeners_unique = VALUES(listeners_unique),
            listeners_total = VALUES(listeners_total), is_online = VALUES(is_online),
            now_playing_title = VALUES(now_playing_title), now_playing_artist = VALUES(now_playing_artist)'
    );
    $stmt->execute($snapshot);
}

// ---- Time helpers ----

/** Round $timestamp down to the nearest $intervalMinutes bucket, in UTC */
function listenerTrackerBucketUtcTimestamp(DateTimeImmutable $timestamp, int $intervalMinutes): DateTimeImmutable {
    $utc = $timestamp->setTimezone(new DateTimeZone('UTC'));
    $bucketMinute = intdiv((int)$utc->format('i'), max(1, $intervalMinutes)) * max(1, $intervalMinutes);
    return $utc->setTime((int)$utc->format('H'), $bucketMinute, 0);
}

/** Build a DateTimeZone, falling back to $fallbackTimezone if $timezoneName is invalid */
function listenerTrackerResolveTimezone(string $timezoneName, string $fallbackTimezone = 'UTC'): DateTimeZone {
    try {
        return new DateTimeZone($timezoneName);
    } catch (Throwable $e) {
        return new DateTimeZone($fallbackTimezone);
    }
}

/** UTC start/end DateTimeImmutables for a calendar month, plus its 'Y-m' key */
function listenerTrackerMonthRangeUtc(int $year, int $month): array {
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), new DateTimeZone('UTC'));
    return ['year_month' => $start->format('Y-m'), 'start' => $start, 'end' => $start->modify('first day of next month')];
}

/** Same as listenerTrackerMonthRangeUtc but anchored to a given timezone */
function listenerTrackerMonthRangeForTimezone(int $year, int $month, DateTimeZone $timezone): array {
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $timezone);
    return ['year_month' => $start->format('Y-m'), 'start' => $start, 'end' => $start->modify('first day of next month')];
}

// ---- History summarization ----

/**
 * Summarize raw listener session history into monthly stats:
 * snapshot count, unique listeners, avg/peak/min concurrent listeners, listener-minutes/hours.
 * Uses a sweep-line over connect/disconnect events clipped to [$rangeStart, $rangeEnd].
 */
function listenerTrackerSummarizeHistoryRows(array $historyRows, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, int $snapshotIntervalMinutes): array {
    $rangeStartTs = $rangeStart->getTimestamp();
    $rangeEndTs = $rangeEnd->getTimestamp();
    $rangeSeconds = max(1, $rangeEndTs - $rangeStartTs);
    $intervalSeconds = max(60, $snapshotIntervalMinutes * 60);
    $events = [];
    $totalListenerSeconds = 0;

    // Build connect(+1)/disconnect(-1) events clipped to the range
    foreach ($historyRows as $row) {
        if (!is_array($row)) continue;

        $connectedOn = (int)($row['connected_on'] ?? 0);
        $connectedUntil = (int)($row['connected_until'] ?? 0);
        $connectedTime = (int)($row['connected_time'] ?? 0);
        if ($connectedUntil <= 0 && $connectedTime > 0) $connectedUntil = $connectedOn + $connectedTime;
        if ($connectedUntil <= $connectedOn) continue;

        $effectiveStart = max($rangeStartTs, $connectedOn);
        $effectiveEnd = min($rangeEndTs, $connectedUntil);
        if ($effectiveEnd <= $effectiveStart) continue;

        $totalListenerSeconds += ($effectiveEnd - $effectiveStart);
        $events[$effectiveStart] ??= ['start' => 0, 'end' => 0];
        $events[$effectiveEnd] ??= ['start' => 0, 'end' => 0];
        $events[$effectiveStart]['start']++;
        $events[$effectiveEnd]['end']++;
    }

    $uniqueListeners = listenerTrackerCountUniqueListeners($historyRows);

    if (!$events) {
        return [
            'snapshots_count' => (int)ceil($rangeSeconds / $intervalSeconds),
            'unique_listeners' => $uniqueListeners,
            'avg_listeners' => 0.00,
            'peak_listeners' => 0,
            'min_listeners' => 0,
            'listener_minutes' => 0,
            'listener_hours' => 0.00,
        ];
    }

    // Sweep events in time order, tracking concurrent listener count
    ksort($events, SORT_NUMERIC);
    $currentListeners = 0;
    $peakListeners = 0;
    $minListeners = null;
    $lastTs = $rangeStartTs;

    foreach ($events as $eventTs => $counts) {
        $eventTs = (int)$eventTs;
        if ($eventTs > $lastTs) {
            $peakListeners = max($peakListeners, $currentListeners);
            $minListeners = $minListeners === null ? $currentListeners : min($minListeners, $currentListeners);
            $lastTs = $eventTs;
        }
        $currentListeners += (int)($counts['start'] ?? 0) - (int)($counts['end'] ?? 0);
        $currentListeners = max(0, $currentListeners);
    }

    if ($rangeEndTs > $lastTs) {
        $peakListeners = max($peakListeners, $currentListeners);
        $minListeners = $minListeners === null ? $currentListeners : min($minListeners, $currentListeners);
    }

    return [
        'snapshots_count' => (int)ceil($rangeSeconds / $intervalSeconds),
        'unique_listeners' => $uniqueListeners,
        'avg_listeners' => round($totalListenerSeconds / $rangeSeconds, 2),
        'peak_listeners' => $peakListeners,
        'min_listeners' => $minListeners ?? 0,
        'listener_minutes' => max(0, (int)round($totalListenerSeconds / 60)),
        'listener_hours' => max(0, round($totalListenerSeconds / 3600, 2)),
    ];
}

/** Format 'YYYY-MM' as e.g. 'June 2026'; returns input unchanged if unparsable */
function listenerTrackerFormatYearMonthLabel(string $yearMonth): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth, new DateTimeZone('UTC'));
    return $date instanceof DateTimeImmutable ? $date->format('F Y') : $yearMonth;
}

/** Count distinct listeners by hash, falling back to ip|user_agent when no hash */
function listenerTrackerCountUniqueListeners(array $historyRows): int {
    $unique = [];

    foreach ($historyRows as $row) {
        if (!is_array($row)) continue;

        $hash = trim((string)($row['hash'] ?? ''));
        if ($hash !== '') {
            $unique['hash:' . $hash] = true;
            continue;
        }

        $fallback = trim((string)($row['ip'] ?? '')) . '|' . trim((string)($row['user_agent'] ?? ''));
        if ($fallback === '|') continue;
        $unique['fallback:' . $fallback] = true;
    }

    return count($unique);
}

// ---- Reads ----

/** Map of station_id => ['timezone' => ...] for all known stations */
function listenerTrackerFetchStoredStationMap(PDO $pdo): array {
    $rows = $pdo->query('SELECT station_id, timezone FROM azuracast_stations')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $stationId = (int)($row['station_id'] ?? 0);
        if ($stationId !== 0) $map[$stationId] = ['timezone' => (string)($row['timezone'] ?? '')];
    }
    return $map;
}

/** Distinct years with data, newest first; falls back to current year if none */
function listenerTrackerFetchAvailableYears(PDO $pdo): array {
    $years = [];

    $rows = $pdo->query("SELECT DISTINCT CAST(LEFT(month_key, 4) AS UNSIGNED) AS report_year FROM listener_monthly_summary ORDER BY report_year DESC")->fetchAll();
    foreach ($rows as $row) {
        $year = (int)$row['report_year'];
        if ($year > 0) $years[$year] = $year;
    }

    if (!$years) {
        $rows = $pdo->query('SELECT DISTINCT YEAR(captured_at_utc) AS report_year FROM listener_snapshots ORDER BY report_year DESC')->fetchAll();
        foreach ($rows as $row) {
            $year = (int)$row['report_year'];
            if ($year > 0) $years[$year] = $year;
        }
    }

    if (!$years) {
        $currentYear = (int)(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
        $years[$currentYear] = $currentYear;
    }

    rsort($years, SORT_NUMERIC);
    return array_values($years);
}

/** Latest snapshot per station (from the most recent captured_at_utc), ordered by listener count */
function listenerTrackerFetchLatestSnapshotRows(PDO $pdo): array {
    $latestCapturedAt = $pdo->query('SELECT MAX(captured_at_utc) AS captured_at_utc FROM listener_snapshots')->fetchColumn();
    if (!$latestCapturedAt) return [];

    $stmt = $pdo->prepare(
        'SELECT ls.station_id, COALESCE(s.name, CONCAT("Station ", ls.station_id)) AS station_name,
                ls.captured_at_utc, ls.listeners_current, ls.listeners_unique, ls.listeners_total,
                ls.is_online, ls.now_playing_title, ls.now_playing_artist
         FROM listener_snapshots ls
         LEFT JOIN azuracast_stations s ON s.station_id = ls.station_id
         WHERE ls.captured_at_utc = :captured_at_utc
         ORDER BY ls.listeners_current DESC, station_name ASC'
    );
    $stmt->execute(['captured_at_utc' => $latestCapturedAt]);
    return $stmt->fetchAll();
}

/** Total listeners across all stations for the most recent $limit snapshot timestamps */
function listenerTrackerFetchNetworkTrendRows(PDO $pdo, int $limit = 32): array {
    $limit = max(1, $limit);
    $sql = sprintf(
        'SELECT ls.captured_at_utc, SUM(ls.listeners_current) AS total_listeners
         FROM listener_snapshots ls
         INNER JOIN (
             SELECT captured_at_utc FROM listener_snapshots
             GROUP BY captured_at_utc ORDER BY captured_at_utc DESC LIMIT %d
         ) recent ON recent.captured_at_utc = ls.captured_at_utc
         GROUP BY ls.captured_at_utc
         ORDER BY ls.captured_at_utc ASC',
        $limit
    );
    return $pdo->query($sql)->fetchAll();
}

/** Monthly summary rows for $year, optionally filtered to a single $month */
function listenerTrackerFetchSummaryRows(PDO $pdo, int $year, ?int $month = null): array {
    $select = 'SELECT ms.station_id, COALESCE(s.name, CONCAT("Station ", ms.station_id)) AS station_name,
                      COALESCE(s.shortcode, CONCAT("station-", ms.station_id)) AS shortcode,
                      ms.month_key AS report_month, ms.snapshots_count, ms.unique_listeners,
                      ms.avg_listeners, ms.peak_listeners, ms.min_listeners, ms.listener_minutes, ms.listener_hours
               FROM listener_monthly_summary ms
               LEFT JOIN azuracast_stations s ON s.station_id = ms.station_id';

    if ($month === null) {
        $stmt = $pdo->prepare("$select WHERE ms.month_key LIKE :year_prefix ORDER BY ms.month_key ASC, ms.listener_hours DESC, station_name ASC");
        $stmt->execute(['year_prefix' => sprintf('%04d-', $year) . '%']);
    } else {
        $stmt = $pdo->prepare("$select WHERE ms.month_key = :year_month ORDER BY ms.listener_hours DESC, station_name ASC");
        $stmt->execute(['year_month' => sprintf('%04d-%02d', $year, $month)]);
    }

    return $stmt->fetchAll();
}

// ---- Output helpers ----

/** HTML-escape for safe output */
function listenerTrackerH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Stream $rows as a downloadable CSV with the given $header row */
function listenerTrackerOutputCsv(string $filename, array $header, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');

    $handle = fopen('php://output', 'wb');
    if ($handle === false) throw new RuntimeException('Unable to open CSV output stream.');

    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}