<?php

// Database Configuration
define('DB_SERVER', 'mysql320.phy.lolipop.lan'); // [cite: 9] Corrected server name
define('DB_USERNAME', 'LAA1611970'); // [cite: 9]
define('DB_PASSWORD', 'Mhu1FNyK'); // [cite: 9]
define('DB_NAME_HANDOVER', 'LAA1611970-handover'); // [cite: 9]
define('DB_NAME_CUSTOMERS', 'LAA1611970-customers'); // [cite: 9]
define('DB_NAME_WORKERS', 'LAA1611970-workers'); // [cite: 9]

// Error/Return Codes
define('APP_RET_OK', 0);
define('APP_RET_NG', -11);
define('APP_RET_DB_ERR', -1);
define('APP_SESSION_ERR', 999);

// Application Configuration
define('SESSION_TIME', 600000); // If needed

// Approval Status Constants (Derived from document flow [cite: 24, 15, 16])
// These represent the *next expected* status or current state
define('STATUS_TEXT_DRAFT', 'Draft'); // Implied initial state before submission
define('STATUS_TEXT_WAITING_DEPT_HEAD', 'Waiting for confirmation from department head'); // [cite: 24]
define('STATUS_TEXT_WAITING_DIVISION_HEAD', 'Waiting for confirmation from division head'); // [cite: 24] (Manager)
define('STATUS_TEXT_WAITING_DIRECTOR', 'Waiting for confirmation from director'); // [cite: 24]
define('STATUS_TEXT_WAITING_EXECUTIVE_DIRECTOR', 'Waiting for confirmation from executive director'); // [cite: 24]
define('STATUS_TEXT_WAITING_SENIOR_MANAGING_DIRECTOR', 'Waiting for confirmation from senior managing director'); // [cite: 24]
define('STATUS_TEXT_WAITING_PRESIDENT', 'Waiting for confirmation from president'); // [cite: 24]
define('STATUS_TEXT_WAITING_GENERAL_AFFAIRS', 'Waiting for confirmation from general affairs'); // [cite: 24]
define('STATUS_TEXT_CONFIRMATION_COMPLETED', 'Confirmation completed'); // [cite: 24]
define('STATUS_TEXT_REJECTED', 'Rejected'); // Implied from dt_denied
define('STATUS_TEXT_ALL', 'All'); // For filtering [cite: 24]

// Email Configuration
define('EMAIL_FROM_ADDRESS', 'noreply@example.com'); // Ganti dengan alamat email yang valid
define('EMAIL_FROM_NAME', 'Audit Handover System');

// Default User ID for system actions if needed (e.g., if an approver ID is not found)
define('DEFAULT_APPROVER_EMAIL', 'admin@iwasakitax.com'); // Ganti dengan email admin
define('DEFAULT_APPROVER_NAME', 'System Admin');

?>