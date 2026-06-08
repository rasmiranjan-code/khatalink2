<?php
// Razorpay API Configuration
define('RZP_KEY_ID',     'rzp_test_SkFdv64cqmaBmF');
define('RZP_KEY_SECRET', 'Yn5fYHO4ePYno5HOGhEQvRGe');
define('RZP_CURRENCY', 'INR');

// Define this secret in Razorpay Dashboard > Settings > Webhooks
define('RZP_WEBHOOK_SECRET', 'khata_webhook_secret_123'); 

// Platform Commission Logic
define('MONTHLY_PLATFORM_COMMISSION_PERCENT', 3); // Customer pays base + 3%
define('BOND_PLATFORM_COMMISSION_PERCENT', 3);    // Customer pays base + 3%
define('LEDGER_PLATFORM_COMMISSION_PERCENT', 3);  // Customer pays base + 3%

// Set to 'live' when you move to production
define('RZP_MODE', 'test'); 
?>