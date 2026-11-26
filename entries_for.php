<?php
// READ-ONLY. Which cases does Reports credit to a technician as "logged" on a day, and why?
//   php tools/entries_for.php <slug> [YYYY-MM-DD]                 one day, every case listed (default: today, in the site's configured day)
//   php tools/entries_for.php <slug> YYYY-MM-DD YYYY-MM-DD        a range: per-day counts, split by how the entry time was decided
// Shows, per case: the tracker-entry time as the site counts it (fixed EDT) and as real Eastern time, how that time was
// decided (first_seen / historical_alert_date / alert_date_fallback), and the first log line that put the row in the tracker.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php';
require_once __DIR__ . '/../reporting.php';
date_default_timezone_set(cfg('timezone'));
$slug = isset($argv[1]) ? $argv[1] : '';
if (!isset(team_registry()[$slug])) { fwrite(STDERR, "Usage: php tools/entries_for.php <slug> [YYYY-MM-DD]\nSlugs: " . implode(', ', array_keys(team_registry())) . "\n"); exit(1); }
$day = isset($argv[2]) ? $argv[2] : date('Y-m-d'); $to = isset($argv[3]) ? $argv[3] : '';
foreach (array($day, $to) as $x) if ($x !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $x)) { fwrite(STDERR, "Dates must be YYYY-MM-DD\n"); exit(1); }
$edt = new DateTimeZone('Etc/GMT+4'); $est = new DateTimeZone('America/Detroit');
$all = all_incidents($slug); $base = rp_tech_baseline($all);
if ($to !== '') {   // range mode
    $per = array();
    foreach ($all as $id => $inc) {
        $e = incident_tracker_entry($inc, $base); if (!$e['ms']) continue;
        $d = new DateTime('@' . (int) ($e['ms'] / 1000)); $d->setTimezone($edt); $k = $d->format('Y-m-d');
        if ($k < $day || $k > $to) continue;
        if (!isset($per[$k])) $per[$k] = array(); $per[$k][$e['src']] = (isset($per[$k][$e['src']]) ? $per[$k][$e['src']] : 0) + 1;
    }
    ksort($per);
    printf("%s: cases credited as LOGGED per day, %s to %s (site day = fixed EDT)\nbaseline (first sync of this tech): %s\n", $slug, $day, $to, $base ? gmdate('Y-m-d H:i', (int) ($base / 1000)) . ' UTC' : 'none');
    foreach ($per as $k => $srcs) { $t = array_sum($srcs); $parts = array(); foreach ($srcs as $n => $c) $parts[] = "$n=$c"; printf("%s  %3d   %s\n", $k, $t, implode('  ', $parts)); }
    exit(0);
}
$rows = array();
foreach ($all as $id => $inc) {
    $e = incident_tracker_entry($inc, $base);
    if (!$e['ms']) continue;
    $d = new DateTime('@' . (int) ($e['ms'] / 1000)); $d->setTimezone($edt);
    if ($d->format('Y-m-d') !== $day) continue;
    $r = new DateTime('@' . (int) ($e['ms'] / 1000)); $r->setTimezone($est);
    $host = ''; foreach (array(isset($inc['host']) ? $inc['host'] : '', isset($inc['sheet']['host']) ? $inc['sheet']['host'] : '', isset($inc['title']) ? $inc['title'] : '') as $h) { if ($h !== '') { $host = $h; break; } }
    $first = ''; foreach ((array) (isset($inc['log']) ? $inc['log'] : array()) as $l) { if (isset($l['text']) && preg_match('/^(Imported from tracker sheet|Linked to tracker sheet row)/', $l['text'])) { $first = $l['text']; break; } }
    $rows[] = array($d->format('H:i') . ' EDT', $r->format('H:i T') . ' ' . $r->format('D d'), $e['src'], $host, substr($first, 0, 70));
}
usort($rows, function ($a, $b) { return strcmp($a[0], $b[0]); });
printf("%s: %d case(s) credited as LOGGED on %s (site day = fixed EDT)\n", $slug, count($rows), $day);
foreach ($rows as $r) echo implode("  |  ", $r) . "\n";
