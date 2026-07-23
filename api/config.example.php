<?php
/**
 * Copy to config.local.php and fill in. config.local.php is gitignored and must
 * never be committed.
 *
 * Generate a jwt_secret with:  php -r "echo bin2hex(random_bytes(32));"
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'sevensmile_contactrate',
        // Use the dedicated read-write user for Nova's own ai_* tables.
        // Reads of ContactRate data should use a SELECT-only user once one exists.
        'user' => '',
        'pass' => '',
    ],

    'jwt_secret' => '',

    'anthropic_key' => '',

    // Spending guards. Both are optional — omit the block to use the defaults
    // in api/lib/usage.php (80 messages per person per day, 3,000 THB across
    // the office per calendar month). Raising a cap here takes effect on the
    // next request; there is nothing to redeploy.
    'limits' => [
        'daily_message_limit' => 80,
        'monthly_budget_thb'  => 3000,
    ],

    // Origins allowed to call this API. No wildcard.
    'allowed_origins' => [
        'https://ai.sevensmiletourandticket.com',
        'http://localhost:5173',
        'http://localhost:5174',
    ],
];
