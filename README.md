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

## Report model

- Raw data is stored in `listener_snapshots`
- Station metadata is stored in `azuracast_stations`
- Fast monthly reporting reads from `listener_monthly_summary`

`listener_hours` is calculated from the configured snapshot interval, which defaults to 3 minutes.
