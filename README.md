# SYSBOARD — System Dashboard

A single-file PHP system monitoring dashboard. Drop one file onto any Apache/Nginx/lighttpd server with PHP and get a real-time view of disk, memory, CPU, network traffic, running processes, and listening ports.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [How It Works](#how-it-works)
- [Dashboard Cards](#dashboard-cards)
- [Configuration & Customization](#configuration--customization)
- [Security Considerations](#security-considerations)
- [Troubleshooting](#troubleshooting)
- [File Structure Reference](#file-structure-reference)

---

## Requirements

| Component | Minimum |
|-----------|---------|
| Web server | Apache 2.4 / Nginx / lighttpd |
| PHP | 7.4 or newer |
| OS | Linux (reads `/proc/stat` and `/proc/net/dev`) |
| Browser | Any modern browser (Chrome, Firefox, Safari, Edge) |

> **Note:** This dashboard reads Linux kernel pseudo-files directly. It will **not** work on Windows or macOS servers.

---

## Installation

### Apache (Debian/Ubuntu)

```bash
# 1. Install Apache and PHP if not already present
sudo apt update
sudo apt install apache2 libapache2-mod-php -y

# 2. Enable the default site if not already enabled
sudo a2ensite 000-default
sudo systemctl reload apache2

# 3. Copy the file
sudo cp dashboard.php /var/www/html/dashboard.php

# 4. Open in browser
# http://YOUR_SERVER_IP/dashboard.php
```

### Nginx

```bash
# 1. Install Nginx and PHP-FPM
sudo apt install nginx php-fpm -y

# 2. Configure Nginx to pass .php files to PHP-FPM
# Edit /etc/nginx/sites-available/default and ensure this block exists:
#
#   location ~ \.php$ {
#       include snippets/fastcgi-php.conf;
#       fastcgi_pass unix:/run/php/php-fpm.sock;
#   }

# 3. Restart services
sudo systemctl restart nginx php-fpm

# 4. Copy the file
sudo cp dashboard.php /var/www/html/dashboard.php
```

### lighttpd

```bash
# 1. Install lighttpd and php-cgi
sudo apt install lighttpd php-cgi -y

# 2. Enable fastcgi module
sudo lighttpd-enable-mod fastcgi-php
sudo systemctl restart lighttpd

# 3. Copy the file
sudo cp dashboard.php /var/www/html/dashboard.php
```

---

## How It Works

The entire application lives in a single PHP file that serves two roles depending on how it is requested:

```
Browser                        dashboard.php (PHP)
  |                                  |
  |-- GET /dashboard.php ---------->|  Serve HTML page + JS
  |<--------------------------------|
  |
  |-- POST fetch=1 (AJAX) -------->|  Collect metrics → return JSON
  |<-- JSON { df, mem, cpu, ... } --|
  |
  |  JS renders cards from JSON
```

### Request routing

At the very top of the file, before any HTML is output, PHP checks for a POST parameter:

```php
if (isset($_POST['fetch'])) {
    header('Content-Type: application/json');
    // ... collect all metrics ...
    echo json_encode([...]);
    exit;   // <-- stop here, never reach the HTML
}
```

If `fetch` is not present, execution falls through to the HTML section and serves the page. This is why the check **must** come before any HTML — any output before `header()` will break the JSON response.

### Metric collection flow

When a POST request arrives, the following sequence runs:

```
1. parse_df()            → run `df -k`, parse each mounted filesystem
2. parse_mem()           → run `free -k`, extract total/used/free/available
3. parse_uptime()        → run `uptime`, extract uptime string and load averages
4. parse_sysinfo()       → run `uname -a`, extract hostname/kernel/arch
5. parse_procs()         → run `ps aux --sort=-%cpu`, return top 8 processes
6. parse_network_ports() → run `ss -tulnp`, return listening ports
7. read_cpu_stat()       → read /proc/stat (sample 1)
8. read_net_stat()       → read /proc/net/dev (sample 1)
   sleep(3)              → wait 3 seconds
9. read_cpu_stat()       → read /proc/stat (sample 2)
10. read_net_stat()      → read /proc/net/dev (sample 2)
    calculate deltas     → compute CPU % and KB/s rates
```

Steps 7–10 are why each refresh takes ~3 seconds — two samples separated by an interval are needed to compute a rate.

### Why `sleep(3)` is necessary

CPU usage and network throughput are **rates**, not point-in-time values. A single reading from `/proc/stat` only gives you cumulative tick counts since boot. To calculate a percentage you need:

```
CPU% = (active_ticks_s2 - active_ticks_s1) / (total_ticks_s2 - total_ticks_s1) × 100
```

The same logic applies to network RX/TX bytes. A longer sleep interval (3 s vs 1 s) produces more accurate averages at the cost of a slower response.

---

## Dashboard Cards

### Disk Space

**Source:** `df -k`

Displays each physical filesystem (tmpfs, udev, devtmpfs, and overlay are filtered out). For each mount:

- Mount point path
- Usage percentage with a colour-coded progress bar
- Used / Total GiB and available GiB

**Colour thresholds:**

| Usage | Colour |
|-------|--------|
| < 65% | Green (`#00d4aa`) |
| 65–84% | Orange (`#f5a623`) |
| ≥ 85% | Red (`#f04060`) |

To change the filtered filesystems, edit this line in `parse_df()`:

```php
if (in_array($fs, ['tmpfs','udev','devtmpfs','overlay'])) continue;
```

---

### Memory

**Source:** `free -k`

Displays a circular SVG gauge showing used memory as a percentage, with the same colour thresholds as disk. Below the gauge, four stat rows show:

- **Total** — physical RAM installed
- **Used** — actively used memory (does not include buffers/cache)
- **Free** — completely unused memory
- **Available** — memory available for new processes (free + reclaimable cache)

> **Tip:** "Available" is the most useful figure for determining if the system is under memory pressure. "Free" is often misleadingly low on healthy Linux systems because the kernel aggressively uses spare RAM for disk cache.

---

### CPU Usage

**Source:** `/proc/stat` (two samples, 3 s apart)

Displays:
- A large number showing overall CPU usage across all cores
- One horizontal bar per logical CPU core (cpu0, cpu1, …)

**Colour thresholds:**

| Usage | Colour |
|-------|--------|
| < 50% | Blue (`#3d8ef8`) |
| 50–79% | Orange (`#f5a623`) |
| ≥ 80% | Red (`#f04060`) |

The calculation per CPU:

```php
$dtot  = $total_s2 - $total_s1;   // total ticks elapsed
$didle = $idle_s2  - $idle_s1;    // idle ticks elapsed
$pct   = ($dtot - $didle) / $dtot * 100;
```

`idle` includes both `idle` and `iowait` fields from `/proc/stat`.

---

### Uptime & Load

**Source:** `uptime`

- **Uptime value** — how long the system has been running (e.g. `3 days, 2:10`)
- **Load average bars** — 1-minute, 5-minute, and 15-minute load averages

The bars map load average to a percentage using `load * 25` (so a load of 4.0 fills the bar completely). Colour thresholds:

| Load avg | Colour |
|----------|--------|
| < 1.5 | Blue |
| 1.5–2.9 | Orange |
| ≥ 3.0 | Red |

> **What is load average?** It represents the average number of processes either running or waiting for CPU/IO over the given period. A load of 1.0 on a single-core machine means the CPU is fully utilised. On a 4-core machine, a load of 4.0 means all cores are busy.

---

### Network Traffic (3s avg)

**Source:** `/proc/net/dev` (two samples, 3 s apart)

Displays one block per network interface (loopback `lo` is excluded). Each block shows:

- **↓ RX** — incoming traffic in KB/s or MB/s
- **↑ TX** — outgoing traffic in KB/s or MB/s

The horizontal bars are relative — the widest bar represents the fastest interface at that moment. Values are calculated as:

```php
$rx_kb = ($rx_bytes_s2 - $rx_bytes_s1) / 3 / 1024;
```

The divisor `3` matches the `sleep(3)` interval. If you change the sleep duration, update this divisor to match.

---

### System Info

**Source:** `uname -a`

Displays:
- **Hostname** — also shown in the page header
- **Kernel** — kernel version string (e.g. `6.1.0-18-amd64`)
- **Arch** — CPU architecture (e.g. `x86_64`, `aarch64`)
- **Full** — the complete raw `uname -a` output

---

### Top Processes (by CPU)

**Source:** `ps aux --sort=-%cpu`

Shows the top 8 processes sorted by current CPU usage. Columns:

| Column | Description |
|--------|-------------|
| PID | Process ID |
| User | Owner of the process |
| Command | Binary name (truncated to 28 characters) |
| CPU% | CPU usage percentage |
| MEM% | Memory usage as percentage of total RAM |

CPU% column colour thresholds:

| CPU% | Colour |
|------|--------|
| < 20% | Default |
| 20–49% | Orange |
| ≥ 50% | Red |

> **Note:** `ps` reports cumulative CPU usage averaged since the process started, not instantaneous usage. A long-running process with occasional CPU spikes may show a low average.

---

### Listening Ports

**Source:** `ss -tulnp`

Shows up to 12 listening TCP/UDP ports with:

- **Proto** — `tcp`, `udp`, `tcp6`, `udp6`
- **Port** — the port number extracted from the local address
- **Process** — process name (requires root or matching user to see process names for all ports)

---

## Configuration & Customization

### Changing the sampling interval

The 3-second sleep affects response time and accuracy. To change it:

1. Change `sleep(3)` to your desired value (in seconds)
2. Change the divisor in the network traffic calculation to match:

```php
// Change both values together
sleep(5);   // ← new interval

$net_traffic[] = [
    'rx_kb' => round($rx_bytes / 5 / 1024, 2),  // ← same value here
    'tx_kb' => round($tx_bytes / 5 / 1024, 2),
];
```

### Adding more disk filter exclusions

To hide additional filesystems from the Disk Space card:

```php
if (in_array($fs, ['tmpfs','udev','devtmpfs','overlay','squashfs'])) continue;
```

### Changing colour thresholds

Thresholds are set in two JavaScript functions at the bottom of the file:

```javascript
// Disk and memory thresholds
function pctColor(p) {
    if (p >= 85) return 'var(--danger)';   // red
    if (p >= 65) return 'var(--warn)';     // orange
    return 'var(--accent2)';               // green
}

// CPU thresholds
function cpuColor(p) {
    if (p >= 80) return 'var(--danger)';   // red
    if (p >= 50) return 'var(--warn)';     // orange
    return 'var(--accent)';               // blue
}
```

### Changing the colour palette

All colours are defined as CSS variables at the top of the `<style>` block:

```css
:root {
    --bg:      #080b10;   /* page background */
    --panel:   #0e1218;   /* card background */
    --border:  #1c2130;   /* card border */
    --text:    #c8d0e0;   /* primary text */
    --muted:   #4a5470;   /* secondary text / labels */
    --dim:     #2a3048;   /* progress bar background */
    --accent:  #3d8ef8;   /* blue — TX / normal CPU */
    --accent2: #00d4aa;   /* teal — RX / normal disk/mem */
    --warn:    #f5a623;   /* orange — warning threshold */
    --danger:  #f04060;   /* red — critical threshold */
}
```

### Adjusting the grid layout

Cards are placed on a 12-column grid using span classes:

```html
<div class="card c5 ...">  <!-- spans 5 of 12 columns -->
<div class="card c7 ...">  <!-- spans 7 of 12 columns -->
```

Available classes: `c3`, `c4`, `c5`, `c6`, `c7`, `c8`, `c12`. Spans within a row should add up to 12. On screens narrower than 1100px all cards automatically expand to full width.

### Changing the number of processes shown

In `parse_procs()`:

```php
foreach (array_slice($lines, 0, 8) as $line) {  // change 8 to any number
```

Also update the HTML table label:

```html
<div class="card-label">Top Processes (by CPU)</div>
```

### Changing the number of ports shown

In `parse_network_ports()`:

```php
return array_slice($ports, 0, 12);  // change 12 to any number
```

---

## Security Considerations

This file executes system commands and exposes sensitive server information. **It should never be publicly accessible.**

### Recommended protections

**Option A: Apache Basic Auth**

```apache
# Add to /etc/apache2/sites-available/000-default.conf or .htaccess
<Files "dashboard.php">
    AuthType Basic
    AuthName "Dashboard"
    AuthUserFile /etc/apache2/.htpasswd
    Require valid-user
</Files>
```

Create a password file:

```bash
sudo htpasswd -c /etc/apache2/.htpasswd yourusername
sudo systemctl reload apache2
```

**Option B: IP allowlist (Apache)**

```apache
<Files "dashboard.php">
    Require ip 192.168.1.0/24
    Require ip 10.0.0.5
</Files>
```

**Option C: IP allowlist (Nginx)**

```nginx
location = /dashboard.php {
    allow 192.168.1.0/24;
    deny all;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

**Option D: Move outside document root**

Place the file somewhere not served by the web server and access it only over SSH or a VPN.

---

## Troubleshooting

### `Unexpected token '<'` — JSON parse error

The server returned HTML instead of JSON. Causes:

1. **PHP not configured** — the `.php` file is being served as plain text. Check `apache2ctl -M | grep php` or `php-fpm` status.
2. **PHP fatal error before JSON output** — a PHP error page was returned. Check `/var/log/apache2/error.log`.
3. **Wrong document root** — the file is not in the directory the web server is serving. Check `grep DocumentRoot /etc/apache2/sites-enabled/*`.

### `Not Found` — 404 error

1. Check the file exists: `ls -la /var/www/html/dashboard.php`
2. Check the default site is enabled: `sudo a2ensite 000-default && sudo systemctl reload apache2`
3. Confirm the document root: `grep DocumentRoot /etc/apache2/sites-enabled/*`

### Process names missing in Listening Ports

`ss -tulnp` only shows process names for ports owned by the current user, or when run as root. The web server (typically `www-data`) does not have permission to see process names for ports owned by other users. Options:

- Accept this limitation (port numbers are still shown)
- Run the web server as root (not recommended)
- Use `sudo` with `NOPASSWD` for the `ss` command via a wrapper script

### CPU always shows 0%

The `/proc/stat` file may not be readable, or the sampling interval is too short. Check:

```bash
cat /proc/stat | head -5
```

If this works but CPU still shows 0, increase `sleep(3)` to `sleep(5)`.

### Network traffic always shows 0 KB/s

Check that `/proc/net/dev` is readable:

```bash
cat /proc/net/dev
```

Also check that the interface is active and passing traffic. An idle interface will correctly show 0 KB/s.

### Page loads but cards stay in loading state

Open browser DevTools → Network tab → click Refresh on the page → find the POST request to `dashboard.php` → inspect the response. The raw response will reveal whether PHP returned JSON or an error.
