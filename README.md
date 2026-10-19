# Monitor Agent

Reports exceptions, logs and queued jobs from a Laravel 12 or 13 application to a self-hosted [Monitor](https://github.com/lpostingher/monitor) panel.

## Installation

```bash
composer require lpostingher/monitor-agent
```

Create a project in the panel and add its URL and ingest token to the application's `.env`:

```dotenv
MONITOR_URL=https://monitor.example.com
MONITOR_TOKEN=mon_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

That's it. The service provider is discovered automatically.

## What is collected

- **Exceptions** reported by the application (respecting `dontReport`), with a stack trace relative to the project root, the request method, URL and route, the authenticated user's id, or the job being processed.
- **Logs** at `info` and above (`MONITOR_LOG_LEVEL`), with their context.
- **Queued jobs**: every attempt with its status (processed, failed or released), duration, queue and attempt number.

Every request, job and command gets a trace id, so the panel shows the logs written alongside an exception.

Values whose keys contain `password`, `token`, `secret`, `authorization`, `cookie` and similar words are replaced by `[redacted]` before leaving the application.

## Impact on your application

- Events are kept in memory and sent in a single gzip request **after the response has been sent**.
- Queue workers and other long-running commands send a batch every 100 events or 10 seconds.
- If the panel is slow or unreachable the batch is dropped after a short timeout. The agent never throws and never blocks a response.

## Configuration

| Variable | Default | |
|---|---|---|
| `MONITOR_URL` | | Panel URL |
| `MONITOR_TOKEN` | | Project ingest token |
| `MONITOR_ENABLED` | `true` (`false` when `APP_ENV=local`) | Turn the agent on or off |
| `MONITOR_LOG_LEVEL` | `info` | Lowest log level reported |

The agent is always off while running tests. Publish the configuration file to change batch sizes, timeouts or redacted keys:

```bash
php artisan vendor:publish --tag=monitor-config
```

## License

MIT
