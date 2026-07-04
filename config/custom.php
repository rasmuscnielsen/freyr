<?php

return [
    'admin_emails' => explode(',', env('ADMIN_EMAILS') ?? ''),
    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', true),
];
