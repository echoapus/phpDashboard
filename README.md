# SYSBOARD

A single-file PHP system dashboard for Linux.

This project is intentionally simple:

- one file: `dashboard.php`
- no API endpoint
- no JavaScript fetch loop
- no framework
- server-rendered HTML on each page load

The page refreshes itself every 15 seconds and also provides a manual refresh link.

## Features

SYSBOARD shows:

- disk usage
- inode usage
- memory usage
- swap usage
- CPU usage per core
- uptime and load average
- boot time
- network traffic
- TCP connection summary
- listening ports
- disk I/O
- temperatures when available
- top processes by CPU
- top processes by memory

## Requirements

| Component | Minimum |
|---|---|
| PHP | 7.4+ |
| OS | Linux |
| Web server | Apache, Nginx, lighttpd, or PHP built-in server |

Notes:

- The dashboard reads Linux-specific files such as `/proc/stat`, `/proc/net/dev`, `/proc/meminfo`, and `/proc/diskstats`.
- It will not work correctly on Windows or macOS.
- Some metrics depend on local tools like `ss`, `ps`, and `df`.
- Temperature data may be unavailable on VMs or systems without exposed thermal sensors.

## Installation

### Apache

```bash
sudo cp dashboard.php /var/www/html/dashboard.php
```

Open:

```text
http://YOUR_SERVER/dashboard.php
```

### Nginx

Place `dashboard.php` in your web root and make sure PHP-FPM is configured for `.php` files.

### lighttpd

Place `dashboard.php` in your web root and enable PHP support.

### Local test

```bash
php -S 127.0.0.1:8000
```

Then open:

```text
http://127.0.0.1:8000/dashboard.php
```

## How It Works

`dashboard.php` collects system metrics during the request, then renders the full HTML page directly.

There is no split between page rendering and metrics collection. Every refresh is a normal page request.

High-level flow:

```text
Browser requests dashboard.php
        |
        v
PHP collects system data
  - /proc/mounts
  - /proc/meminfo
  - /proc/stat
  - /proc/net/dev
  - /proc/diskstats
  - /sys/class/thermal/*
  - ss / ps / df
        |
        v
PHP renders HTML
        |
        v
Browser reloads every 15s
```

CPU, network traffic, and disk I/O are sampled twice with a 1 second interval so the dashboard can calculate rates instead of only cumulative counters.

## Design Goals

The current implementation is optimized for:

- easy deployment
- low conceptual overhead
- one-file maintenance
- straightforward debugging

It is not optimized for:

- multi-user scale
- historical metric storage
- push updates
- authentication or role separation

## Important Behavior

### Refresh model

The page uses:

- `<meta http-equiv="refresh" content="15">`
- a manual refresh link

That means the entire page reloads every 15 seconds.

### Data sampling

CPU, network throughput, and disk I/O require two samples. The page waits about 1 second during rendering to calculate those values.

### Missing metrics

Some cards may show fallback text such as:

- `No sensors`
- `No port data`
- `No active TCP connections`

That is expected when the host does not expose that data or the required command is unavailable.

## Security

This dashboard exposes host-level operational data.

Do not publish it directly to the public internet without access control.

At minimum, put it behind one of:

- HTTP Basic Auth
- VPN
- reverse-proxy authentication
- internal-only network access

## Files

```text
.
├── dashboard.php
└── README.md
```

## Validation

Basic local checks:

```bash
php -l dashboard.php
php dashboard.php > /tmp/dashboard.html
```

## License

Add your preferred license if you plan to distribute this publicly.
