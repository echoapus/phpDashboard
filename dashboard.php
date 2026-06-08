<?php
function run_cmd($cmd) {
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) return ['out' => '', 'err' => 'Failed', 'code' => -1];
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['out' => $out, 'err' => $err, 'code' => $code];
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sample_interval_seconds() {
    return 1.0;
}

function parse_df() {
    $mountLines = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($mountLines === false) return [];

    $skipTypes = ['tmpfs', 'devtmpfs', 'overlay', 'squashfs', 'proc', 'sysfs', 'cgroup2'];
    $skipPrefixes = ['/proc', '/sys', '/dev'];
    $seenDevices = [];
    $mounts = [];

    foreach ($mountLines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 3) continue;
        $fs = $parts[0];
        $mount = str_replace('\040', ' ', $parts[1]);
        $type = $parts[2];
        if (strpos($fs, '/dev/') !== 0) continue;
        if (in_array($type, $skipTypes, true)) continue;
        foreach ($skipPrefixes as $prefix) {
            if (strpos($mount, $prefix) === 0) continue 2;
        }
        if (isset($seenDevices[$fs])) continue;
        $total = @disk_total_space($mount);
        $avail = @disk_free_space($mount);
        if ($total === false || $avail === false || $total <= 0) continue;
        $used = $total - $avail;
        $seenDevices[$fs] = true;
        $mounts[] = [
            'fs' => $fs,
            'mount' => $mount,
            'total' => round($total / 1024 / 1024 / 1024, 1),
            'used' => round($used / 1024 / 1024 / 1024, 1),
            'avail' => round($avail / 1024 / 1024 / 1024, 1),
            'pct' => (int)round($used / $total * 100),
        ];
    }
    return $mounts;
}

function parse_meminfo_map() {
    $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    $mem = [];
    foreach ($lines as $line) {
        if (!preg_match('/^([A-Za-z_]+):\s+(\d+)/', $line, $m)) continue;
        $mem[$m[1]] = (int)$m[2];
    }
    return $mem;
}

function parse_mem() {
    $mem = parse_meminfo_map();
    $total = $mem['MemTotal'] ?? 0;
    $free = $mem['MemFree'] ?? 0;
    $available = $mem['MemAvailable'] ?? $free;
    $buffers = $mem['Buffers'] ?? 0;
    $cached = $mem['Cached'] ?? 0;
    $sreclaimable = $mem['SReclaimable'] ?? 0;
    $used = max(0, $total - $free - $buffers - $cached - $sreclaimable);
    return [
        'total' => (int)round($total / 1024),
        'used' => (int)round($used / 1024),
        'free' => (int)round($free / 1024),
        'available' => (int)round($available / 1024),
        'pct' => $total > 0 ? (int)round($used / $total * 100) : 0,
    ];
}

function parse_swap() {
    $mem = parse_meminfo_map();
    $total = $mem['SwapTotal'] ?? 0;
    $free = $mem['SwapFree'] ?? 0;
    $used = max(0, $total - $free);
    return [
        'total' => (int)round($total / 1024),
        'used' => (int)round($used / 1024),
        'free' => (int)round($free / 1024),
        'pct' => $total > 0 ? (int)round($used / $total * 100) : 0,
    ];
}

function parse_uptime() {
    $uptimeRaw = @file_get_contents('/proc/uptime');
    $seconds = $uptimeRaw !== false ? (int)floor((float)explode(' ', trim($uptimeRaw))[0]) : 0;
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0 || $days > 0) $parts[] = $hours . 'h';
    $parts[] = $minutes . 'm';
    $load = sys_getloadavg();
    return [
        'uptime' => implode(' ', $parts),
        'load1' => isset($load[0]) ? (float)$load[0] : 0,
        'load5' => isset($load[1]) ? (float)$load[1] : 0,
        'load15' => isset($load[2]) ? (float)$load[2] : 0,
    ];
}

function parse_boot_time() {
    $lines = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return '—';
    foreach ($lines as $line) {
        if (strpos($line, 'btime ') === 0) {
            return date('Y-m-d H:i:s', (int)trim(substr($line, 6)));
        }
    }
    return '—';
}

function parse_sysinfo() {
    return [
        'kernel' => php_uname('r') ?: '—',
        'hostname' => php_uname('n') ?: '—',
        'arch' => php_uname('m') ?: '—',
        'raw' => php_uname() ?: '—',
    ];
}

function parse_cpu_meta() {
    $cpuinfo = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $cores = 0;
    if ($cpuinfo !== false) {
        foreach ($cpuinfo as $line) {
            if (strpos($line, 'processor') === 0) $cores++;
        }
    }
    return ['cores' => max(1, $cores)];
}

function parse_procs($sort) {
    if ($sort === 'mem') {
        $cmd = 'ps -eo user:20,pid,pmem,pcpu,comm --sort=-pmem';
    } else {
        $cmd = 'ps -eo user:20,pid,pcpu,pmem,comm --sort=-pcpu';
    }
    $r = run_cmd($cmd);
    if ($r['code'] !== 0) return [];
    $lines = preg_split('/\r?\n/', trim($r['out']));
    if (!$lines) return [];
    array_shift($lines);
    $procs = [];
    foreach (array_slice($lines, 0, 8) as $line) {
        $p = preg_split('/\s+/', trim($line), 5);
        if (count($p) < 5) continue;
        $cmdName = $p[4];
        $procs[] = [
            'user' => $p[0],
            'pid' => $p[1],
            'a' => $p[2],
            'b' => $p[3],
            'cmd' => function_exists('mb_strimwidth') ? mb_strimwidth($cmdName, 0, 28, '...') : substr($cmdName, 0, 28),
        ];
    }
    return $procs;
}

function parse_network_ports() {
    $r = run_cmd('ss -H -tuln');
    if ($r['code'] !== 0) return ['data' => [], 'note' => 'ss unavailable'];
    $lines = preg_split('/\r?\n/', trim($r['out']));
    $ports = [];
    foreach ($lines as $line) {
        $p = preg_split('/\s+/', trim($line), 6);
        if (count($p) < 5) continue;
        $local = $p[4] ?? '—';
        preg_match('/:(\d+)$|^\[(.*)\]:(\d+)$/', $local, $pm);
        $port = isset($pm[3]) && $pm[3] !== '' ? $pm[3] : ($pm[1] ?? $local);
        $ports[] = ['proto' => $p[0], 'port' => $port];
    }
    return ['data' => array_slice($ports, 0, 12), 'note' => count($ports) ? '' : 'No listening ports found'];
}

function parse_connections() {
    $r = run_cmd('ss -tan');
    if ($r['code'] !== 0) {
        return ['total' => 0, 'established' => 0, 'time_wait' => 0, 'syn_recv' => 0, 'note' => 'ss unavailable'];
    }
    $lines = preg_split('/\r?\n/', trim($r['out']));
    $summary = ['total' => 0, 'established' => 0, 'time_wait' => 0, 'syn_recv' => 0, 'note' => ''];
    foreach ($lines as $line) {
        if ($line === '' || strpos($line, 'State') === 0) continue;
        $parts = preg_split('/\s+/', trim($line));
        if (!isset($parts[0])) continue;
        $state = strtoupper($parts[0]);
        $summary['total']++;
        if ($state === 'ESTAB' || $state === 'ESTABLISHED') $summary['established']++;
        if ($state === 'TIME-WAIT') $summary['time_wait']++;
        if ($state === 'SYN-RECV') $summary['syn_recv']++;
    }
    if ($summary['total'] === 0) $summary['note'] = 'No active TCP connections';
    return $summary;
}

function parse_inodes() {
    $r = run_cmd('df -iP');
    if ($r['code'] !== 0) return [];
    $lines = preg_split('/\r?\n/', trim($r['out']));
    if (!$lines) return [];
    array_shift($lines);
    $rows = [];
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 6) continue;
        $fs = $parts[0];
        $mount = $parts[5];
        if (strpos($fs, '/dev/') !== 0) continue;
        if (preg_match('#^/(proc|sys|dev)#', $mount)) continue;
        $rows[] = [
            'mount' => $mount,
            'used' => (int)$parts[2],
            'pct' => (int)str_replace('%', '', $parts[4]),
        ];
    }
    return array_slice($rows, 0, 8);
}

function read_cpu_stat() {
    $lines = @file('/proc/stat');
    if ($lines === false) return [];
    $cpus = [];
    foreach ($lines as $line) {
        if (!preg_match('/^(cpu\d*)\s+(.+)/', $line, $m)) continue;
        $vals = array_map('intval', preg_split('/\s+/', trim($m[2])));
        $idle = $vals[3] + ($vals[4] ?? 0);
        $cpus[$m[1]] = ['idle' => $idle, 'total' => array_sum($vals)];
    }
    return $cpus;
}

function read_net_stat() {
    $lines = @file('/proc/net/dev');
    if ($lines === false) return [];
    $ifaces = [];
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) continue;
        [$iface, $data] = explode(':', $line, 2);
        $iface = trim($iface);
        if ($iface === 'lo') continue;
        $vals = preg_split('/\s+/', trim($data));
        if (count($vals) < 9) continue;
        $ifaces[$iface] = ['rx' => (float)$vals[0], 'tx' => (float)$vals[8]];
    }
    return $ifaces;
}

function read_disk_stats() {
    $lines = @file('/proc/diskstats', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    $stats = [];
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 14) continue;
        $name = $parts[2];
        if (preg_match('/^(loop|ram|fd|sr)/', $name)) continue;
        if (preg_match('/\d$/', $name) && !preg_match('/^(nvme\d+n\d+)$/', $name)) continue;
        $stats[$name] = [
            'read_sectors' => (int)$parts[5],
            'write_sectors' => (int)$parts[9],
            'io_ms_weighted' => (int)$parts[13],
        ];
    }
    return $stats;
}

function sample_cpu_and_net() {
    $interval = sample_interval_seconds();
    $cpu1 = read_cpu_stat();
    $net1 = read_net_stat();
    $disk1 = read_disk_stats();
    usleep((int)($interval * 1000000));
    $cpu2 = read_cpu_stat();
    $net2 = read_net_stat();
    $disk2 = read_disk_stats();

    $cpu = [];
    foreach ($cpu2 as $name => $v2) {
        if (!isset($cpu1[$name])) continue;
        $dtot = $v2['total'] - $cpu1[$name]['total'];
        $didle = $v2['idle'] - $cpu1[$name]['idle'];
        $cpu[] = ['name' => $name, 'pct' => $dtot > 0 ? round(($dtot - $didle) / $dtot * 100, 1) : 0];
    }

    $net = [];
    foreach ($net2 as $iface => $v2) {
        if (!isset($net1[$iface])) continue;
        $net[] = [
            'iface' => $iface,
            'rx_kb' => round(($v2['rx'] - $net1[$iface]['rx']) / $interval / 1024, 2),
            'tx_kb' => round(($v2['tx'] - $net1[$iface]['tx']) / $interval / 1024, 2),
        ];
    }

    $disk = [];
    foreach ($disk2 as $name => $v2) {
        if (!isset($disk1[$name])) continue;
        $readBytes = max(0, $v2['read_sectors'] - $disk1[$name]['read_sectors']) * 512;
        $writeBytes = max(0, $v2['write_sectors'] - $disk1[$name]['write_sectors']) * 512;
        $busyPct = min(100, round(max(0, $v2['io_ms_weighted'] - $disk1[$name]['io_ms_weighted']) / ($interval * 10), 1));
        $disk[] = [
            'device' => $name,
            'read_kb' => round($readBytes / $interval / 1024, 2),
            'write_kb' => round($writeBytes / $interval / 1024, 2),
            'busy_pct' => $busyPct,
        ];
    }

    return [$cpu, $net, $disk];
}

function parse_temperatures() {
    $zones = glob('/sys/class/thermal/thermal_zone*/temp');
    if (!$zones) return [];
    $temps = [];
    foreach ($zones as $tempFile) {
        $raw = @file_get_contents($tempFile);
        if ($raw === false) continue;
        $c = (float)trim($raw) / 1000;
        if ($c <= 0) continue;
        $label = @file_get_contents(dirname($tempFile) . '/type');
        $temps[] = ['label' => $label !== false ? trim($label) : basename(dirname($tempFile)), 'c' => round($c, 1)];
    }
    return array_slice($temps, 0, 6);
}

[$cpuData, $netTraffic, $diskIo] = sample_cpu_and_net();
$df = parse_df();
$mem = parse_mem();
$swap = parse_swap();
$uptime = parse_uptime();
$bootTime = parse_boot_time();
$sysinfo = parse_sysinfo();
$cpuMeta = parse_cpu_meta();
$cpuProcs = parse_procs('cpu');
$memProcs = parse_procs('mem');
$ports = parse_network_ports();
$connections = parse_connections();
$inodes = parse_inodes();
$temps = parse_temperatures();
$lastUpdated = date('Y-m-d H:i:s');

$cpuOverall = 0;
foreach ($cpuData as $cpuRow) {
    if ($cpuRow['name'] === 'cpu') {
        $cpuOverall = $cpuRow['pct'];
        break;
    }
}
$diskPeak = 0;
foreach ($df as $mount) {
    $diskPeak = max($diskPeak, $mount['pct']);
}
$netTotal = 0;
foreach ($netTraffic as $iface) {
    $netTotal += $iface['rx_kb'] + $iface['tx_kb'];
}

function pct_color($p) {
    if ($p >= 85) return 'var(--danger)';
    if ($p >= 65) return 'var(--warn)';
    return 'var(--accent2)';
}

function cpu_color($p) {
    if ($p >= 80) return 'var(--danger)';
    if ($p >= 50) return 'var(--warn)';
    return 'var(--accent)';
}

function fmt_kb($kb) {
    return $kb >= 1024 ? number_format($kb / 1024, 2) . ' MB/s' : number_format($kb, 1) . ' KB/s';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="15">
<title>System Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root { --bg:#080b10; --panel:#0e1218; --border:#1c2130; --text:#c8d0e0; --muted:#4a5470; --dim:#2a3048; --accent:#3d8ef8; --accent2:#00d4aa; --warn:#f5a623; --danger:#f04060; --mono:'IBM Plex Mono', monospace; --sans:'IBM Plex Sans', sans-serif; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:var(--sans); background:var(--bg); color:var(--text); min-height:100vh; padding:1.25rem 1.5rem 2.5rem; }
header { display:flex; align-items:center; justify-content:space-between; margin-bottom:2rem; padding-bottom:1rem; border-bottom:1px solid var(--border); gap:1rem; flex-wrap:wrap; }
.logo { display:flex; align-items:baseline; gap:.6rem; }
.logo h1 { font-family:var(--mono); font-size:1.05rem; font-weight:600; color:var(--accent); letter-spacing:.05em; }
.logo .host,.header-note,#last-updated { font-family:var(--mono); font-size:.72rem; color:var(--muted); }
.header-right { display:flex; align-items:center; gap:.9rem; flex-wrap:wrap; }
.refresh-link { font-family:var(--mono); font-size:.78rem; font-weight:600; padding:.48rem 1rem; background:transparent; color:var(--accent); border:1px solid var(--accent); border-radius:6px; text-decoration:none; }
.refresh-link:hover { background:var(--accent); color:#fff; }
.grid { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:1rem; }
.c3{grid-column:span 3}.c4{grid-column:span 4}.c5{grid-column:span 5}.c8{grid-column:span 8}.c12{grid-column:span 12}
@media(max-width:1100px){.c3,.c4,.c5,.c8{grid-column:span 12}}
.card { background:var(--panel); border:1px solid var(--border); border-radius:8px; padding:1rem 1.1rem; min-width:0; }
.card-label { font-family:var(--mono); font-size:.65rem; letter-spacing:.12em; color:var(--muted); text-transform:uppercase; margin-bottom:.85rem; display:flex; align-items:center; gap:.4rem; }
.card-label::before { content:''; width:5px; height:5px; border-radius:50%; background:var(--accent2); flex-shrink:0; }
.section-title { grid-column:1/-1; display:flex; align-items:center; gap:1rem; }
.section-title .kicker { font-family:var(--mono); font-size:.66rem; color:var(--muted); letter-spacing:.12em; text-transform:uppercase; }
.section-title .rule { flex:1; height:1px; background:var(--border); }
.summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.6rem; }
@media(max-width:1100px){.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
.summary-tile,.mini-stat { border:1px solid var(--border); background:rgba(255,255,255,.01); border-radius:6px; padding:.7rem .75rem; min-width:0; }
.summary-tile .k,.mini-stat .k { display:block; color:var(--muted); font-size:.62rem; font-family:var(--mono); margin-bottom:.28rem; text-transform:uppercase; letter-spacing:.08em; }
.summary-tile .v,.mini-stat .v { display:block; font-family:var(--mono); font-size:1rem; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mini-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.55rem; }
.disk-row { display:grid; grid-template-columns:1fr auto; gap:.3rem .6rem; align-items:center; margin-bottom:.9rem; }
.disk-bar-wrap,.load-bar-wrap,.cpu-core-bar-wrap,.net-rate-bar-wrap { background:var(--dim); border-radius:3px; overflow:hidden; }
.disk-bar-wrap { grid-column:1/-1; height:5px; }
.disk-bar,.load-bar,.cpu-core-bar,.net-rate-bar { height:100%; }
.disk-mount,.disk-pct,.disk-sub,.uptime-val,.load-row,.cpu-overall,.cpu-core-row,.sysinfo-grid .v,.dash-table th,.dash-table td,.muted-note,.temp-row,.inode-row,.io-row,.pill,.net-iface-name,.net-rate-row { font-family:var(--mono); }
.disk-mount { font-size:.72rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.disk-pct,.cpu-pct-val,.load-val,.net-rate-val { font-size:.72rem; color:var(--muted); text-align:right; }
.disk-sub,.muted-note { font-size:.65rem; color:var(--muted); }
.mem-layout { display:flex; align-items:center; gap:1.4rem; }
.gauge-wrap { position:relative; width:96px; height:96px; flex-shrink:0; }
.gauge-wrap svg { transform:rotate(-90deg); overflow:visible; }
.gauge-bg { fill:none; stroke:var(--dim); stroke-width:9; }
.gauge-text { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
.gauge-pct { font-family:var(--mono); font-size:1.3rem; font-weight:600; line-height:1; }
.gauge-sub2 { font-family:var(--mono); font-size:.55rem; color:var(--muted); margin-top:2px; }
.mem-stat-row { display:flex; justify-content:space-between; padding:.28rem 0; border-bottom:1px solid var(--border); font-size:.78rem; }
.mem-stat-row:last-child,.dash-table tr:last-child td { border-bottom:none; }
.uptime-val { font-size:1.6rem; font-weight:600; color:var(--accent2); margin-bottom:.8rem; }
.load-bars,.cpu-cores { display:flex; flex-direction:column; gap:.35rem; }
.load-row { display:grid; grid-template-columns:2.8rem 1fr 2.5rem; gap:.5rem; font-size:.7rem; align-items:center; }
.load-bar-wrap,.cpu-core-bar-wrap,.net-rate-bar-wrap { height:4px; }
.cpu-overall { display:flex; align-items:baseline; gap:.5rem; margin-bottom:.9rem; }
.cpu-big { font-size:2.2rem; font-weight:600; line-height:1; }
.cpu-unit { font-size:.7rem; color:var(--muted); }
.cpu-core-row { display:grid; grid-template-columns:3rem 1fr 2.8rem; gap:.45rem; font-size:.68rem; align-items:center; }
.pill { display:inline-flex; align-items:center; justify-content:center; min-width:3.2rem; padding:.16rem .42rem; border:1px solid var(--border); border-radius:999px; font-size:.64rem; color:var(--muted); }
.net-iface-block,.temp-row,.inode-row,.io-row { margin-bottom:.55rem; }
.net-iface-block:last-child,.temp-row:last-child,.inode-row:last-child,.io-row:last-child { margin-bottom:0; }
.net-iface-name { font-size:.7rem; margin-bottom:.35rem; }
.net-rate-row { display:grid; grid-template-columns:1.2rem 1fr 5rem; gap:.4rem; align-items:center; font-size:.68rem; margin-bottom:.25rem; }
.sysinfo-grid { display:grid; grid-template-columns:auto 1fr; gap:.4rem 1rem; font-size:.78rem; }
.sysinfo-grid .k { color:var(--muted); font-size:.7rem; }
.dash-table { width:100%; border-collapse:collapse; font-size:.73rem; }
.dash-table th { font-size:.62rem; letter-spacing:.08em; color:var(--muted); text-transform:uppercase; text-align:left; padding:0 .4rem .6rem; border-bottom:1px solid var(--border); }
.dash-table td { font-size:.72rem; padding:.4rem .4rem; border-bottom:1px solid var(--border); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px; }
.num { text-align:right; color:var(--accent); }
.temp-row { display:grid; grid-template-columns:1fr auto; gap:.5rem; font-size:.7rem; min-width:0; }
.inode-row { display:grid; grid-template-columns:minmax(0,1fr) auto auto; gap:.5rem; font-size:.7rem; min-width:0; }
.io-row { display:grid; grid-template-columns:4rem minmax(0,1fr) minmax(0,1fr) 3rem; gap:.5rem; font-size:.7rem; min-width:0; }
.note { margin-top:.7rem; color:var(--muted); font-family:var(--mono); font-size:.66rem; }
</style>
</head>
<body>
<header>
    <div class="logo">
        <h1>SYSBOARD</h1>
        <span class="host"><?= h($sysinfo['hostname']) ?></span>
    </div>
    <div class="header-right">
        <span class="header-note">Auto refresh every 15s</span>
        <span id="last-updated">Updated at <?= h($lastUpdated) ?></span>
        <a class="refresh-link" href="<?= h($_SERVER['PHP_SELF'] ?? 'dashboard.php') ?>">Refresh</a>
    </div>
</header>

<div class="grid">
    <div class="section-title"><span class="kicker">Overview</span><span class="rule"></span></div>
    <div class="card c12">
        <div class="card-label">System Summary</div>
        <div class="summary-grid">
            <div class="summary-tile"><span class="k">CPU</span><span class="v" style="color:<?= h(cpu_color($cpuOverall)) ?>"><?= h(number_format($cpuOverall, 1)) ?>%</span></div>
            <div class="summary-tile"><span class="k">Memory</span><span class="v" style="color:<?= h(pct_color($mem['pct'])) ?>"><?= h($mem['pct']) ?>% of <?= h($mem['total']) ?> MB</span></div>
            <div class="summary-tile"><span class="k">Disk Peak</span><span class="v" style="color:<?= h(pct_color($diskPeak)) ?>"><?= h($diskPeak) ?>% used</span></div>
            <div class="summary-tile"><span class="k">Network</span><span class="v"><?= h(fmt_kb($netTotal)) ?> total</span></div>
        </div>
    </div>

    <div class="card c5">
        <div class="card-label">Disk Space</div>
        <?php if (!$df): ?>
            <div class="note">No disk data</div>
        <?php else: foreach ($df as $mount): $col = pct_color($mount['pct']); ?>
            <div class="disk-row">
                <span class="disk-mount"><?= h($mount['mount']) ?></span>
                <span class="disk-pct" style="color:<?= h($col) ?>"><?= h($mount['pct']) ?>%</span>
                <div class="disk-bar-wrap"><div class="disk-bar" style="width:<?= h($mount['pct']) ?>%;background:<?= h($col) ?>"></div></div>
            </div>
            <div class="disk-sub"><?= h($mount['used']) ?> GiB / <?= h($mount['total']) ?> GiB · avail <?= h($mount['avail']) ?> GiB</div>
        <?php endforeach; endif; ?>
    </div>

    <div class="card c4">
        <div class="card-label">Memory</div>
        <?php $memOffset = 251.2 * (1 - $mem['pct'] / 100); $memCol = pct_color($mem['pct']); ?>
        <div class="mem-layout">
            <div class="gauge-wrap">
                <svg viewBox="0 0 96 96" width="96" height="96">
                    <circle class="gauge-bg" cx="48" cy="48" r="40"></circle>
                    <circle cx="48" cy="48" r="40" fill="none" stroke-width="9" stroke-linecap="round" stroke="<?= h($memCol) ?>" stroke-dasharray="251.2" stroke-dashoffset="<?= h($memOffset) ?>"></circle>
                </svg>
                <div class="gauge-text">
                    <span class="gauge-pct" style="color:<?= h($memCol) ?>"><?= h($mem['pct']) ?>%</span>
                    <span class="gauge-sub2">USED</span>
                </div>
            </div>
            <div class="mem-stats">
                <div class="mem-stat-row"><span>Total</span><span><?= h($mem['total']) ?> MB</span></div>
                <div class="mem-stat-row"><span>Used</span><span><?= h($mem['used']) ?> MB</span></div>
                <div class="mem-stat-row"><span>Free</span><span><?= h($mem['free']) ?> MB</span></div>
                <div class="mem-stat-row"><span>Available</span><span><?= h($mem['available']) ?> MB</span></div>
            </div>
        </div>
    </div>

    <div class="card c3">
        <div class="card-label">Swap</div>
        <div class="mini-grid">
            <div class="mini-stat"><span class="k">Used</span><span class="v" style="color:<?= h(pct_color($swap['pct'])) ?>"><?= h($swap['used']) ?> MB</span></div>
            <div class="mini-stat"><span class="k">Usage</span><span class="v" style="color:<?= h(pct_color($swap['pct'])) ?>"><?= h($swap['pct']) ?>%</span></div>
            <div class="mini-stat"><span class="k">Total</span><span class="v"><?= h($swap['total']) ?> MB</span></div>
            <div class="mini-stat"><span class="k">Free</span><span class="v"><?= h($swap['free']) ?> MB</span></div>
        </div>
    </div>

    <div class="card c5">
        <div class="card-label">CPU Usage</div>
        <div class="cpu-overall">
            <span class="cpu-big" style="color:<?= h(cpu_color($cpuOverall)) ?>"><?= h(number_format($cpuOverall, 1)) ?></span>
            <span class="cpu-unit">%</span>
        </div>
        <div class="muted-note" style="margin-bottom:.7rem">Cores <span class="pill"><?= h($cpuMeta['cores']) ?></span> <span style="margin-left:.45rem">Load/Core</span> <span class="pill"><?= h(number_format($uptime['load1'] / max(1, $cpuMeta['cores']), 2)) ?></span></div>
        <div class="cpu-cores">
            <?php foreach ($cpuData as $cpu): if ($cpu['name'] === 'cpu') continue; $col = cpu_color($cpu['pct']); ?>
                <div class="cpu-core-row">
                    <span style="color:var(--muted)"><?= h($cpu['name']) ?></span>
                    <div class="cpu-core-bar-wrap"><div class="cpu-core-bar" style="width:<?= h($cpu['pct']) ?>%;background:<?= h($col) ?>"></div></div>
                    <span class="cpu-pct-val"><?= h(number_format($cpu['pct'], 1)) ?>%</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card c4">
        <div class="card-label">Uptime & Load</div>
        <div class="uptime-val"><?= h($uptime['uptime']) ?></div>
        <div class="muted-note" style="margin:-.35rem 0 .8rem">Boot: <?= h($bootTime) ?></div>
        <div class="load-bars">
            <?php foreach ([['1m', $uptime['load1']], ['5m', $uptime['load5']], ['15m', $uptime['load15']]] as [$label, $val]): $col = $val >= 3 ? 'var(--danger)' : ($val >= 1.5 ? 'var(--warn)' : 'var(--accent)'); ?>
                <div class="load-row">
                    <span style="color:var(--muted)"><?= h($label) ?></span>
                    <div class="load-bar-wrap"><div class="load-bar" style="width:<?= h(min($val * 25, 100)) ?>%;background:<?= h($col) ?>"></div></div>
                    <span class="load-val"><?= h(number_format($val, 2)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card c4">
        <div class="card-label">Network Traffic (1s avg)</div>
        <?php if (!$netTraffic): ?>
            <div class="note">No interface traffic detected</div>
        <?php else: $maxKB = max(1, ...array_map(fn($d) => max($d['rx_kb'], $d['tx_kb']), $netTraffic)); foreach ($netTraffic as $iface): ?>
            <div class="net-iface-block">
                <div class="net-iface-name"><?= h($iface['iface']) ?></div>
                <div class="net-rate-row">
                    <span style="color:var(--accent2)">↓</span>
                    <div class="net-rate-bar-wrap"><div class="net-rate-bar" style="width:<?= h(min($iface['rx_kb'] / $maxKB * 100, 100)) ?>%;background:var(--accent2)"></div></div>
                    <span class="net-rate-val"><?= h(fmt_kb($iface['rx_kb'])) ?></span>
                </div>
                <div class="net-rate-row">
                    <span style="color:var(--accent)">↑</span>
                    <div class="net-rate-bar-wrap"><div class="net-rate-bar" style="width:<?= h(min($iface['tx_kb'] / $maxKB * 100, 100)) ?>%;background:var(--accent)"></div></div>
                    <span class="net-rate-val"><?= h(fmt_kb($iface['tx_kb'])) ?></span>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="card c4">
        <div class="card-label">Connections</div>
        <div class="mini-grid">
            <div class="mini-stat"><span class="k">Total</span><span class="v"><?= h($connections['total']) ?></span></div>
            <div class="mini-stat"><span class="k">Established</span><span class="v"><?= h($connections['established']) ?></span></div>
            <div class="mini-stat"><span class="k">Time-Wait</span><span class="v"><?= h($connections['time_wait']) ?></span></div>
            <div class="mini-stat"><span class="k">Syn-Recv</span><span class="v"><?= h($connections['syn_recv']) ?></span></div>
        </div>
        <?php if ($connections['note'] !== ''): ?><div class="note"><?= h($connections['note']) ?></div><?php endif; ?>
    </div>

    <div class="section-title"><span class="kicker">Hardware</span><span class="rule"></span></div>

    <div class="card c4">
        <div class="card-label">System Info</div>
        <div class="sysinfo-grid">
            <span class="k">Hostname</span><span class="v"><?= h($sysinfo['hostname']) ?></span>
            <span class="k">Kernel</span><span class="v"><?= h($sysinfo['kernel']) ?></span>
            <span class="k">Arch</span><span class="v"><?= h($sysinfo['arch']) ?></span>
            <span class="k">Full</span><span class="v" style="color:var(--muted)"><?= h($sysinfo['raw']) ?></span>
        </div>
    </div>

    <div class="card c4">
        <div class="card-label">Listening Ports</div>
        <table class="dash-table">
            <thead><tr><th>Proto</th><th>Port</th></tr></thead>
            <tbody>
            <?php if (!$ports['data']): ?>
                <tr><td colspan="2" style="color:var(--muted)">No port data</td></tr>
            <?php else: foreach ($ports['data'] as $port): ?>
                <tr><td style="color:var(--muted)"><?= h($port['proto']) ?></td><td class="num"><?= h($port['port']) ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php if ($ports['note'] !== ''): ?><div class="note"><?= h($ports['note']) ?></div><?php endif; ?>
    </div>

    <div class="card c4">
        <div class="card-label">Inode Usage</div>
        <?php if (!$inodes): ?>
            <div class="note">No inode data</div>
        <?php else: foreach ($inodes as $inode): ?>
            <div class="inode-row">
                <span style="overflow:hidden;text-overflow:ellipsis"><?= h($inode['mount']) ?></span>
                <span style="color:var(--muted)"><?= h(number_format($inode['used'])) ?> used</span>
                <span style="color:<?= h(pct_color($inode['pct'])) ?>"><?= h($inode['pct']) ?>%</span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="card c4">
        <div class="card-label">Disk I/O (1s avg)</div>
        <?php if (!$diskIo): ?>
            <div class="note">No block device I/O detected</div>
        <?php else: foreach ($diskIo as $io): ?>
            <div class="io-row">
                <span><?= h($io['device']) ?></span>
                <span style="color:var(--accent2)">R <?= h(fmt_kb($io['read_kb'])) ?></span>
                <span style="color:var(--accent)">W <?= h(fmt_kb($io['write_kb'])) ?></span>
                <span style="color:<?= h(cpu_color($io['busy_pct'])) ?>"><?= h($io['busy_pct']) ?>%</span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="card c4">
        <div class="card-label">Temperatures</div>
        <?php if (!$temps): ?>
            <div class="note">No sensors</div>
        <?php else: foreach ($temps as $temp): $col = $temp['c'] >= 80 ? 'var(--danger)' : ($temp['c'] >= 65 ? 'var(--warn)' : 'var(--accent2)'); ?>
            <div class="temp-row">
                <span style="overflow:hidden;text-overflow:ellipsis"><?= h($temp['label']) ?></span>
                <span style="color:<?= h($col) ?>"><?= h(number_format($temp['c'], 1)) ?>°C</span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="section-title"><span class="kicker">Processes</span><span class="rule"></span></div>

    <div class="card c8">
        <div class="card-label">Top Processes (by CPU)</div>
        <table class="dash-table">
            <thead><tr><th>PID</th><th>User</th><th>Command</th><th style="text-align:right">CPU%</th><th style="text-align:right">MEM%</th></tr></thead>
            <tbody>
            <?php if (!$cpuProcs): ?>
                <tr><td colspan="5" style="color:var(--muted)">No process data</td></tr>
            <?php else: foreach ($cpuProcs as $proc): $cls = (float)$proc['a'] >= 50 ? 'var(--danger)' : ((float)$proc['a'] >= 20 ? 'var(--warn)' : 'var(--accent)'); ?>
                <tr><td><?= h($proc['pid']) ?></td><td><?= h($proc['user']) ?></td><td><?= h($proc['cmd']) ?></td><td class="num" style="color:<?= h($cls) ?>"><?= h($proc['a']) ?></td><td class="num"><?= h($proc['b']) ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card c4">
        <div class="card-label">Top Processes (by Memory)</div>
        <table class="dash-table">
            <thead><tr><th>PID</th><th>User</th><th>Command</th><th style="text-align:right">MEM%</th><th style="text-align:right">CPU%</th></tr></thead>
            <tbody>
            <?php if (!$memProcs): ?>
                <tr><td colspan="5" style="color:var(--muted)">No process data</td></tr>
            <?php else: foreach ($memProcs as $proc): $cls = (float)$proc['a'] >= 20 ? 'var(--danger)' : ((float)$proc['a'] >= 10 ? 'var(--warn)' : 'var(--accent)'); ?>
                <tr><td><?= h($proc['pid']) ?></td><td><?= h($proc['user']) ?></td><td><?= h($proc['cmd']) ?></td><td class="num" style="color:<?= h($cls) ?>"><?= h($proc['a']) ?></td><td class="num"><?= h($proc['b']) ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
