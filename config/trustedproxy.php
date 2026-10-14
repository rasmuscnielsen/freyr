<?php

return [
    // Comma-separated proxy IPs or CIDR ranges. Trust no proxies by default.
    'proxies' => env('TRUSTED_PROXIES', []),
];
