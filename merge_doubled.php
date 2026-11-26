<?php
// Merges an orphan incident (host = the whole Salesforce subject) into its twin (plain host). DRY RUN unless --apply is given.
//   php tools/merge_doubled.php                 dry run, all technicians: shows what would change
//   php tools/merge_doubled.php --apply         backs up tracker.sqlite first, then merges
// Per pair: the twin keeps its own data; if the orphan's entry time is EARLIER the twin takes the orphan's trackerEntryAt/Source
// (the first time the sync saw the case is the real "logged" time); the orphan's log lines are added to the twin; the orphan is deleted.
// Only pairs where the orphan's host starts with "Server Health Check" and EXACTLY ONE twin exists are touched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php'; require_once __DIR__ . '/../reporting.php';
date_default_timezone_set(cfg('timezone'));
$apply = in_array('--apply', $argv, true);
if ($apply) {
    $db = rtrim(cfg('data_dir'), '/') . '/tracker.sqlite'; $bak = $db . '.bak-merge-' . date('Ymd-His');
    if (!copy($db, $bak)) { fwrite(STDERR, "Could not back up $db - nothing changed\n"); exit(1); }
    echo "Backup: $bak\n\n";
}
$fmt = function ($i) { $b = array(); $e = incident_tracker_entry($i, 0); return ($e['ms'] ? gmdate('Y-m-d H:i', (int) ($e['ms'] / 1000)) . ' UTC' : 'none') . ' (' . $e['src'] . ')'; };
$done = 0; $skip = 0;
foreach (array_keys(team_registry()) as $slug) {
    $all = all_incidents($slug);
    foreach ($all as $id => $orph) {
        $h = isset($orph['host']) ? (string) $orph['host'] : '';
        if (!preg_match('/^server\s+health\s*check/i', $h)) continue;
        $plain = tracker_host_name($h); $twins = array();
        foreach ($all as $id2 => $o) if ($id2 !== $id && strcasecmp(host_only($o), $plain) === 0) $twins[] = $id2;
        if (count($twins) !== 1) { printf("SKIP  %s %s: %d twins (needs exactly 1)\n", $slug, $id, count($twins)); $skip++; continue; }
        $tw = $all[$twins[0]];
        $oe = incident_tracker_entry($orph, 0); $te = incident_tracker_entry($tw, 0);
        $take = $oe['ms'] && (!$te['ms'] || $oe['ms'] < $te['ms']);
        printf("%s  %s\n  orphan %s  entry %s  status=%s\n  twin   %s  entry %s  status=%s\n  -> %s; orphan's %d log line(s) copied; orphan deleted\n\n", $apply ? 'MERGE' : 'WOULD MERGE', $slug,
            $id, $fmt($orph), isset($orph['status']) ? $orph['status'] : '?', $twins[0], $fmt($tw), isset($tw['status']) ? $tw['status'] : '?',
            $take ? "twin takes the orphan's earlier entry time" : "twin keeps its entry time", isset($orph['log']) ? count($orph['log']) : 0);
        if ($apply) {
            if ($take) { $tw['trackerEntryAt'] = $oe['ms']; $tw['trackerEntrySource'] = $oe['src']; }
            foreach ((isset($orph['log']) ? $orph['log'] : array()) as $l) { $l['text'] = '[from duplicate row] ' . $l['text']; if (!isset($tw['log']) || !is_array($tw['log'])) $tw['log'] = array(); $tw['log'][] = $l; }
            add_log($tw, now_ms(), 'Merged a duplicate incident created from the Salesforce subject in the Hostname cell');
            $tw['updated'] = now_ms(); put_incident($slug, $twins[0], $tw); del_incident($slug, $id); bump_rev($slug);
        }
        $done++;
    }
}
printf("%d pair(s) %s, %d skipped.%s\n", $done, $apply ? 'merged' : 'would be merged', $skip, $apply ? '' : "  DRY RUN: nothing changed. Re-run with --apply to merge.");
