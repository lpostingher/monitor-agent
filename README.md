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
- **Requests**: method, route pattern, controller action, status, duration and the authenticated user's id. The URL is sent without its query string, and the `/up` health check is skipped.
- **Queries**: the SQL with its placeholders (never the bound values), duration, connection and the file and line of your code that ran it.
- **Outgoing requests** made with Laravel's `Http` client: method, URL without its query string, status and duration.
- **Artisan commands**: name (never the arguments), exit code and duration. Long-running commands such as `queue:work` and `schedule:run` are skipped.
- **Scheduled tasks**: every run with its cron expression, whether it succeeded, and its duration.
- **Queued jobs**: every attempt with its status (processed, failed or released), duration, queue and attempt number.

Every request, job and command gets a trace id, so the panel shows the logs written alongside an exception.

Values whose keys contain `password`, `token`, `secret`, `authorization`, `cookie`, `api-key` and similar words are replaced by `[redacted]` before leaving the application. Database errors are sent without their bound values, and URLs with sensitive route parameters are sent as the route pattern. Other exception messages are sent as they are.

## Impact on your application

- Events are kept in memory and sent in a single gzip request **after the response has been sent**.
- Queue workers and other long-running commands send a batch every 100 events or 10 seconds.
- If the panel is slow or unreachable the batch is dropped after a short timeout, and sending pauses for 30 seconds. The agent never throws and never blocks a response.
- Memory stays bounded: at most 1000 events or 4.5 MB are buffered at once.

Laravel Octane is not supported yet.

## Configuration

| Variable            | Default                               |                           |
| ------------------- | ------------------------------------- | ------------------------- |
| `MONITOR_URL`       |                                       | Panel URL                 |
| `MONITOR_TOKEN`     |                                       | Project ingest token      |
| `MONITOR_ENABLED`   | `true` (`false` when `APP_ENV=local`) | Turn the agent on or off  |
| `MONITOR_LOG_LEVEL` | `info`                                | Lowest log level reported |

The agent is always off while running tests. Publish the configuration file to change batch sizes, timeouts or redacted keys:

```bash
php artisan vendor:publish --tag=monitor-agent-config
```

## License

MIT
