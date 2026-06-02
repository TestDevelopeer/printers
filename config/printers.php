<?php

return [
    'low_toner_threshold' => (int) env('PRINTERS_LOW_TONER_THRESHOLD', 15),
    'default_snmp_community' => env('PRINTERS_DEFAULT_SNMP_COMMUNITY', 'public'),
    'default_snmp_version' => env('PRINTERS_DEFAULT_SNMP_VERSION', '2c'),
    'scan_timeout' => (int) env('PRINTERS_SCAN_TIMEOUT', 1000),
    'poll_timeout' => (int) env('PRINTERS_POLL_TIMEOUT', 1000),
    'scan_max_hosts' => (int) env('PRINTERS_SCAN_MAX_HOSTS', 512),
    'scan_max_sync_seconds' => (int) env('PRINTERS_SCAN_MAX_SYNC_SECONDS', 45),
    'scan_estimated_requests_per_host' => (int) env('PRINTERS_SCAN_ESTIMATED_REQUESTS_PER_HOST', 8),
];
