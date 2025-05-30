<?php

// Database Configuration [cite: 9]
define('DB_SERVER', 'mysql320.phy.lolipop.lan'); // Corrected server name [cite: 10]
define('DB_USERNAME', 'LAA1611970'); // [cite: 10]
define('DB_PASSWORD', 'Mhu1FNyK'); // [cite: 10]
define('DB_NAME_HANDOVER', 'LAA1611970-handover'); // [cite: 10]
define('DB_NAME_CUSTOMERS', 'LAA1611970-customers'); // [cite: 10]
define('DB_NAME_WORKERS', 'LAA1611970-workers'); // [cite: 10]

// Error/Return Codes
define('APP_RET_OK', 0);
define('APP_RET_NG', -1); // General error
define('APP_RET_DB_ERR', -2);
define('APP_RET_VALIDATION_ERR', -3);
define('APP_RET_AUTH_ERR', -4); // Authorization error
define('APP_RET_NOT_FOUND', -5);

// Approval Status Codes (Internal representation)
// Based on the approval flow described on Home Screen [cite: 24] and t_handover approval fields [cite: 15, 16]
define('STATUS_DRAFT', 0); // Not explicitly in PDF, but useful for initial creation before submission
define('STATUS_PENDING_DEPT_HEAD', 1);         // Waiting for s_superior (dt_approved)
define('STATUS_PENDING_MANAGER', 2);           // Waiting for dt_approved_1
define('STATUS_PENDING_DIRECTOR', 3);          // Waiting for dt_approved_2
define('STATUS_PENDING_EXECUTIVE_DIRECTOR', 4); // Waiting for dt_approved_3 ("Regular approval")
define('STATUS_PENDING_MANAGING_DIRECTOR', 5); // Waiting for dt_approved_4
define('STATUS_PENDING_PRESIDENT', 6);         // Waiting for dt_approved_5
define('STATUS_PENDING_GENERAL_AFFAIRS', 7);   // Waiting for dt_checked
define('STATUS_COMPLETED', 8);                 // All approvals done (dt_checked is filled)
define('STATUS_REJECTED', 9);                  // dt_denied is filled
define('STATUS_HANDED_OVER', 10);              // s_in_charge (Successor ID) is filled after completion

// Text for statuses [cite: 24] (for display and email)
const STATUS_TEXTS = [
    STATUS_DRAFT => "Draft",
    STATUS_PENDING_DEPT_HEAD => "Waiting for confirmation from department head",
    STATUS_PENDING_MANAGER => "Waiting for confirmation from division head", // "division head" in PDF is likely Manager for dt_approved_1
    STATUS_PENDING_DIRECTOR => "Waiting for confirmation from director",
    STATUS_PENDING_EXECUTIVE_DIRECTOR => "Waiting for confirmation from executive director",
    STATUS_PENDING_MANAGING_DIRECTOR => "Waiting for confirmation from senior managing director", // "senior managing director"
    STATUS_PENDING_PRESIDENT => "Waiting for confirmation from president",
    STATUS_PENDING_GENERAL_AFFAIRS => "Waiting for confirmation from general affairs",
    STATUS_COMPLETED => "Confirmation completed",
    STATUS_REJECTED => "Rejected",
    STATUS_HANDED_OVER => "Handed Over to Successor"
];

// Approver Roles (Matches dt_approved_X fields)
define('APPROVER_ROLE_DEPT_HEAD', 1);
define('APPROVER_ROLE_MANAGER', 2);
define('APPROVER_ROLE_DIRECTOR', 3);
define('APPROVER_ROLE_EXECUTIVE_DIRECTOR', 4);
define('APPROVER_ROLE_MANAGING_DIRECTOR', 5);
define('APPROVER_ROLE_PRESIDENT', 6);
define('APPROVER_ROLE_GENERAL_AFFAIRS', 7);


// Email Configuration
define('EMAIL_FROM_ADDRESS', '123@iwasakitax.com'); // Replace with actual from address
define('EMAIL_FROM_NAME', 'Audit Handover System');  // [cite: 30] (example from jQuery params)

// Default user ID for testing (replace with actual session logic)
// In a real scenario, this would come from the portal integration [cite: 6]
// define('CURRENT_USER_ID', '0001'); // Example: predecessor_id from t_worker.s_worker [cite: 20]
// define('CURRENT_USER_NAME', 'Test User');
?>