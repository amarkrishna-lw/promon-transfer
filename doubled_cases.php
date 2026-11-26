<?php
// READ-ONLY. Finds incidents created from a Hostname cell that held the Salesforce subject ("Server Health Check <host> ...")
// and shows whether a second incident exists for the plain host (a double count).
//   php tools/doubled_cases.php            all technicians
//   php tools/doubled_cases.php <slug>     one technician
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php';
date_default_timezone_set(cfg('timezone'));
$slugs = isset($argv[1]) ? array($argv[1]) : array_keys(team_registry());
$tot = 0; $dbl = 0;
foreach ($slugs as $slug) {
    if (!isset(team_registry()[$slug])) { fwrite(STDERR, "Unknown slug $slug\n"); exit(1); }
    $all = all_incidents($slug);
    foreach ($all as $id => $inc) {
        $h = isset($inc['host']) ? (string) $inc['host'] : '';
        if (!preg_match('/^server\s+health\s*check/i', $h)) continue;
        $plain = tracker_host_name($h); $twins = array();
        foreach ($all as $id2 => $o) if ($id2 !== $id && strcasecmp(host_only($o), $plain) === 0) $twins[] = $id2;
        $tot++; if ($twins) $dbl++;
        printf("%-18s %-34s status=%-10s %s\n   host stored: %s\n   plain host:  %s  twin incidents: %s\n", $slug, $id, isset($inc['status']) ? $inc['status'] : '?', $twins ? 'DOUBLED' : 'no twin', $h, $plain, $twins ? implode(', ', $twins) : '-');
    }
}
printf("\n%d incident(s) with the subject in the host, %d with a twin for the plain host. Nothing was changed.\n", $tot, $dbl);
