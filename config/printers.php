<?php

return [
    'low_toner_threshold' => (int) env('PRINTERS_LOW_TONER_THRESHOLD', 15),
    'critical_low_toner_threshold' => (int) env('PRINTERS_CRITICAL_LOW_TONER_THRESHOLD', 5),
    'replacement_detection_min_increase' => (int) env('PRINTERS_REPLACEMENT_DETECTION_MIN_INCREASE', 3),
    'default_snmp_community' => env('PRINTERS_DEFAULT_SNMP_COMMUNITY', 'public'),
    'default_snmp_version' => env('PRINTERS_DEFAULT_SNMP_VERSION', '2c'),
    'scan_timeout' => (int) env('PRINTERS_SCAN_TIMEOUT', 1000),
    'poll_timeout' => (int) env('PRINTERS_POLL_TIMEOUT', 1000),
    'scan_max_hosts' => (int) env('PRINTERS_SCAN_MAX_HOSTS', 512),
    'scan_max_sync_seconds' => (int) env('PRINTERS_SCAN_MAX_SYNC_SECONDS', 90),
    'scan_concurrency' => (int) env('PRINTERS_SCAN_CONCURRENCY', 16),
    'scan_ping_concurrency' => (int) env('PRINTERS_SCAN_PING_CONCURRENCY', 32),
    'scan_estimated_snmp_hosts' => (int) env('PRINTERS_SCAN_ESTIMATED_SNMP_HOSTS', 16),
    'scan_estimated_snmp_seconds_per_host' => (float) env('PRINTERS_SCAN_ESTIMATED_SNMP_SECONDS_PER_HOST', 2),
    'daily_snapshot_at' => env('PRINTERS_DAILY_SNAPSHOT_AT', '00:00'),
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],
];
