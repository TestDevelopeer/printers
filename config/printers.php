<?php

return [
    'low_toner_threshold' => (int) env('PRINTERS_LOW_TONER_THRESHOLD', 15),
    'default_snmp_community' => env('PRINTERS_DEFAULT_SNMP_COMMUNITY', 'public'),
    'default_snmp_version' => env('PRINTERS_DEFAULT_SNMP_VERSION', '2c'),
    'scan_timeout' => (int) env('PRINTERS_SCAN_TIMEOUT', 1000),
    'poll_timeout' => (int) env('PRINTERS_POLL_TIMEOUT', 1000),
    'scan_max_hosts' => (int) env('PRINTERS_SCAN_MAX_HOSTS', 512),
];
