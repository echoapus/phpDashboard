<?php
// ============================================================
// API Endpoint
// ============================================================
if (isset($_POST['fetch'])) {
    header('Content-Type: application/json');

    function run_cmd($cmd) {
        $desc = [1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) return ['out'=>'','err'=>'Failed','code'=>-1];
        $out  = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err  = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        return ['out'=>$out,'err'=>$err,'code'=>$code];
    }

    // --- Disk ---
    function parse_df() {
        $r = run_cmd('df -k');
        $lines = explode("\n", trim($r['out']));
        array_shift($lines);
        $mounts = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 6) continue;
            $fs    = $parts[0];
            $total = (int)$parts[1];
            $used  = (int)$parts[2];
            $avail = (int)$parts[3];
            $pct   = (int)str_replace('%','',$parts[4]);
            $mount = $parts[5];
            if (in_array($fs, ['tmpfs','udev','devtmpfs','overlay'])) continue;
            $mounts[] = [
                'fs'    => $fs,
                'mount' => $mount,
                'total' => round($total/1024/1024, 1),
                'used'  => round($used/1024/1024, 1),
                'avail' => round($avail/1024/1024, 1),
                'pct'   => $pct,
            ];
        }
        return $mounts;
    }

    // --- Memory ---
    function parse_mem() {
        $r = run_cmd('free -k');
        $lines = explode("\n", trim($r['out']));
        $mem = preg_split('/\s+/', $lines[1]);
        return [
            'total'     => round($mem[1]/1024),
            'used'      => round($mem[2]/1024),
            'free'      => round($mem[3]/1024),
            'available' => round($mem[6]/1024),
            'pct'       => round($mem[2]/$mem[1]*100),
        ];
    }

    // --- Uptime / Load ---
    function parse_uptime() {
        $r = run_cmd('uptime');
        $line = trim($r['out']);
        preg_match('/up\s+(.*?),\s+\d+\s+user/', $line, $up_m);
        preg_match('/load average:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)/', $line, $la_m);
        return [
            'uptime' => isset($up_m[1]) ? trim($up_m[1]) : '—',
            'load1'  => isset($la_m[1]) ? (float)$la_m[1] : 0,
            'load5'  => isset($la_m[2]) ? (float)$la_m[2] : 0,
            'load15' => isset($la_m[3]) ? (float)$la_m[3] : 0,
        ];
    }

    // --- System Info ---
    function parse_sysinfo() {
        $r = run_cmd('uname -a');
        $parts = explode(' ', trim($r['out']));
        return [
            'kernel'   => isset($parts[2]) ? $parts[2] : '—',
            'hostname' => isset($parts[1]) ? $parts[1] : '—',
            'arch'     => isset($parts[11]) ? $parts[11] : (isset($parts[10]) ? $parts[10] : '—'),
            'raw'      => trim($r['out']),
        ];
    }

    // --- Top Processes ---
    function parse_procs() {
        $r = run_cmd('ps aux --sort=-%cpu');
        $lines = explode("\n", trim($r['out']));
        array_shift($lines);
        $procs = [];
        foreach (array_slice($lines, 0, 8) as $line) {
            $p = preg_split('/\s+/', $line, 11);
            if (count($p) < 11) continue;
            $procs[] = [
                'user' => $p[0],
                'pid'  => $p[1],
                'cpu'  => $p[2],
                'mem'  => $p[3],
                'cmd'  => mb_strimwidth(basename(explode(' ',$p[10])[0]), 0, 28, '…'),
            ];
        }
        return $procs;
    }

    // --- Network Ports ---
    function parse_network_ports() {
        $r = run_cmd('ss -tulnp');
        $lines = explode("\n", trim($r['out']));
        array_shift($lines);
        $ports = [];
        foreach ($lines as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) < 5) continue;
            $local   = isset($p[4]) ? $p[4] : '—';
            $process = isset($p[6]) ? preg_replace('/.*name="([^"]+)".*/', '$1', $p[6]) : '—';
            preg_match('/:(\d+)$/', $local, $pm);
            $port = isset($pm[1]) ? $pm[1] : $local;
            $ports[] = ['proto'=>$p[0],'port'=>$port,'process'=>$process];
        }
        return array_slice($ports, 0, 12);
    }

    // --- Read /proc/stat ---
    function read_cpu_stat() {
        $lines = file('/proc/stat');
        $cpus = [];
        foreach ($lines as $line) {
            if (!preg_match('/^(cpu\d*)\s+(.+)/', $line, $m)) continue;
            $vals  = array_map('intval', explode(' ', trim($m[2])));
            $idle  = $vals[3] + (isset($vals[4]) ? $vals[4] : 0);
            $total = array_sum($vals);
            $cpus[$m[1]] = ['idle'=>$idle,'total'=>$total];
        }
        return $cpus;
    }

    // --- Read /proc/net/dev ---
    function read_net_stat() {
        $lines = file('/proc/net/dev');
        $ifaces = [];
        foreach ($lines as $line) {
            if (!strpos($line, ':')) continue;
            list($iface, $data) = explode(':', $line, 2);
            $iface = trim($iface);
            if ($iface === 'lo') continue;
            $vals = preg_split('/\s+/', trim($data));
            $ifaces[$iface] = ['rx'=>(float)$vals[0],'tx'=>(float)$vals[8]];
        }
        return $ifaces;
    }

    // First sample
    $cpu_s1 = read_cpu_stat();
    $net_s1 = read_net_stat();

    // Wait 3 seconds
    sleep(3);

    // Second sample
    $cpu_s2 = read_cpu_stat();
    $net_s2 = read_net_stat();

    // Calculate CPU %
    $cpu_data = [];
    foreach ($cpu_s2 as $name => $v2) {
        if (!isset($cpu_s1[$name])) continue;
        $dtot  = $v2['total'] - $cpu_s1[$name]['total'];
        $didle = $v2['idle']  - $cpu_s1[$name]['idle'];
        $pct   = $dtot > 0 ? round(($dtot - $didle) / $dtot * 100, 1) : 0;
        $cpu_data[] = ['name'=>$name,'pct'=>$pct];
    }

    // Calculate network traffic
    $net_traffic = [];
    foreach ($net_s2 as $iface => $v2) {
        if (!isset($net_s1[$iface])) continue;
        $rx_bytes = $v2['rx'] - $net_s1[$iface]['rx'];
        $tx_bytes = $v2['tx'] - $net_s1[$iface]['tx'];
        $net_traffic[] = [
            'iface' => $iface,
            'rx_kb' => round($rx_bytes / 3 / 1024, 2),
            'tx_kb' => round($tx_bytes / 3 / 1024, 2),
        ];
    }

    echo json_encode([
        'time'        => date('Y-m-d H:i:s'),
        'df'          => parse_df(),
        'mem'         => parse_mem(),
        'uptime'      => parse_uptime(),
        'sysinfo'     => parse_sysinfo(),
        'procs'       => parse_procs(),
        'ports'       => parse_network_ports(),
        'cpu'         => $cpu_data,
        'net_traffic' => $net_traffic,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root {
    --bg:      #080b10;
    --panel:   #0e1218;
    --border:  #1c2130;
    --text:    #c8d0e0;
    --muted:   #4a5470;
    --dim:     #2a3048;
    --accent:  #3d8ef8;
    --accent2: #00d4aa;
    --warn:    #f5a623;
    --danger:  #f04060;
    --mono:    'IBM Plex Mono', monospace;
    --sans:    'IBM Plex Sans', sans-serif;
}

* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding: 1.5rem 2rem 3rem;
}

/* ── HEADER ── */
header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border);
    gap: 1rem;
    flex-wrap: wrap;
}

.logo { display:flex; align-items:baseline; gap:0.6rem; }
.logo h1 { font-family:var(--mono); font-size:1.05rem; font-weight:600; color:var(--accent); letter-spacing:.05em; }
.logo .host { font-family:var(--mono); font-size:0.75rem; color:var(--muted); }

.header-right { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }

#last-updated { font-family:var(--mono); font-size:.72rem; color:var(--muted); }



#refresh-btn {
    font-family:var(--mono); font-size:.78rem; font-weight:600;
    padding:.5rem 1.2rem;
    background:transparent; color:var(--accent);
    border:1px solid var(--accent); border-radius:6px;
    cursor:pointer; letter-spacing:.04em;
    transition:background .15s, color .15s, box-shadow .15s;
}
#refresh-btn:hover { background:var(--accent); color:#fff; box-shadow:0 0 18px rgba(61,142,248,.35); }
#refresh-btn:disabled { opacity:.4; cursor:not-allowed; background:transparent; color:var(--accent); box-shadow:none; }

/* ── GRID ── */
.grid { display:grid; grid-template-columns:repeat(12,1fr); gap:1rem; }
.c3  { grid-column:span 3; }
.c4  { grid-column:span 4; }
.c5  { grid-column:span 5; }
.c6  { grid-column:span 6; }
.c7  { grid-column:span 7; }
.c8  { grid-column:span 8; }
.c12 { grid-column:span 12; }
@media(max-width:1100px){.c3,.c4,.c5,.c6,.c7,.c8{grid-column:span 12;}}

/* ── CARD ── */
.card {
    background:var(--panel); border:1px solid var(--border);
    border-radius:10px; padding:1.2rem 1.4rem;
    position:relative; overflow:hidden; transition:opacity .3s;
}
.card.loading { opacity:.45; }
.card-label {
    font-family:var(--mono); font-size:.65rem; letter-spacing:.12em;
    color:var(--muted); text-transform:uppercase; margin-bottom:1rem;
    display:flex; align-items:center; gap:.4rem;
}
.card-label::before {
    content:''; display:inline-block;
    width:5px; height:5px; border-radius:50%;
    background:var(--accent2); flex-shrink:0;
}
.card.loading::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg,transparent 0%,rgba(255,255,255,.03) 50%,transparent 100%);
    background-size:200% 100%; animation:shimmer 1.2s infinite;
}
@keyframes shimmer { 0%{background-position:-200% 0} 100%{background-position:200% 0} }
@keyframes fadeUp  { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }
.updated { animation:fadeUp .35s ease both; }

/* ── DISK ── */
.disk-row { display:grid; grid-template-columns:1fr auto; gap:.3rem .6rem; align-items:center; margin-bottom:.9rem; }
.disk-row:last-child { margin-bottom:0; }
.disk-mount { font-family:var(--mono); font-size:.72rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.disk-pct   { font-family:var(--mono); font-size:.72rem; text-align:right; min-width:2.5rem; }
.disk-bar-wrap { grid-column:1/-1; height:5px; background:var(--dim); border-radius:3px; overflow:hidden; }
.disk-bar { height:100%; border-radius:3px; transition:width .6s cubic-bezier(.4,0,.2,1); }
.disk-sub { font-family:var(--mono); font-size:.65rem; color:var(--muted); margin-top:-.6rem; margin-bottom:.7rem; }

/* ── MEMORY ── */
.mem-layout { display:flex; align-items:center; gap:1.8rem; }
.gauge-wrap { position:relative; width:96px; height:96px; flex-shrink:0; }
.gauge-wrap svg { transform:rotate(-90deg); overflow:visible; }
.gauge-bg { fill:none; stroke:var(--dim); stroke-width:9; }
.gauge-fg { fill:none; stroke-width:9; stroke-linecap:round; transition:stroke-dashoffset .7s cubic-bezier(.4,0,.2,1),stroke .4s; }
.gauge-text { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
.gauge-pct  { font-family:var(--mono); font-size:1.3rem; font-weight:600; line-height:1; }
.gauge-sub2 { font-family:var(--mono); font-size:.55rem; color:var(--muted); margin-top:2px; }
.mem-stats  { flex:1; }
.mem-stat-row { display:flex; justify-content:space-between; align-items:center; padding:.28rem 0; border-bottom:1px solid var(--border); font-size:.78rem; }
.mem-stat-row:last-child { border-bottom:none; }
.mem-stat-row .k { color:var(--muted); font-size:.7rem; }
.mem-stat-row .v { font-family:var(--mono); font-size:.75rem; }

/* ── UPTIME ── */
.uptime-val { font-family:var(--mono); font-size:1.6rem; font-weight:600; color:var(--accent2); margin-bottom:.8rem; line-height:1; }
.load-bars  { display:flex; flex-direction:column; gap:.4rem; }
.load-row   { display:grid; grid-template-columns:2.8rem 1fr 2.5rem; align-items:center; gap:.5rem; font-family:var(--mono); font-size:.7rem; }
.load-bar-wrap { height:4px; background:var(--dim); border-radius:2px; overflow:hidden; }
.load-bar   { height:100%; background:var(--accent); border-radius:2px; transition:width .6s cubic-bezier(.4,0,.2,1); }
.load-val   { color:var(--muted); text-align:right; }

/* ── CPU ── */
.cpu-overall { display:flex; align-items:baseline; gap:.5rem; margin-bottom:1rem; }
.cpu-big     { font-family:var(--mono); font-size:2.2rem; font-weight:600; line-height:1; transition:color .3s; }
.cpu-unit    { font-family:var(--mono); font-size:.7rem; color:var(--muted); }
.cpu-cores   { display:flex; flex-direction:column; gap:.35rem; }
.cpu-core-row { display:grid; grid-template-columns:3rem 1fr 2.8rem; align-items:center; gap:.45rem; font-family:var(--mono); font-size:.68rem; }
.cpu-core-bar-wrap { height:4px; background:var(--dim); border-radius:2px; overflow:hidden; }
.cpu-core-bar { height:100%; border-radius:2px; transition:width .6s cubic-bezier(.4,0,.2,1),background .3s; }
.cpu-pct-val { text-align:right; color:var(--muted); }

/* ── NET TRAFFIC ── */
.net-iface-block { margin-bottom:1rem; }
.net-iface-block:last-child { margin-bottom:0; }
.net-iface-name  { font-family:var(--mono); font-size:.7rem; color:var(--text); margin-bottom:.4rem; }
.net-rate-row    { display:grid; grid-template-columns:1.2rem 1fr 5rem; align-items:center; gap:.4rem; margin-bottom:.25rem; font-family:var(--mono); font-size:.68rem; }
.net-rate-bar-wrap { height:4px; background:var(--dim); border-radius:2px; overflow:hidden; }
.net-rate-bar    { height:100%; border-radius:2px; transition:width .6s cubic-bezier(.4,0,.2,1); }
.net-rate-val    { text-align:right; color:var(--muted); }
.net-rx-bar { background:var(--accent2); }
.net-tx-bar { background:var(--accent); }

/* ── SYSINFO ── */
.sysinfo-grid { display:grid; grid-template-columns:auto 1fr; gap:.4rem 1rem; font-size:.78rem; }
.sysinfo-grid .k { color:var(--muted); font-size:.7rem; }
.sysinfo-grid .v { font-family:var(--mono); font-size:.73rem; word-break:break-all; }

/* ── TABLE ── */
.dash-table { width:100%; border-collapse:collapse; font-size:.73rem; }
.dash-table th { font-family:var(--mono); font-size:.62rem; letter-spacing:.08em; color:var(--muted); text-transform:uppercase; text-align:left; padding:0 .4rem .6rem; border-bottom:1px solid var(--border); }
.dash-table td { font-family:var(--mono); font-size:.72rem; padding:.4rem .4rem; border-bottom:1px solid var(--border); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px; }
.dash-table tr:last-child td { border-bottom:none; }
.dash-table .num { text-align:right; color:var(--accent); }
.hi   { color:var(--warn); }
.crit { color:var(--danger); }
</style>
</head>
<body>

<header>
    <div class="logo">
        <h1>◈ SYSBOARD</h1>
        <span class="host" id="hostname">—</span>
    </div>
    <div class="header-right">
        <span id="last-updated">Not loaded</span>
        <button id="refresh-btn" onclick="doRefresh()">⟳ Refresh</button>
    </div>
</header>

<div class="grid">

    <!-- Disk Space -->
    <div class="card c5 loading" id="card-df">
        <div class="card-label">Disk Space</div>
        <div id="df-content"><span style="color:var(--muted);font-size:.75rem;font-family:var(--mono)">—</span></div>
    </div>

    <!-- Memory -->
    <div class="card c4 loading" id="card-mem">
        <div class="card-label">Memory</div>
        <div class="mem-layout">
            <div class="gauge-wrap">
                <svg viewBox="0 0 96 96" width="96" height="96">
                    <circle class="gauge-bg" cx="48" cy="48" r="40"/>
                    <circle class="gauge-fg" id="gauge-fg" cx="48" cy="48" r="40"
                        stroke-dasharray="251.2" stroke-dashoffset="251.2" stroke="var(--accent2)"/>
                </svg>
                <div class="gauge-text">
                    <span class="gauge-pct" id="mem-pct">—</span>
                    <span class="gauge-sub2">USED</span>
                </div>
            </div>
            <div class="mem-stats" id="mem-stats">
                <div class="mem-stat-row"><span class="k">Total</span><span class="v">—</span></div>
                <div class="mem-stat-row"><span class="k">Used</span><span class="v">—</span></div>
                <div class="mem-stat-row"><span class="k">Free</span><span class="v">—</span></div>
                <div class="mem-stat-row"><span class="k">Available</span><span class="v">—</span></div>
            </div>
        </div>
    </div>

    <!-- CPU -->
    <div class="card c3 loading" id="card-cpu">
        <div class="card-label">CPU Usage</div>
        <div class="cpu-overall">
            <span class="cpu-big" id="cpu-overall">—</span>
            <span class="cpu-unit">%</span>
        </div>
        <div class="cpu-cores" id="cpu-cores">
            <span style="color:var(--muted);font-size:.72rem;font-family:var(--mono)">—</span>
        </div>
    </div>

    <!-- Uptime / Load -->
    <div class="card c4 loading" id="card-uptime">
        <div class="card-label">Uptime &amp; Load</div>
        <div class="uptime-val" id="uptime-val">—</div>
        <div class="load-bars" id="load-bars">
            <div class="load-row"><span style="color:var(--muted)">1m</span><div class="load-bar-wrap"><div class="load-bar" style="width:0%"></div></div><span class="load-val">—</span></div>
            <div class="load-row"><span style="color:var(--muted)">5m</span><div class="load-bar-wrap"><div class="load-bar" style="width:0%"></div></div><span class="load-val">—</span></div>
            <div class="load-row"><span style="color:var(--muted)">15m</span><div class="load-bar-wrap"><div class="load-bar" style="width:0%"></div></div><span class="load-val">—</span></div>
        </div>
    </div>

    <!-- Network Traffic -->
    <div class="card c4 loading" id="card-traffic">
        <div class="card-label">Network Traffic (3s avg)</div>
        <div id="traffic-content"><span style="color:var(--muted);font-size:.75rem;font-family:var(--mono)">—</span></div>
    </div>

    <!-- System Info -->
    <div class="card c4 loading" id="card-sysinfo">
        <div class="card-label">System Info</div>
        <div class="sysinfo-grid" id="sysinfo-grid">
            <span class="k">Hostname</span><span class="v">—</span>
            <span class="k">Kernel</span><span class="v">—</span>
            <span class="k">Arch</span><span class="v">—</span>
        </div>
    </div>

    <!-- Processes -->
    <div class="card c8 loading" id="card-procs">
        <div class="card-label">Top Processes (by CPU)</div>
        <table class="dash-table">
            <thead><tr>
                <th>PID</th><th>User</th><th>Command</th>
                <th style="text-align:right">CPU%</th><th style="text-align:right">MEM%</th>
            </tr></thead>
            <tbody id="procs-body">
                <tr><td colspan="5" style="color:var(--muted);padding:.8rem .4rem">—</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Listening Ports -->
    <div class="card c4 loading" id="card-ports">
        <div class="card-label">Listening Ports</div>
        <table class="dash-table">
            <thead><tr><th>Proto</th><th>Port</th><th>Process</th></tr></thead>
            <tbody id="ports-body">
                <tr><td colspan="3" style="color:var(--muted);padding:.8rem .4rem">—</td></tr>
            </tbody>
        </table>
    </div>

</div>

<script>
// ── Utilities ──
function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s ?? '—');
    return d.innerHTML;
}
function setContent(id, html) {
    const el = document.getElementById(id);
    el.innerHTML = html;
    el.classList.remove('updated');
    void el.offsetWidth;
    el.classList.add('updated');
}
function pctColor(p) {
    if (p >= 85) return 'var(--danger)';
    if (p >= 65) return 'var(--warn)';
    return 'var(--accent2)';
}
function cpuColor(p) {
    if (p >= 80) return 'var(--danger)';
    if (p >= 50) return 'var(--warn)';
    return 'var(--accent)';
}
function fmtKB(kb) {
    if (kb >= 1024) return (kb/1024).toFixed(2) + ' MB/s';
    return kb.toFixed(1) + ' KB/s';
}

// ── Renderers ──
function renderDf(data) {
    if (!data?.length) { setContent('df-content','<span style="color:var(--muted);font-size:.75rem;font-family:var(--mono)">No data</span>'); return; }
    let html = '';
    data.forEach(d => {
        const col = pctColor(d.pct);
        html += `<div class="disk-row">
            <span class="disk-mount">${esc(d.mount)}</span>
            <span class="disk-pct" style="color:${col}">${d.pct}%</span>
            <div class="disk-bar-wrap"><div class="disk-bar" style="width:${d.pct}%;background:${col}"></div></div>
        </div>
        <div class="disk-sub">${d.used} GiB / ${d.total} GiB &nbsp;·&nbsp; avail ${d.avail} GiB</div>`;
    });
    setContent('df-content', html);
}

function renderMem(d) {
    const col = pctColor(d.pct);
    const offset = 251.2 * (1 - d.pct / 100);
    document.getElementById('mem-pct').textContent = d.pct + '%';
    document.getElementById('mem-pct').style.color  = col;
    const fg = document.getElementById('gauge-fg');
    fg.style.strokeDashoffset = offset;
    fg.style.stroke = col;
    setContent('mem-stats', `
        <div class="mem-stat-row"><span class="k">Total</span><span class="v">${d.total} MB</span></div>
        <div class="mem-stat-row"><span class="k">Used</span><span class="v">${d.used} MB</span></div>
        <div class="mem-stat-row"><span class="k">Free</span><span class="v">${d.free} MB</span></div>
        <div class="mem-stat-row"><span class="k">Available</span><span class="v">${d.available} MB</span></div>
    `);
}

function renderCpu(data) {
    if (!data?.length) return;
    const overall = data.find(c => c.name === 'cpu');
    const cores   = data.filter(c => c.name !== 'cpu');
    if (overall) {
        const el = document.getElementById('cpu-overall');
        el.textContent = overall.pct;
        el.style.color = cpuColor(overall.pct);
    }
    let html = '';
    cores.forEach(c => {
        const col = cpuColor(c.pct);
        html += `<div class="cpu-core-row">
            <span style="color:var(--muted)">${esc(c.name)}</span>
            <div class="cpu-core-bar-wrap"><div class="cpu-core-bar" style="width:${c.pct}%;background:${col}"></div></div>
            <span class="cpu-pct-val">${c.pct}%</span>
        </div>`;
    });
    setContent('cpu-cores', html || '<span style="color:var(--muted);font-size:.7rem;font-family:var(--mono)">No core data</span>');
}

function renderUptime(d) {
    document.getElementById('uptime-val').textContent = d.uptime || '—';
    const loads = [d.load1, d.load5, d.load15];
    document.querySelectorAll('#load-bars .load-row').forEach((row, i) => {
        const v = loads[i];
        const col = v >= 3 ? 'var(--danger)' : v >= 1.5 ? 'var(--warn)' : 'var(--accent)';
        row.querySelector('.load-bar').style.width      = Math.min(v*25,100) + '%';
        row.querySelector('.load-bar').style.background = col;
        row.querySelector('.load-val').textContent      = v.toFixed(2);
    });
}

function renderTraffic(data) {
    if (!data?.length) { setContent('traffic-content','<span style="color:var(--muted);font-size:.75rem;font-family:var(--mono)">No data</span>'); return; }
    const maxKB = Math.max(1, ...data.map(d => Math.max(d.rx_kb, d.tx_kb)));
    let html = '';
    data.forEach(d => {
        const rxPct = Math.min(d.rx_kb / maxKB * 100, 100);
        const txPct = Math.min(d.tx_kb / maxKB * 100, 100);
        html += `<div class="net-iface-block">
            <div class="net-iface-name">${esc(d.iface)}</div>
            <div class="net-rate-row">
                <span style="color:var(--accent2)">↓</span>
                <div class="net-rate-bar-wrap"><div class="net-rate-bar net-rx-bar" style="width:${rxPct}%"></div></div>
                <span class="net-rate-val">${fmtKB(d.rx_kb)}</span>
            </div>
            <div class="net-rate-row">
                <span style="color:var(--accent)">↑</span>
                <div class="net-rate-bar-wrap"><div class="net-rate-bar net-tx-bar" style="width:${txPct}%"></div></div>
                <span class="net-rate-val">${fmtKB(d.tx_kb)}</span>
            </div>
        </div>`;
    });
    setContent('traffic-content', html);
}

function renderSysinfo(d) {
    document.getElementById('hostname').textContent = d.hostname;
    setContent('sysinfo-grid', `
        <span class="k">Hostname</span><span class="v">${esc(d.hostname)}</span>
        <span class="k">Kernel</span><span class="v">${esc(d.kernel)}</span>
        <span class="k">Arch</span><span class="v">${esc(d.arch)}</span>
        <span class="k">Full</span><span class="v" style="color:var(--muted)">${esc(d.raw)}</span>
    `);
}

function renderProcs(data) {
    let html = '';
    data.forEach(p => {
        const f = parseFloat(p.cpu);
        const cls = f >= 50 ? 'crit' : f >= 20 ? 'hi' : '';
        html += `<tr><td>${esc(p.pid)}</td><td>${esc(p.user)}</td><td>${esc(p.cmd)}</td>
            <td class="num ${cls}">${p.cpu}</td><td class="num">${p.mem}</td></tr>`;
    });
    setContent('procs-body', html || '<tr><td colspan="5" style="color:var(--muted)">No data</td></tr>');
}

function renderPorts(data) {
    let html = '';
    data.forEach(p => {
        html += `<tr>
            <td style="color:var(--muted)">${esc(p.proto)}</td>
            <td style="color:var(--accent)">${esc(p.port)}</td>
            <td>${esc(p.process)}</td>
        </tr>`;
    });
    setContent('ports-body', html || '<tr><td colspan="3" style="color:var(--muted)">No data</td></tr>');
}

// ── Loading state ──
function setLoading(on) {
    document.querySelectorAll('.card').forEach(c => on ? c.classList.add('loading') : c.classList.remove('loading'));
    const btn = document.getElementById('refresh-btn');
    btn.disabled = on;
    btn.textContent = on ? 'Loading…' : '⟳ Refresh';
}

// ── Main refresh ──
async function refresh() {
    setLoading(true);
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'fetch=1'
        });
        const d = await res.json();
        renderDf(d.df);
        renderMem(d.mem);
        renderCpu(d.cpu);
        renderUptime(d.uptime);
        renderTraffic(d.net_traffic);
        renderSysinfo(d.sysinfo);
        renderProcs(d.procs);
        renderPorts(d.ports);
        document.getElementById('last-updated').textContent = 'Updated at ' + d.time;
    } catch (e) {
        document.getElementById('last-updated').textContent = 'Update failed: ' + e.message;
    } finally {
        setLoading(false);
    }
}

// ── Manual refresh ──
function doRefresh() { refresh(); }

// Keyboard shortcut R
document.addEventListener('keydown', e => {
    if ((e.key === 'r' || e.key === 'R') && document.activeElement.tagName !== 'INPUT') doRefresh();
});

// Initial load
refresh();
</script>
</body>
</html>
