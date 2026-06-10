# AzuraCast Listener Tracker

This project stores 3-minute AzuraCast listener snapshots in MySQL and builds monthly summary reports from those snapshots.

## What it includes

- `monthly.php` for the web dashboard and monthly report UI
- `listenership_log_job.php` for the 3-minute snapshot logger
- `generate_monthly_summary.php` for rebuilding monthly summaries
- `monthly_stats_lib.php` for shared config, API, DB, and reporting helpers
- `schema.sql` for the database schema
- `config.example.php` as the starting config file

Compatibility aliases are also included:

- `dashboard.php` loads the dashboard view
- `log_azuracast_listeners.php` loads the logger job
- `monthly_stats_job.php` loads the monthly summary generator

## Setup

1. Copy `config.example.php` to `config.php` and fill in your database settings plus AzuraCast API key.
2. Import `schema.sql` into your MySQL or MariaDB database, or let the scripts create the tables automatically.
3. Run the logger every 3 minutes.
4. Open `monthly.php` in your browser.

## Example commands

```powershell
Copy-Item config.example.php config.php
& 'C:\xampp\php\php.exe' listenership_log_job.php --refresh-stations=1
& 'C:\xampp\php\php.exe' generate_monthly_summary.php --year=2026 --month=06
```

To backfill an older month from AzuraCast's retained listener-history data:

```powershell
& 'C:\xampp\php\php.exe' generate_monthly_summary.php --year=2026 --month=04 --source=azuracast-history
```

Example cron entry on Linux:

```cron
*/3 * * * * /usr/bin/php /path/to/listenership_log_job.php >> /path/to/logs/azuracast_listener_cron.log 2>&1
15 * * * * /usr/bin/php /path/to/generate_monthly_summary.php --year=$(date +\%Y) --month=$(date +\%m)
```

## Report model

- Raw data is stored in `listener_snapshots`
- Station metadata is stored in `azuracast_stations`
- Fast monthly reporting reads from `listener_monthly_summary`

`listener_hours` is calculated from the configured snapshot interval, which defaults to 3 minutes.
