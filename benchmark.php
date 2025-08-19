<?php

/**
 * Benchmark — Advanced
 * 
 * This PHP script provides a comprehensive benchmarking tool for server environments,
 * compatible with PHP 5.3+. It measures and reports performance across several subsystems:
 * MySQL, disk I/O, CPU, memory, and network latency. Results are scored, visualized, and
 * accompanied by optimization recommendations.
 * 
 * Features:
 * - Configurable via GET form: server name, environment, MySQL credentials, query count, disk size, etc.
 * - System info detection: CPU cores, Docker/container status, memory.
 * - MySQL benchmarks: single inserts, transactional inserts, bulk inserts, selects.
 * - Disk benchmarks: sequential and random read/write with multiple block sizes.
 * - CPU benchmarks: Pi calculation and prime sieve.
 * - Memory benchmark: allocation and peak usage.
 * - Network benchmark: TCP connect latency to configurable host/port.
 * - Scoring: Normalizes results against reference values for each subsystem.
 * - Recommendations: Generates actionable optimization tips based on results.
 * - UI: Responsive HTML/CSS, metric bars, details, JSON export/download.
 * 
 * Functions:
 * - html($s): Escapes HTML for safe output.
 * - timer_start(), timer_end($start): Microsecond timing helpers.
 * - detect_cpu_cores(): Detects number of CPU cores (Linux, Windows).
 * - is_docker(): Detects if running inside Docker/container.
 * - sys_info(): Collects system info snapshot.
 * - generate_recommendations(...): Outputs optimization tips based on metrics.
 * - desc_better_lower($value, $ref): Descriptor for metrics where lower is better.
 * - desc_better_higher($value, $ref): Descriptor for metrics where higher is better.
 * - desc_latency_ms($latencies_ms): Descriptor for network latency.
 * - render_bar(...): Renders metric bar with value and descriptor.
 * 
 * Security:
 * - MySQL credentials are overrideable via form, but database name is enforced as 'testdb'.
 * - All user inputs are sanitized for HTML output.
 * 
 * Usage:
 * 1. Open the script in a browser.
 * 2. Fill in the desired parameters and click "Run Benchmark".
 * 3. View results, recommendations, and export JSON.
 * 
 * Note:
 * - Uses deprecated mysql_* functions for compatibility with PHP 5.3.
 * - Designed for minimal dependencies and easy deployment.
 */
// ============================
// Konfigurasi
// ============================
// MySQL credentials: do not hardcode secrets — overrideable via form (GET)
$host = isset($_GET['db_host']) ? $_GET['db_host'] : '127.0.0.1';
$port = isset($_GET['db_port']) ? $_GET['db_port'] : '3306';
$user = isset($_GET['db_user']) ? $_GET['db_user'] : 'root';
$pass = isset($_GET['db_pass']) ? $_GET['db_pass'] : '';
// ENFORCE: database name must be 'testdb' regardless of form input
$db   = 'testdb';
$total = 1000; // default jumlah query MySQL (can override by form)

// Helper
function html($s)
{
    return htmlspecialchars($s);
}
function timer_start()
{
    return microtime(true);
}
function timer_end($start)
{
    return microtime(true) - $start;
}

// System info helpers
function detect_cpu_cores()
{
    // try /proc/cpuinfo (linux)
    if (is_readable('/proc/cpuinfo')) {
        $c = file_get_contents('/proc/cpuinfo');
        preg_match_all('/^processor\\s*:/m', $c, $m);
        if (isset($m[0])) return count($m[0]);
    }
    // try nproc
    $n = trim(@shell_exec('nproc 2>/dev/null'));
    if (is_numeric($n) && intval($n) > 0) return intval($n);
    // Windows env
    $env = getenv('NUMBER_OF_PROCESSORS');
    if ($env !== false && is_numeric($env)) return intval($env);
    return 1;
}
function is_docker()
{
    if (file_exists('/.dockerenv')) return true;
    if (is_readable('/proc/1/cgroup')) {
        $c = @file_get_contents('/proc/1/cgroup');
        if ($c !== false && (strpos($c, 'docker') !== false || strpos($c, 'kubepods') !== false)) return true;
    }
    return false;
}
function sys_info()
{
    $info = array();
    $info['php_version'] = phpversion();
    $info['uname'] = php_uname();
    $info['cores'] = detect_cpu_cores();
    $info['is_docker'] = is_docker() ? 'yes' : 'no';
    // memory limit & total memory (if available)
    $info['memory_limit'] = ini_get('memory_limit');
    if (is_readable('/proc/meminfo')) {
        $m = file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\\s+(\\d+) kB/i', $m, $mm)) {
            $info['mem_kb'] = intval($mm[1]);
        }
    }
    return $info;
}

// UI & styles
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>Benchmark — Advanced</title>';
echo '<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#0f172a;color:#e6eef9;margin:0;padding:24px}
.wrap{max-width:1100px;margin:0 auto}
.card{background:#020617;border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 6px 30px rgba(2,6,23,.6);border:1px solid rgba(255,255,255,.03)}
.row{display:flex;flex-wrap:wrap;margin:-8px}
.col{padding:8px;box-sizing:border-box}
.col-33{width:33%}
.col-50{width:50%}
.col-100{width:100%}
h1{margin:0 0 8px;color:#60a5fa}
label{display:block;margin-bottom:6px;color:#94a3b8;font-size:13px}
input[type=text],select,input[type=number]{width:100%;padding:8px;border-radius:8px;border:1px solid #243244;background:#04102b;color:#e6eef9}
.btn{display:inline-block;padding:10px 14px;border-radius:8px;background:#60a5fa;color:#04102b;border:none;cursor:pointer;font-weight:700}
.small{font-size:13px;color:#94a3b8}
.metric-row{display:flex;align-items:center;margin:10px 0}
.metric-label{flex:0 0 220px;min-width:160px;color:#cbd5e1;font-weight:600;padding-right:12px}
.metric-body{display:flex;align-items:center;flex:1}
.bar{flex:1;height:14px;background:rgba(96,165,250,.12);border-radius:8px;overflow:hidden}
.bar-fill{height:100%;background:linear-gradient(90deg,#06b6d4,#60a5fa);width:0;transition:width .6s}
.metric-value{width:70px;text-align:right;font-weight:700;color:#e6eef9;margin-left:12px}
.jsonbox{background:#03102a;padding:12px;border-radius:8px;color:#e6eef9;overflow:auto;max-height:240px}
.legend{font-size:13px;color:#94a3b8;margin-top:8px}
.footer{color:#94a3b8;text-align:center;margin-top:18px}
@media(max-width:900px){.col-33,.col-50{width:100%}}
</style></head><body><div class="wrap">';
echo '<div class="card"><h1>Benchmark — Advanced</h1><div class="small">Tekan <b>Run Benchmark</b> untuk memulai. Hasil dapat di-download (JSON).</div></div>';

// Form: metadata + options
// set defaults untuk tampilan form
// replace static 'myserver' default with actual host name
$detected_host = (function_exists('gethostname') && gethostname() !== false) ? gethostname() : php_uname('n');
$form_server_default = isset($_GET['server_name']) ? html($_GET['server_name']) : html($detected_host);
$form_env = isset($_GET['env']) ? $_GET['env'] : 'baremetal';

echo '<form method="get" class="card">';
echo '<div class="row">';
echo '<div class="col col-33"><label>Server name</label><input type="text" name="server_name" value="' . $form_server_default . '" placeholder="app-prod-01"></div>';
echo '<div class="col col-33"><label>Environment</label>'
    . '<select name="env">'
    . '<option value=""' . ($form_env === '' ? ' selected' : '') . '>-- pilih --</option>'
    . '<option value="baremetal"' . ($form_env === 'baremetal' ? ' selected' : '') . '>Baremetal</option>'
    . '<option value="docker"' . ($form_env === 'docker' ? ' selected' : '') . '>Docker</option>'
    . '<option value="vm"' . ($form_env === 'vm' ? ' selected' : '') . '>VM</option>'
    . '</select></div>';
echo '<div class="col col-33"><label>MySQL rows</label><input type="number" name="total" value="' . (isset($_GET['total']) ? html($_GET['total']) : $total) . '" min="10" max="100000"></div>';
echo '</div>';
echo '<div class="row" style="margin-top:12px">';
echo '<div class="col col-33"><label>Disk file size MB</label><input type="number" name="disk_mb" value="' . (isset($_GET['disk_mb']) ? html($_GET['disk_mb']) : 50) . '" min="1" max="1024"></div>';
echo '<div class="col col-33"><label>Random ops (count)</label><input type="number" name="rnd_ops" value="' . (isset($_GET['rnd_ops']) ? html($_GET['rnd_ops']) : 500) . '" min="10" max="50000"></div>';
echo '<div class="col col-33"><label>Network test host</label><input type="text" name="net_host" value="' . (isset($_GET['net_host']) ? html($_GET['net_host']) : 'google.com:80') . '"></div>';
echo '</div>';

$form_db_host = isset($_GET['db_host']) ? html($_GET['db_host']) : '127.0.0.1';
$form_db_port = isset($_GET['db_port']) ? html($_GET['db_port']) : '3306';
$form_db_user = isset($_GET['db_user']) ? html($_GET['db_user']) : 'root';
$form_db_pass = isset($_GET['db_pass']) ? html($_GET['db_pass']) : '';
// show testdb as default in the form
$form_db_name = 'testdb';

echo '<div class="row" style="margin-top:12px">';
echo '<div class="col col-33"><label>DB Host</label><input type="text" name="db_host" value="' . $form_db_host . '" placeholder="127.0.0.1"></div>';
echo '<div class="col col-33"><label>DB Port</label><input type="number" name="db_port" value="' . $form_db_port . '" min="1" max="65535"></div>';
echo '<div class="col col-33"><label>DB Name</label><input type="text" readonly name="db_name" value="' . $form_db_name . '" placeholder="test"></div>';
echo '</div>';
echo '<div class="row" style="margin-top:8px">';
echo '<div class="col col-33"><label>DB User</label><input type="text" name="db_user" value="' . $form_db_user . '" placeholder="root"></div>';
echo '<div class="col col-33"><label>DB Password</label><input type="password" name="db_pass" value="' . $form_db_pass . '" placeholder=""></div>';
echo '<div class="col col-33"></div>';
echo '</div>';

echo '<div style="margin-top:12px"><button class="btn" type="submit" name="run" value="1">Run Benchmark</button> ';
echo '<a href="' . html($_SERVER['PHP_SELF']) . '" class="btn" style="background:#334155;color:#fff;margin-left:8px">Reset</a></div>';
echo '</form>';

// If not run, show system info and exit
if (!isset($_GET['run'])) {
    $sys = sys_info();
    echo '<div class="card"><div class="row">';
    echo '<div class="col col-50"><div class="kv">System Info</div><div class="small">PHP: ' . html($sys['php_version']) . ' · Cores: ' . html($sys['cores']) . ' · Docker: ' . html($sys['is_docker']) . '</div></div>';
    echo '<div class="col col-50"><div class="kv">OS</div><div class="small">' . html($sys['uname']) . '</div></div>';
    echo '</div><div class="legend">Silakan isi parameter dan tekan Run Benchmark.</div></div>';
    echo '<div class="footer">Benchmark tool — minimal dan kompatibel PHP 5.3</div></div></body></html>';
    exit;
}

// Begin benchmark run (collect options)
$total = isset($_GET['total']) ? intval($_GET['total']) : $total;
$disk_mb = isset($_GET['disk_mb']) ? max(1, intval($_GET['disk_mb'])) : 50;
$rnd_ops = isset($_GET['rnd_ops']) ? max(1, intval($_GET['rnd_ops'])) : 500;
$net_host = isset($_GET['net_host']) ? $_GET['net_host'] : 'google.com:80';
// apply defaults when collecting options
// use detected host when form did not provide server_name
$server_name = isset($_GET['server_name']) ? $_GET['server_name'] : $detected_host;
$env = isset($_GET['env']) ? $_GET['env'] : 'baremetal';

// Collect system info snapshot
$sys = sys_info();

// --- MySQL Benchmarks ---
// Use mysql_* (compat PHP 5.3)
// establish connection to server first
$conn = @mysql_connect($host . ':' . $port, $user, $pass);
$mysql_result = array('connected' => false);
if ($conn) {
    // ensure DB exists (existing logic)
    if (!@mysql_select_db($db, $conn)) {
        $safe_db = mysql_real_escape_string($db, $conn);
        $create_sql = "CREATE DATABASE IF NOT EXISTS `{$safe_db}` CHARACTER SET utf8 COLLATE utf8_general_ci";
        if (!@mysql_query($create_sql, $conn)) {
            $mysql_result['error'] = 'Gagal membuat database: ' . $db;
        } else {
            @mysql_select_db($db, $conn);
        }
    }

    if (!isset($mysql_result['error'])) {
        $mysql_result['connected'] = true;
        // create a unique table name per run
        $table_name = 'benchmark_' . time();
        $table_safe = preg_replace('/[^0-9A-Za-z_]/', '', $table_name);
        $table_expr = '`' . $table_safe . '`';
        $mysql_result['table'] = $table_safe;

        // drop/create table using the unique name
        mysql_query("DROP TABLE IF EXISTS {$table_expr}");
        mysql_query("CREATE TABLE {$table_expr} (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), email VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");

        // single inserts
        $t0 = timer_start();
        for ($i = 0; $i < $total; $i++) {
            $n = "User_" . $i;
            $e = "user" . $i . "@test.com";
            mysql_query("INSERT INTO {$table_expr} (name, email) VALUES ('" . mysql_real_escape_string($n, $conn) . "','" . mysql_real_escape_string($e, $conn) . "')");
        }
        $insert_time = timer_end($t0);

        // transactional batch insert
        mysql_query("TRUNCATE TABLE {$table_expr}");
        $t0 = timer_start();
        mysql_query("START TRANSACTION");
        for ($i = 0; $i < $total; $i++) {
            $n = "UserT_" . $i;
            $e = "usert" . $i . "@test.com";
            mysql_query("INSERT INTO {$table_expr} (name, email) VALUES ('" . mysql_real_escape_string($n, $conn) . "','" . mysql_real_escape_string($e, $conn) . "')");
        }
        mysql_query("COMMIT");
        $tx_insert_time = timer_end($t0);

        // multi-row bulk insert in chunks
        mysql_query("TRUNCATE TABLE {$table_expr}");
        $chunk = 100;
        $t0 = timer_start();
        for ($s = 0; $s < $total; $s += $chunk) {
            $vals = array();
            for ($j = 0; $j < $chunk && ($s + $j) < $total; $j++) {
                $idx = $s + $j;
                $n = "UserB_" . $idx;
                $e = "userb" . $idx . "@test.com";
                $vals[] = "('" . mysql_real_escape_string($n, $conn) . "','" . mysql_real_escape_string($e, $conn) . "')";
            }
            mysql_query("INSERT INTO {$table_expr} (name,email) VALUES " . implode(",", $vals));
        }
        $bulk_insert_time = timer_end($t0);

        // select test
        $t0 = timer_start();
        $res = mysql_query("SELECT COUNT(*) as cnt FROM {$table_expr}");
        if ($res) {
            $r = mysql_fetch_assoc($res);
            $count_after = $r['cnt'];
        }

        // random selects by id
        for ($i = 1; $i <= min($total, 1000); $i++) {
            $idq = rand(1, max(1, $total));
            $r = mysql_query("SELECT id FROM {$table_expr} WHERE id = " . intval($idq));
            if ($r) {
                while (mysql_fetch_assoc($r)) {
                }
            }
        }
        $select_time = timer_end($t0);

        $mysql_result['times'] = array(
            'single_insert' => $insert_time,
            'tx_insert' => $tx_insert_time,
            'bulk_insert' => $bulk_insert_time,
            'select' => $select_time,
            'row_count' => isset($count_after) ? intval($count_after) : 0
        );
    }
} else {
    $mysql_result['error'] = 'Gagal koneksi MySQL';
}

// --- Disk Benchmarks ---
// create test file
$disk_file = 'benchmark_testfile.tmp';
$disk_bytes = $disk_mb * 1024 * 1024;
$block_sizes = array(4096, 65536, 1048576); // 4KB,64KB,1MB
$disk_result = array('seq' => array(), 'rnd' => array());
$fh = @fopen($disk_file, 'w+');
if ($fh === false) {
    $disk_result['error'] = 'Tidak dapat membuat file: ' . $disk_file;
} else {
    // fill file once with zeros to size
    ftruncate($fh, $disk_bytes);
    fflush($fh);
    // sequential write with different block sizes
    foreach ($block_sizes as $bs) {
        fseek($fh, 0);
        $chunk = str_repeat("0123456789ABCDEF", max(1, intval($bs / 16)));
        $start = timer_start();
        $written = 0;
        while ($written < $disk_bytes) {
            $towrite = ($disk_bytes - $written) >= $bs ? $bs : ($disk_bytes - $written);
            fwrite($fh, substr($chunk, 0, $towrite));
            $written += $towrite;
        }
        fflush($fh);
        $t = timer_end($start);
        $mbs = ($disk_bytes / 1024 / 1024) / max(0.000001, $t);
        $disk_result['seq'][] = array('block' => $bs, 'time_s' => $t, 'mb_s' => $mbs);
    }
    // sequential read
    foreach ($block_sizes as $bs) {
        fseek($fh, 0);
        $start = timer_start();
        $read = 0;
        while (!feof($fh)) {
            $r = fread($fh, $bs);
            if ($r === false) break;
            $read += strlen($r);
        }
        $t = timer_end($start);
        $mbs = ($read / 1024 / 1024) / max(0.000001, $t);
        $disk_result['seq'][] = array('block' => $bs, 'op' => 'read', 'time_s' => $t, 'mb_s' => $mbs);
    }
    // random writes/reads (rnd_ops with 4KB)
    $bs = 4096;
    $rand_written = 0;
    $start = timer_start();
    for ($i = 0; $i < $rnd_ops; $i++) {
        $pos = rand(0, max(0, intval($disk_bytes - $bs)));
        fseek($fh, $pos);
        fwrite($fh, str_repeat("RND", intval($bs / 3) + 1), $bs);
        $rand_written += $bs;
    }
    fflush($fh);
    $t = timer_end($start);
    $disk_result['rnd'][] = array('write_ops' => $rnd_ops, 'block' => $bs, 'time_s' => $t, 'avg_ms_per_op' => ($t / $rnd_ops * 1000));
    // random reads
    $start = timer_start();
    for ($i = 0; $i < $rnd_ops; $i++) {
        $pos = rand(0, max(0, intval($disk_bytes - $bs)));
        fseek($fh, $pos);
        fread($fh, $bs);
    }
    $t = timer_end($start);
    $disk_result['rnd'][] = array('read_ops' => $rnd_ops, 'block' => $bs, 'time_s' => $t, 'avg_ms_per_op' => ($t / $rnd_ops * 1000));
    fclose($fh);
    clearstatcache();
    $disk_size_actual = filesize($disk_file);
    @unlink($disk_file);
    $disk_result['meta'] = array('requested_mb' => $disk_mb, 'actual_bytes' => $disk_size_actual);
}

// --- CPU Benchmarks ---
$cpu_result = array();
$iters = 3;
$pi_times = array();
for ($it = 0; $it < $iters; $it++) {
    $t0 = timer_start();
    $pi = 0.0;
    $max = 300000;
    for ($k = 0; $k < $max; $k++) {
        $pi += pow(-1, $k) / (2 * $k + 1);
    }
    $pi = $pi * 4;
    $pi_times[] = timer_end($t0);
}
$cpu_result['pi_avg_s'] = array_sum($pi_times) / count($pi_times);
// prime sieve (simple)
$t0 = timer_start();
$N = 100000;
$pr = array();
for ($i = 2; $i <= $N; $i++) $pr[$i] = 1;
for ($p = 2; $p * $p <= $N; $p++) {
    if ($pr[$p]) {
        for ($q = $p * $p; $q <= $N; $q += $p) $pr[$q] = 0;
    }
}
$cpu_result['sieve_time_s'] = timer_end($t0);

// --- Memory Benchmark ---
$mem_result = array();
$t0 = timer_start();
$big = array();
for ($i = 0; $i < 200000; $i++) {
    $big[] = str_repeat("memx", 5);
}
$mem_result['alloc_time_s'] = timer_end($t0);
$mem_result['peak_mb'] = memory_get_peak_usage(true) / (1024 * 1024);
unset($big);

// --- Network benchmark (TCP connect latency)
$net_result = array('target' => $net_host);
list($nhost, $nport) = array(null, null);
if (strpos($net_host, ':') !== false) {
    $parts = explode(':', $net_host, 2);
    $nhost = $parts[0];
    $nport = intval($parts[1]);
} else {
    $nhost = $net_host;
    $nport = 80;
}
$latencies = array();
for ($i = 0; $i < 5; $i++) {
    $t0 = timer_start();
    $fp = @fsockopen($nhost, $nport, $errno, $errstr, 2);
    $t = timer_end($t0);
    if ($fp) {
        fclose($fp);
        $latencies[] = $t * 1000;
    } else {
        $latencies[] = null;
    }
}
$net_result['latencies_ms'] = $latencies;

// --- Scoring & normalization ---
$mysql_insert_ms = isset($mysql_result['times']) ? ($mysql_result['times']['single_insert'] / max(1, $total) * 1000) : 9999;
$disk_avg_mb_s = 0;
if (isset($disk_result['seq']) && count($disk_result['seq']) > 0) {
    // pick write entries or first write
    $sum = 0;
    $cnt = 0;
    foreach ($disk_result['seq'] as $e) {
        if (isset($e['mb_s'])) {
            $sum += $e['mb_s'];
            $cnt++;
        }
    }
    if ($cnt > 0) $disk_avg_mb_s = $sum / $cnt;
}
$cpu_time_pi = isset($cpu_result['pi_avg_s']) ? $cpu_result['pi_avg_s'] : 99;
$memory_peak = isset($mem_result['peak_mb']) ? $mem_result['peak_mb'] : 9999;

// references
$ref = array('mysql_ms' => 5, 'disk_ssd' => 500, 'disk_hdd' => 120, 'disk_nvme' => 2500, 'cpu_time' => 1.5, 'mem_mb' => 200);

// mysql score (lower ms better)
$mysql_score = max(0, min(100, (1 - min(2, $mysql_insert_ms / $ref['mysql_ms'])) * 100));

// disk score (choose by storage type if provided)
$chosen_avg = $ref['disk_ssd'];
if ($storage_type === 'HDD') $chosen_avg = $ref['disk_hdd'];
if ($storage_type === 'NVMe') $chosen_avg = $ref['disk_nvme'];
$disk_score = max(0, min(100, ($disk_avg_mb_s / max(0.0001, $chosen_avg)) * 100));

// cpu score (lower time better)
$cpu_score = max(0, min(100, (1 - min(3, $cpu_time_pi / $ref['cpu_time'])) * 100));

// memory score (lower peak better)
$memory_score = max(0, min(100, (1 - min(3, $memory_peak / $ref['mem_mb'])) * 100));

// overall weighted (cap 0..100)
$overall = ($mysql_score * 0.30) + ($disk_score * 0.35) + ($cpu_score * 0.20) + ($memory_score * 0.15);
if ($overall > 100) $overall = 100;
if ($overall < 0) $overall = 0;
$overall = round($overall, 2);

// -----------------------------------------------------------
// INSERT: definisi fungsi rekomendasi dipindah ke sini
// -----------------------------------------------------------
function generate_recommendations($mysql_insert_ms, $mysql_select_ms, $disk_avg_mb_s, $cpu_time_pi, $memory_peak, $storage_type, $sys, $ref)
{
    echo '<div class="card"><div class="kv">Rekomendasi Optimasi</div><div class="small">';
    // MySQL recommendations
    echo '<b>MySQL</b><br>';
    if ($mysql_insert_ms > $ref['mysql_ms'] * 2) {
        echo '- INSERT lambat (' . number_format($mysql_insert_ms, 2) . ' ms/query). Gunakan transaksi, multi-row inserts, dan bulk load. ';
        echo 'Pertimbangkan ubah: <code>innodb_flush_log_at_trx_commit=2</code> & <code>sync_binlog=0</code> untuk throughput lebih tinggi (uji dulu).</br>';
    } else {
        echo '- INSERT terlihat wajar (' . number_format($mysql_insert_ms, 2) . ' ms/query).</br>';
    }
    if ($mysql_select_ms > $ref['mysql_ms'] * 2) {
        echo '- SELECT lambat (' . number_format($mysql_select_ms, 2) . ' ms/query). Periksa indexing, EXPLAIN, dan naikkan <code>innodb_buffer_pool_size</code>.</br>';
    } else {
        echo '- SELECT terlihat wajar (' . number_format($mysql_select_ms, 2) . ' ms/query).</br>';
    }
    // Suggest pool size if mem info available
    $suggest_pool = 0;
    if (isset($sys['mem_kb']) && $sys['mem_kb'] > 0) {
        $ram_mb = intval($sys['mem_kb'] / 1024);
        $suggest_pool = intval($ram_mb * 0.7);
        echo '- Rekomendasi innodb_buffer_pool_size: ~' . $suggest_pool . 'M (sekitar 70% RAM jika server khusus MySQL).</br>';
    } else {
        echo '- Rekomendasi innodb_buffer_pool_size: 50-80% RAM jika server khusus MySQL.</br>';
    }
    // MySQL config snippet
    echo '<div style="margin:8px 0;padding:10px;background:#04102b;border-radius:6px;"><pre style="white-space:pre-wrap;color:#cbd5e1">';
    echo '[mysqld]' . "\n";
    echo 'innodb_buffer_pool_size = ' . $suggest_pool . 'M' . "\n";
    echo 'innodb_log_file_size = 512M' . "\n";
    echo 'innodb_flush_log_at_trx_commit = 2' . "\n";
    echo 'sync_binlog = 0' . "\n";
    echo 'max_connections = 200' . "\n";
    echo '</pre></div>';
    // Disk recommendations
    echo '<b>Disk & Filesystem</b><br>';
    if ($disk_avg_mb_s <= 50) {
        echo '- Disk throughput rendah (avg ' . number_format($disk_avg_mb_s, 2) . ' MB/s). Pertimbangkan upgrade ke SSD/NVMe atau periksa storage path (HDD di VM/docker). Gunakan mount options: noatime, nodiratime, dan scheduler yang sesuai (deadline untuk HDD, noop/none untuk NVMe).</br>';
    } else {
        echo '- Disk throughput: ' . number_format($disk_avg_mb_s, 2) . ' MB/s.</br>';
    }
    echo '- Perbaikan cepat: gunakan SSD/NVMe, gunakan LVM align/partition alignment, atau pindah DB data file ke disk lebih cepat.</br>';
    // CPU recommendations
    echo '<b>CPU</b><br>';
    if ($cpu_time_pi > $ref['cpu_time'] * 1.5) {
        echo '- CPU relatif lambat (pi avg ' . number_format($cpu_time_pi, 2) . ' s). Pertimbangkan CPU dengan clock lebih tinggi atau lebih banyak core; untuk container, gunakan CPU pinning / limits.</br>';
    } else {
        echo '- CPU performa adekuat (pi avg ' . number_format($cpu_time_pi, 2) . ' s).</br>';
    }
    // Memory recommendations
    echo '<b>Memory</b><br>';
    if ($memory_peak > $ref['mem_mb']) {
        echo '- Peak memory tinggi (' . number_format($memory_peak, 2) . ' MB). Tambah RAM atau turunkan memory_limit/optimalkan aplikasi.</br>';
    } else {
        echo '- Memory usage wajar (peak ' . number_format($memory_peak, 2) . ' MB).</br>';
    }
    echo '- OS tuning: set <code>vm.swappiness=10</code>, sesuaikan <code>vm.dirty_ratio</code>/<code>vm.dirty_background_ratio</code> jika heavy writes.</br>';
    // Docker-specific
    echo '<b>Docker</b><br>';
    if ($sys['is_docker'] === 'yes') {
        echo '- Jika berjalan di Docker: gunakan storage driver overlay2, mount host disk (bind) untuk DB volumes, alokasikan resource (cpus, memory) dan gunakan --cpuset-cpus jika perlu.</br>';
    } else {
        echo '- Bukan container: pastikan service berjalan di baremetal/VM yang sesuai.</br>';
    }
    // Hardware upgrade suggestions
    echo '<b>Upgrade Hardware (prioritas)</b><br>';
    echo '1) Storage: HDD → SSD → NVMe untuk peningkatan I/O terbesar.<br>';
    echo '2) RAM: tambahkan untuk memungkinkan innodb_buffer_pool_size besar; mengurangi I/O swap.<br>';
    echo '3) CPU: lebih banyak core / frekuensi lebih tinggi untuk queries & konkuren tinggi.<br>';
    // Tips
    echo '<b>Tips tambahan</b><br>';
    echo '- Jalankan fio/ioping, capture iostat/vmstat, aktifkan slow query log, dan siapkan monitoring (Prometheus+Grafana).<br>';
    echo '</div></div>';
}

// ====== NEW: pastikan mysql_select_ms terdefinisi sebelum memanggil rekomendasi ======
$mysql_select_ms = (isset($select_time) && $total > 0) ? ($select_time / $total * 1000) : 0;

// ====== NEW: helper untuk menghasilkan keterangan (bagus/rata-rata/perlu perbaikan) ======
function desc_better_lower($value, $ref)
{
    if (!is_numeric($value)) return 'Tidak tersedia';
    if ($value <= $ref) return 'Bagus';
    if ($value <= $ref * 1.5) return 'Rata-rata';
    return 'Perlu perbaikan';
}
function desc_better_higher($value, $ref)
{
    if (!is_numeric($value)) return 'Tidak tersedia';
    if ($value >= $ref) return 'Bagus';
    if ($value >= $ref * 0.7) return 'Rata-rata';
    return 'Perlu perbaikan';
}
function desc_latency_ms($latencies_ms)
{
    $vals = array();
    foreach ((array)$latencies_ms as $v) if (is_numeric($v)) $vals[] = $v;
    if (count($vals) === 0) return 'Timeout / Tidak tersedia';
    $avg = array_sum($vals) / count($vals);
    if ($avg <= 50) return 'Bagus (low latency)';
    if ($avg <= 150) return 'Rata-rata';
    return 'Perlu perbaikan (latensi tinggi)';
}

// Hitung descriptors berdasarkan metrik & referensi
$desc_mysql = desc_better_lower($mysql_insert_ms, $ref['mysql_ms']);
$desc_disk  = desc_better_higher($disk_avg_mb_s, $chosen_avg);
$desc_cpu   = desc_better_lower($cpu_time_pi, $ref['cpu_time']);
$desc_mem   = desc_better_lower($memory_peak, $ref['mem_mb']);
$desc_net   = desc_latency_ms($net_result['latencies_ms']);

// Prepare JSON result
$result = array(
    'meta' => array('server_name' => $server_name, 'env' => $env, 'sys' => $sys, 'run_at' => date('c')),
    'mysql' => $mysql_result,
    'disk' => $disk_result,
    'cpu' => $cpu_result,
    'memory' => $mem_result,
    'network' => $net_result,
    'scores' => array('mysql' => round($mysql_score, 2), 'disk' => round($disk_score, 2), 'cpu' => round($cpu_score, 2), 'memory' => round($memory_score, 2), 'overall' => $overall)
);

// --- Render results ---
echo '<div class="card"><div class="row">';
echo '<div class="col col-50"><div class="kv">Overall Score</div><div style="font-size:28px;font-weight:800;margin-top:6px">' . $result['scores']['overall'] . ' / 100</div></div>';
echo '<div class="col col-50"><div class="kv">Server</div><div class="small">' . html($server_name) . ' · ' . html($env) . ' · Cores: ' . html($sys['cores']) . ' · Docker: ' . html($sys['is_docker']) . '</div></div>';
echo '</div>';

// render metric bars
function render_bar($label, $value, $max = 100, $unit = '', $desc = '')
{
    // format value with 2 decimals for display
    $display = number_format((float)$value, 2);
    echo '<div class="metric-row">';
    echo '<div class="metric-label">' . html($label) . ($unit ? (' <span class="small">(' . $unit . ')</span>') : '') . '</div>';
    echo '<div class="metric-body">';
    $pct = max(0, min(100, ($value / $max) * 100));
    echo '<div class="bar" title="' . html($display) . '"><div class="bar-fill" style="width:' . $pct . '%"></div></div>';
    echo '<div style="text-align:right"><div class="metric-value">' . html($display) . '</div>';
    if ($desc !== '') {
        echo '<div class="small" style="margin-top:4px;color:#9ae6ff">' . html($desc) . '</div>';
    }
    echo '</div>';
    echo '</div>'; // metric-body
    echo '</div>'; // metric-row
}
echo '<div style="margin-top:12px">';
// panggil dengan descriptor
render_bar('MySQL (insert avg ms/query)', $mysql_insert_ms, max(1, $ref['mysql_ms']), 'ms', $desc_mysql);
render_bar('Disk (avg MB/s)', $disk_avg_mb_s, max(1, $chosen_avg), 'MB/s', $desc_disk);
render_bar('CPU (pi avg s)', $cpu_time_pi, max(0.1, $ref['cpu_time']), 's', $desc_cpu);
render_bar('Memory peak MB', $memory_peak, max(1, $ref['mem_mb']), 'MB', $desc_mem);
echo '</div></div>';

// Details cards
echo '<div class="card"><div class="kv">MySQL details</div><div class="small">';
if (isset($result['mysql']['connected']) && $result['mysql']['connected']) {
    echo 'Connected · Rows after: ' . html(isset($result['mysql']['times']['row_count']) ? $result['mysql']['times']['row_count'] : '-') . '<br>';
    echo 'Single insert time: ' . number_format($result['mysql']['times']['single_insert'], 2) . ' s · Transaction insert: ' . number_format($result['mysql']['times']['tx_insert'], 2) . ' s · Bulk insert: ' . number_format($result['mysql']['times']['bulk_insert'], 2) . ' s · Select: ' . number_format($result['mysql']['times']['select'], 2) . ' s';
    // keterangan singkat
    echo '<br><b>Keterangan:</b> ' . html($desc_mysql);
} else {
    echo 'MySQL: ' . $result['mysql']['error'];
}
echo '</div></div>';

// Disk details
echo '<div class="card"><div class="kv">Disk details</div><div class="small">';
if (isset($result['disk']['seq'])) {
    foreach ($result['disk']['seq'] as $d) {
        if (isset($d['op']) && $d['op'] == 'read') {
            echo 'SEQ READ b=' . $d['block'] . ' time=' . number_format($d['time_s'], 2) . 's speed=' . number_format($d['mb_s'], 2) . ' MB/s<br>';
        } else {
            echo 'SEQ WRITE b=' . $d['block'] . ' time=' . number_format($d['time_s'], 2) . 's speed=' . number_format($d['mb_s'], 2) . ' MB/s<br>';
        }
    }
    if (isset($result['disk']['rnd'])) {
        foreach ($result['disk']['rnd'] as $r) {
            if (isset($r['write_ops'])) echo 'RND write ops=' . intval($r['write_ops']) . ' avg_ms/op=' . number_format($r['avg_ms_per_op'], 2) . ' ms<br>';
            if (isset($r['read_ops'])) echo 'RND read ops=' . intval($r['read_ops']) . ' avg_ms/op=' . number_format($r['avg_ms_per_op'], 2) . ' ms<br>';
        }
    }
    echo '<br><b>Keterangan:</b> ' . html($desc_disk);
} else {
    echo html($result['disk']['error']);
}
echo '</div></div>';

// CPU & Memory & Network cards
echo '<div class="card"><div class="kv">CPU details</div><div class="small">Pi avg s: ' . number_format($result['cpu']['pi_avg_s'], 2) . ' · Sieve time s: ' . number_format($result['cpu']['sieve_time_s'], 2) . '<br><b>Keterangan:</b> ' . html($desc_cpu) . '</div></div>';
echo '<div class="card"><div class="kv">Memory</div><div class="small">Alloc time s: ' . number_format($result['memory']['alloc_time_s'], 2) . ' · Peak MB: ' . number_format($result['memory']['peak_mb'], 2) . '<br><b>Keterangan:</b> ' . html($desc_mem) . '</div></div>';
echo '<div class="card"><div class="kv">Network</div><div class="small">Target: ' . html($net_host) . '<br>Latencies ms: ';
$latout = array();
foreach ($result['network']['latencies_ms'] as $l) $latout[] = ($l === null ? 'timeout' : round($l, 2) . 'ms');
echo html(implode(',', $latout));
echo '<br><b>Keterangan:</b> ' . html($desc_net);
echo '</div></div>';

// Tampilkan rekomendasi optimasi -- pindahkan ke sini supaya muncul sebelum card JSON
generate_recommendations(
    $mysql_insert_ms,
    $mysql_select_ms,
    $disk_avg_mb_s,
    $cpu_time_pi,
    $memory_peak,
    (isset($storage_type) ? $storage_type : null),
    $sys,
    $ref
);

// JSON export and view
$json = json_encode($result);
echo '<div class="card"><div class="kv">Export / Raw JSON</div>';
echo '<div style="margin:8px 0"><button class="btn" id="downloadJson">Download JSON</button> <button class="btn" id="openJson" style="background:#334155">View JSON</button></div>';
echo '<div class="jsonbox" id="jsonbox"><pre>' . html(json_encode($result)) . '</pre></div>';
echo '</div>';

// Inline JS for download/view (compatible)
echo '<script type="text/javascript">
(function(){ var data = ' . json_encode($result) . ';
var btn = document.getElementById("downloadJson");
if(btn){ btn.onclick = function(){
    var content = JSON.stringify(data, null, 2);
    try {
        var blob = new Blob([content], {type:"application/json"});
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement("a");
        a.href = url; a.download = "benchmark_"+(data.meta.server_name?data.meta.server_name.replace(/\\s+/g,"_"):"result")+".json";
        document.body.appendChild(a); a.click(); document.body.removeChild(a); window.URL.revokeObjectURL(url);
    } catch(e) {
        window.open("data:application/json;charset=utf-8,"+encodeURIComponent(content));
    }
};}
var v = document.getElementById("openJson");
if(v){ v.onclick = function(){
    var w = window.open(); w.document.open(); w.document.write("<pre>"+JSON.stringify(data,null,2)+"</pre>"); w.document.close();
};}
})();
</script>';

// Footer
echo '<div class="footer">Benchmark — Advanced · Generated ' . date('c') . '</div></div></body></html>';
