<?php
// Start session if using session for user ID
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once 'connect_db.php'; // Includes const.php
require_once 'send_mail.php';
require_once 'handover_utils.php'; // Our new utility functions

// --- Pseudo User Authentication ---
// In a real system, user_id and user_name would come from the portal [cite: 6]
// For now, let's simulate it or allow it to be passed for testing.
$currentUserId = $_SESSION['user_id'] ?? $_POST['current_user_id'] ?? '0001'; // Example, from session or passed
$currentUserName = $_SESSION['user_name'] ?? $_POST['current_user_name'] ?? 'Default User';


$connHandover = connectDB(DB_NAME_HANDOVER);
$connCustomers = connectDB(DB_NAME_CUSTOMERS); // Connection for v_customer
$connWorkers = connectDB(DB_NAME_WORKERS);     // Connection for v_worker

if (!$connHandover) {
    echo json_encode(['error' => 'Unable to connect to handover database.', 'code' => APP_RET_DB_ERR]);
    exit;
}
// Not exiting for customer/worker DB connection failure here, handle in functions

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$params = $_POST; // Use POST for data modification, GET for retrieval if preferred

$result = [];

try {
    switch ($action) {
        case 'getHandoverList':
            $result = getHandoverList($connHandover, $connWorkers, $params);
            break;
        case 'getHandoverData':
            $result = getHandoverData($connHandover, $connCustomers, $connWorkers, $params);
            break;
        case 'createHandover': // New action for explicit creation
            $params['s_predecessor'] = $currentUserId; // Set predecessor from session/auth
            $result = createHandoverData($connHandover, $connWorkers, $params);
            if (isset($result['success']) && $result['success'] && isset($result['email_params'])) {
                sendMail($result['email_params']);
            }
            break;
        case 'updateHandoverData':
            $result = updateHandoverData($connHandover, $connWorkers, $params, $currentUserId);
             if (isset($result['success']) && $result['success'] && isset($result['email_params'])) {
                sendMail($result['email_params']);
            }
            break;
        case 'getApprovalRoot':
            $result = getApprovalRoot($connHandover, $connWorkers, $params);
            break;
        default:
            $result = ['error' => 'Invalid or unspecified action.', 'code' => APP_RET_NG];
            http_response_code(400);
    }
} catch (Exception $e) {
    error_log("Error in handover.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $result = ['error' => 'An unexpected error occurred: ' . $e->getMessage(), 'code' => APP_RET_NG];
    http_response_code(500);
}

echo json_encode($result);

if ($connHandover) $connHandover->close();
if ($connCustomers) $connCustomers->close();
if ($connWorkers) $connWorkers->close();

// --- Main Function Implementations ---

function getHandoverList($connHandover, $connWorkers, $params) {
    // Columns for list display based on Home Screen description [cite: 24]
    // Submission date / CD of involved party / involved party name / representative name / total amount of compensation / predecessor name / status
    $sqlBase = "SELECT h.s_customer, h.dt_submitted, h.id_tkc_cd, h.s_name, h.s_rep_name,
                       (h.n_advisory_fee + h.n_account_closing_fee + COALESCE(h.n_others_fee, 0)) as total_compensation,
                       h.s_predecessor, pred_worker.user_name as predecessor_name,
                       h.dt_approved, h.dt_approved_1, h.dt_approved_2, h.dt_approved_3, h.dt_approved_4, h.dt_approved_5,
                       h.dt_checked, h.dt_denied, h.s_in_charge
                FROM t_handover h
                LEFT JOIN " . DB_NAME_WORKERS . ".v_worker pred_worker ON h.s_predecessor = pred_worker.s_worker"; // Assuming v_worker is in another DB

    $conditions = [];
    $queryParams = [];
    $paramTypes = "";

    // Filter by status [cite: 24]
    if (isset($params['status']) && $params['status'] !== 'All' && $params['status'] !== '') {
        // This requires mapping the text status from frontend to logic based on date fields.
        // Or, frontend sends a status code. For now, assuming frontend sends a code that matches our STATUS_* constants.
        $statusCodeToFilter = intval($params['status']);
        // This is tricky. We'd need many OR conditions for each status.
        // It's easier if we calculate status in PHP after fetching. Or add a subquery/generated column in SQL if complex.
        // For a simplified SQL filter:
        // if ($statusCodeToFilter === STATUS_COMPLETED) {
        //     $conditions[] = "h.dt_checked IS NOT NULL AND h.dt_denied IS NULL";
        // } // ... and so on. This gets very complex.
        // Simpler: fetch more and filter in PHP, or frontend gets all relevant items and filters.
        // The document says "Status can be set according to the approval route" [cite: 24] implying filtering by these categories.
    }

    // "Include completed" checkbox [cite: 24]
    // If not checked (default), exclude "Confirmation completed" (STATUS_COMPLETED) and "Handed Over" (STATUS_HANDED_OVER)
    if (!isset($params['completed']) || $params['completed'] == 'false' || $params['completed'] == 0) {
        // This condition means we select items that are NOT YET dt_checked OR are denied
         $conditions[] = "(h.dt_checked IS NULL OR h.dt_denied IS NOT NULL)";
    }
    
    // Filter by "person in charge" is the predecessor (current user viewing their items) [cite: 24]
    if (!empty($params['predecessor_id'])) { // Assuming predecessor_id is passed for this filter
        $conditions[] = "h.s_predecessor = ?";
        $queryParams[] = $params['predecessor_id'];
        $paramTypes .= "s";
    }

    // Filter by keywords [cite: 5, 24] (company name, representative name, address, special notes)
    if (!empty($params['keywords'])) {
        $keyword = "%" . $params['keywords'] . "%";
        $keywordConditions = [];
        $keywordFields = ['h.s_name', 'h.s_rep_name', 'h.s_address', 'h.s_special_notes', 'h.s_other_notes']; // [cite: 5, 24]
        foreach ($keywordFields as $field) {
            $keywordConditions[] = "$field LIKE ?";
            $queryParams[] = $keyword;
            $paramTypes .= "s";
        }
        $conditions[] = "(" . implode(" OR ", $keywordConditions) . ")";
    }

    if (!empty($conditions)) {
        $sqlBase .= " WHERE " . implode(" AND ", $conditions);
    }

    // Sorting [cite: 5, 24] (submission date, CD of involved party, company name)
    $allowedSortBy = ['dt_submitted', 'id_tkc_cd', 's_name', 's_customer'];
    $sortBy = 'h.dt_submitted'; // Default
    if (isset($params['sort_by']) && in_array($params['sort_by'], $allowedSortBy)) {
        $sortBy = 'h.' . $params['sort_by'];
    }
    $sortDir = (isset($params['sort_dir']) && strtoupper($params['sort_dir']) === 'ASC') ? 'ASC' : 'DESC';
    $sqlBase .= " ORDER BY " . $sortBy . " " . $sortDir;

    // Add LIMIT and OFFSET for pagination if needed
    // $sqlBase .= " LIMIT ? OFFSET ?";

    $stmt = $connHandover->prepare($sqlBase);
    if ($stmt) {
        if (!empty($paramTypes) && !empty($queryParams)) {
            $stmt->bind_param($paramTypes, ...$queryParams);
        }
        $stmt->execute();
        $queryResult = $stmt->get_result();
        $data = [];
        while ($row = $queryResult->fetch_assoc()) {
            $currentStatus = determineCurrentStatus($row);
            // Filter by status code if $params['status'] was provided and not 'All'
            if (isset($params['status']) && $params['status'] !== 'All' && $params['status'] !== '' && $currentStatus != intval($params['status'])) {
                continue; // Skip this row if status doesn't match filter
            }
            $row['status_code'] = $currentStatus;
            $row['status_text'] = STATUS_TEXTS[$currentStatus] ?? 'Unknown Status';
            $data[] = $row;
        }
        $stmt->close();
        return ['success' => true, 'data' => $data];
    } else {
        return ['error' => 'Failed to prepare SQL statement for list: ' . $connHandover->error, 'sql' => $sqlBase, 'code' => APP_RET_DB_ERR];
    }
}

function getHandoverData($connHandover, $connCustomers, $connWorkers, $params) {
    if (empty($params['id'])) { // id is s_customer [cite: 12]
        return ['error' => 'Handover ID (s_customer) not provided.', 'code' => APP_RET_VALIDATION_ERR];
    }
    $id = $params['id'];

    $sql = "SELECT * FROM t_handover WHERE s_customer = ?";
    $stmt = $connHandover->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        if ($data) {
            $data['status_code'] = determineCurrentStatus($data);
            $data['status_text'] = STATUS_TEXTS[$data['status_code']] ?? 'Unknown Status';

            // Fetch related data from views
            if ($connCustomers) { // Check if connection was successful
                $customerViewData = getCustomerDetails($connCustomers, $data['s_customer']); // [cite: 17]
                if ($customerViewData) $data['v_customer_data'] = $customerViewData;
            }
            if ($connWorkers) { // Check if connection was successful
                if (!empty($data['s_predecessor'])) {
                    $data['predecessor_details'] = getWorkerDetails($connWorkers, $data['s_predecessor']); // [cite: 20]
                }
                if (!empty($data['s_superior'])) {
                    $data['superior_details'] = getWorkerDetails($connWorkers, $data['s_superior']);
                }
                if (!empty($data['s_in_charge'])) {
                    $data['in_charge_details'] = getWorkerDetails($connWorkers, $data['s_in_charge']);
                }
                // Could also fetch details for s_approved_X users if their IDs are stored, but t_handover doesn't have fields for that.
            }
            return ['success' => true, 'data' => $data];
        } else {
            return ['error' => 'Data not found for ID: ' . $id, 'code' => APP_RET_NOT_FOUND];
        }
    } else {
        return ['error' => 'Failed to prepare SQL statement for detail: ' . $connHandover->error, 'code' => APP_RET_DB_ERR];
    }
}

function createHandoverData($connHandover, $connWorkers, $params) {
    // Validation (basic example)
    if (empty($params['s_customer']) || empty($params['s_predecessor']) || empty($params['s_superior'])) {
        return ['error' => 'Required fields for creation are missing (Customer ID, Predecessor, Dept Head).', 'code' => APP_RET_VALIDATION_ERR];
    }
    // Further validation for all NOT NULL fields in t_handover [cite: 12, 13, 14, 15] that don't have defaults

    // `dt_submitted` has DEFAULT NOW() [cite: 15]
    // `s_other_notes` is NOT NULL but has no default[cite: 15]. Ensure it's provided or handle.
    $params['s_other_notes'] = $params['s_other_notes'] ?? ''; // Provide default if not set

    $columns = [];
    $placeholders = [];
    $values = [];
    $paramTypes = "";

    // Define all columns from t_handover table [cite: 12, 13, 14, 15, 16]
    // This list should be exhaustive for creation.
    $allowedFieldsForCreate = [
        's_customer','id_tkc_cd','s_name','s_address','s_type','dt_from','dt_to','n_advisory_fee',
        'n_account_closing_fee','n_others_fee','s_rep_name','s_rep_personal','s_rep_partner_name',
        's_rep_partner_personal','s_rep_others_name','s_rep_others_personal','s_corp_tel','s_corp_fax',
        's_rep_tel','s_rep_email','s_rep_contact','n_recovery','n_advisory_yet','n_account_closing_yet',
        'n_others_yet','dt_recovery','s_recover_reason','dt_completed','n_place','s_place_others',
        's_convenient','s_required_time','s_affiliated_company','s_heeding_audit','n_interim_return',
        'n_consumption_tax','s_heeding_settlement','dt_last_tax_audit','s_tax_audit_memo',
        'n_exemption_for_dependents','s_exemption_for_dependents','n_last_year_end_adjustment',
        's_last_year_end_adjustment','n_payroll_report','s_payroll_report','n_legal_report','s_legal_report',
        'n_deadline_exceptions','s_deadline_exceptions',
        's_late_payment', // Field No.51 [cite: 14]
        'n_depreciable_assets_tax','s_depreciable_assets_tax','n_final_tax_return','s_final_tax_return',
        's_taxpayer_name','n_health_insurance','n_employment_insurance','n_workers_accident_insurance',
        'n_late_payment',
        'n_greetings_method','s_special_notes','s_other_notes','s_predecessor','s_superior'
        // dt_submitted (auto), approval dates (null), s_in_charge (null)
    ];

    foreach($allowedFieldsForCreate as $field) {
        if (isset($params[$field])) {
            $columns[] = $field;
            $placeholders[] = "?";
            $values[] = ($params[$field] === '' && !in_array($field, ['n_advisory_fee', 'n_account_closing_fee'])) ? null : $params[$field]; // Allow NULL for empty strings, except for specific NOT NULL int fields
            // Basic type detection, refine this based on actual schema for all fields
            if (is_int($params[$field]) || preg_match('/^n_/', $field) && is_numeric($params[$field])) $paramTypes .= "i";
            else if (is_double($params[$field])) $paramTypes .= "d";
            else $paramTypes .= "s";
        } else if (in_array($field, ['n_advisory_fee', 'n_account_closing_fee'])) { // Default 0 for NOT NULL INT fields if not provided [cite: 12]
            $columns[] = $field;
            $placeholders[] = "?";
            $values[] = 0;
            $paramTypes .= "i";
        } else if ($field === 's_other_notes' && !isset($params[$field])) { // Ensure s_other_notes is present as it's NOT NULL [cite: 15]
             $columns[] = $field;
             $placeholders[] = "?";
             $values[] = ""; // Default to empty string
             $paramTypes .= "s";
        }
    }

    if (empty($columns)) {
        return ['error' => 'No data provided for creation.', 'code' => APP_RET_VALIDATION_ERR];
    }

    $sql = "INSERT INTO t_handover (" . implode(",", $columns) . ") VALUES (" . implode(",", $placeholders) . ")";
    $stmt = $connHandover->prepare($sql);

    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$values);
        if ($stmt->execute()) {
            $newId = $params['s_customer']; // The ID was provided
            $stmt->close();

            // Email notification to Department Head (s_superior)
            $deptHeadInfo = getWorkerDetails($connWorkers, $params['s_superior']);
            $emailParams = null;
            if ($deptHeadInfo && !empty($deptHeadInfo['s_worker'])) { // Assuming email is worker_id@example.com
                 $emailParams = [
                    'mail_to' => $deptHeadInfo['s_worker'] . '@example.com', // Placeholder email
                    'name_to' => $deptHeadInfo['user_name'],
                    'customer_name' => $params['s_name'] ?? $newId,
                    'status_text' => STATUS_TEXTS[STATUS_PENDING_DEPT_HEAD],
                    'comment' => "A new audit handover has been submitted for your approval."
                ];
            }

            return ['success' => true, 'message' => 'Handover data created successfully. ID: ' . $newId, 'id' => $newId, 'email_params' => $emailParams];
        } else {
            // Check for duplicate entry if s_customer is PK
            if ($connHandover->errno == 1062) { // Duplicate entry
                 return ['error' => 'Failed to create handover data: Duplicate Customer ID.', 'sql_error' => $stmt->error, 'code' => APP_RET_DB_ERR];
            }
            return ['error' => 'Failed to create handover data.', 'sql_error' => $stmt->error, 'sql' => $sql, 'code' => APP_RET_DB_ERR];
        }
    } else {
        return ['error' => 'Failed to prepare SQL statement for creation.', 'sql_error' => $connHandover->error, 'sql' => $sql, 'code' => APP_RET_DB_ERR];
    }
}


function updateHandoverData($connHandover, $connWorkers, $params, $currentUserId) {
    if (empty($params['s_customer'])) {
        return ['error' => 'Handover ID (s_customer) not provided for update.', 'code' => APP_RET_VALIDATION_ERR];
    }
    $s_customer = $params['s_customer'];

    // Fetch current data to check status and for email context
    $currentHandoverDataArr = getHandoverData($connHandover, null, $connWorkers, ['id' => $s_customer]); // Pass null for customer DB as not needed here
    if (!isset($currentHandoverDataArr['success']) || !$currentHandoverDataArr['success']) {
        return ['error' => 'Failed to retrieve current handover data for update.', 'code' => APP_RET_NOT_FOUND];
    }
    $currentHandover = $currentHandoverDataArr['data'];
    $currentStatus = $currentHandover['status_code'];

    $updateFields = [];
    $updateValues = [];
    $paramTypes = "";
    $emailParams = null;
    $nextStatus = $currentStatus; // By default, status doesn't change

    $approvalAction = $params['approval_action'] ?? null; // e.g., 'approve', 'reject', 'assign_successor'
    $approvalComment = $params['approval_comment'] ?? null;

    if ($approvalAction) {
        // Authorization: Check if currentUserId is allowed to perform this action on currentStatus
        // This is complex and depends on the role of $currentUserId (predecessor, specific approver, admin)
        // For simplicity, we'll assume the frontend controls this, but backend must validate.
        $now = date('Y-m-d H:i:s');

        if ($approvalAction === 'approve') {
            $acted = false;
            if ($currentStatus === STATUS_PENDING_DEPT_HEAD && $currentUserId === $currentHandover['s_superior']) { // Dept Head approves
                $updateFields[] = "dt_approved = ?"; $updateValues[] = $now; $paramTypes .= "s";
                $updateFields[] = "s_approved = ?"; $updateValues[] = $approvalComment; $paramTypes .= "s";
                $nextStatus = STATUS_PENDING_MANAGER; $acted = true;
            } elseif ($currentStatus === STATUS_PENDING_MANAGER /* && current user is the designated Manager */) {
                // Who is the manager? This needs to be known. Assuming $currentUserId IS the manager.
                $updateFields[] = "dt_approved_1 = ?"; $updateValues[] = $now; $paramTypes .= "s";
                $updateFields[] = "s_approved_1 = ?"; $updateValues[] = $approvalComment; $paramTypes .= "s";
                $nextStatus = STATUS_PENDING_DIRECTOR; $acted = true;
            } // ... Add similar blocks for dt_approved_2 to dt_approved_5 [cite: 15, 16]
            elseif ($currentStatus === STATUS_PENDING_DIRECTOR) {
                $updateFields[] = "dt_approved_2 = ?"; $updateValues[] = $now; $paramTypes .= "s";
                $updateFields[] = "s_approved_2 = ?"; $updateValues[] = $approvalComment; $paramTypes .= "s";
                $nextStatus = STATUS_PENDING_EXECUTIVE_DIRECTOR; $acted = true;
            }  elseif ($currentStatus === STATUS_PENDING_EXECUTIVE_DIRECTOR) {
                $updateFields[] = "dt_approved_3 = ?"; $updateValues[] = $now; $paramTypes .= "s";
                $updateFields[] = "s_approved_3 = ?"; $updateValues[] = $approvalComment; $paramTypes .= "s";
                $nextStatus = STATUS_PENDING_MANAGING_DIRECTOR; $acted = true;
            } elseif ($currentStatus === STATUS_PENDING_MANAGING_DIRECTOR) {
                $updateFields[] = "dt_approved_4 = ?"; $updateValues[] = $now; $paramTypes .= "s";
                $updateFields[] = "s_approved_4 = ?"; $updateValues[] = $approvalComment; $paramTypes .= "s";
                $nextStatus = STATUS_PENDING_PRESIDENT; $acted = true;
            } elseif ($currentStatus === STATUS_PENDING_PRESIDENT) {
                $updateFields[] = "dt_approved_5 = ?"; $updateValues[] = $now; $paramTypes .= "s";
                $updateFields[] = "s_approved_5 = ?"; $updateValues[] = $approvalComment; $paramTypes .= "s";
                $nextStatus = STATUS_PENDING_GENERAL_AFFAIRS; $acted = true;
            } elseif ($currentStatus === STATUS_PENDING_GENERAL_AFFAIRS /* && current user is GA */) {
                $updateFields[] = "dt_checked = ?"; $updateValues[] = $now; $paramTypes .= "s";
                $updateFields[] = "s_checked = ?"; $updateValues[] = $approvalComment; $paramTypes .= "s";
                $nextStatus = STATUS_COMPLETED; $acted = true;
            }

            if ($acted) {
                $nextApproverInfo = getNextApproverInfo($currentHandover, $nextStatus, $connWorkers);
                 if ($nextApproverInfo && !empty($nextApproverInfo['email'])) {
                     $emailParams = [
                        'mail_to' => $nextApproverInfo['email'],
                        'name_to' => $nextApproverInfo['name'],
                        'customer_name' => $currentHandover['s_name'] ?? $s_customer,
                        'status_text' => STATUS_TEXTS[$nextStatus],
                        'comment' => $approvalComment ?? "Action required for audit handover."
                    ];
                 } else if ($nextStatus === STATUS_COMPLETED) { // Notify predecessor of completion
                    $predecessorInfo = getWorkerDetails($connWorkers, $currentHandover['s_predecessor']);
                    if ($predecessorInfo) {
                         $emailParams = [
                            'mail_to' => ($predecessorInfo['s_worker'] ?? 'pred') . '@example.com', // Placeholder
                            'name_to' => $predecessorInfo['user_name'],
                            'customer_name' => $currentHandover['s_name'] ?? $s_customer,
                            'status_text' => STATUS_TEXTS[STATUS_COMPLETED],
                            'comment' => "The handover process has been fully approved and completed."
                        ];
                    }
                 }
            } else {
                 return ['error' => 'Approval action not permitted for current user or status.', 'code' => APP_RET_AUTH_ERR];
            }

        } elseif ($approvalAction === 'reject') {
            // Determine which comment field to use based on current approver.
            // For simplicity, let's say the current user is authorized.
            $updateFields[] = "dt_denied = ?"; $updateValues[] = $now; $paramTypes .= "s";
            // Store rejection comment in the current level's comment field
            if ($currentStatus === STATUS_PENDING_DEPT_HEAD) $updateFields[] = "s_approved = ?";
            else if ($currentStatus === STATUS_PENDING_MANAGER) $updateFields[] = "s_approved_1 = ?";
            // ... etc. for s_approved_2 to s_approved_5, s_checked
            else if ($currentStatus === STATUS_PENDING_GENERAL_AFFAIRS) $updateFields[] = "s_checked = ?";
            else { /* Default to a general notes field or error */ }
            
            if (end($updateFields) !== "dt_denied = ?") { // Check if a comment field was added
                 $updateValues[] = "Rejected: " . $approvalComment; $paramTypes .= "s";
            }

            $nextStatus = STATUS_REJECTED;

            // Email notification to Predecessor (s_predecessor)
            $predecessorInfo = getWorkerDetails($connWorkers, $currentHandover['s_predecessor']);
            if ($predecessorInfo) {
                $emailParams = [
                    'mail_to' => ($predecessorInfo['s_worker'] ?? 'pred') . '@example.com', // Placeholder email
                    'name_to' => $predecessorInfo['user_name'],
                    'customer_name' => $currentHandover['s_name'] ?? $s_customer,
                    'status_text' => STATUS_TEXTS[STATUS_REJECTED],
                    'comment' => $approvalComment ?? "Audit handover has been rejected."
                ];
            }
        } elseif ($approvalAction === 'assign_successor' && $currentStatus === STATUS_COMPLETED) {
             if (empty($params['s_in_charge_id'])) { // Successor ID from frontend
                return ['error' => 'Successor ID (s_in_charge_id) not provided.', 'code' => APP_RET_VALIDATION_ERR];
            }
            $updateFields[] = "s_in_charge = ?"; $updateValues[] = $params['s_in_charge_id']; $paramTypes .= "s"; // [cite: 16]
            $nextStatus = STATUS_HANDED_OVER;
            // Email to successor and predecessor
            $successorInfo = getWorkerDetails($connWorkers, $params['s_in_charge_id']);
            if ($successorInfo) {
                 $emailParams = [
                    'mail_to' => ($successorInfo['s_worker'] ?? 'succ') . '@example.com', // Placeholder email
                    'name_to' => $successorInfo['user_name'],
                    'customer_name' => $currentHandover['s_name'] ?? $s_customer,
                    'status_text' => STATUS_TEXTS[STATUS_HANDED_OVER],
                    'comment' => "An audit has been handed over to you."
                ];
                // Optionally send another email to predecessor
            }
        }
    } else { // General data update (not an approval action)
        // Only allow predecessor to update if not fully completed or rejected, or admin
        if ($currentUserId !== $currentHandover['s_predecessor'] && !in_array($currentStatus, [STATUS_DRAFT, STATUS_REJECTED])) {
            // Basic check, more granular permissions might be needed.
            // return ['error' => 'Only the predecessor can modify general data unless rejected.', 'code' => APP_RET_AUTH_ERR];
        }
        // List of fields updatable by predecessor [cite: 12, 13, 14, 15]
        // Exclude approval fields, s_predecessor, s_superior (set at creation/workflow), dt_submitted
        $allowedFieldsForUpdate = [ /* Copy from create, but exclude FKs like s_predecessor, s_superior and date audit trails */
            'id_tkc_cd','s_name','s_address','s_type','dt_from','dt_to','n_advisory_fee', /* ... many more ... */ 's_other_notes'
        ]; // This list needs to be carefully curated.

        foreach($allowedFieldsForUpdate as $field) {
            if (isset($params[$field])) {
                $updateFields[] = $field . " = ?";
                $updateValues[] = ($params[$field] === '') ? null : $params[$field]; // Allow NULL for empty strings
                // Basic type detection
                if (is_int($params[$field]) || preg_match('/^n_/', $field) && is_numeric($params[$field])) $paramTypes .= "i";
                else $paramTypes .= "s";
            }
        }
    }

    if (empty($updateFields)) {
        return ['message' => 'No data provided or action taken for update.', 'no_change' => true];
    }

    $sql = "UPDATE t_handover SET " . implode(", ", $updateFields) . " WHERE s_customer = ?";
    $updateValues[] = $s_customer;
    $paramTypes .= "s";

    $stmt = $connHandover->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$updateValues);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Data updated successfully. New Status: ' . STATUS_TEXTS[$nextStatus]];
            if ($emailParams) {
                $response['email_params'] = $emailParams;
            }
            // If status changed, include it in response
            if ($nextStatus !== $currentStatus) {
                 $response['new_status_code'] = $nextStatus;
                 $response['new_status_text'] = STATUS_TEXTS[$nextStatus];
            }
            return $response;
        } else {
            return ['error' => 'Failed to update data.', 'sql_error' => $stmt->error, 'sql' => $sql, 'code' => APP_RET_DB_ERR];
        }
        $stmt->close();
    } else {
        return ['error' => 'Failed to prepare SQL statement for update.', 'sql_error' => $connHandover->error, 'sql' => $sql, 'code' => APP_RET_DB_ERR];
    }
}


function getApprovalRoot($connHandover, $connWorkers, $params) {
    // This function describes the *potential* approval path.
    // For a specific handover item ($params['s_customer']), it can show current status along the path.
    $s_customer = $params['s_customer'] ?? null;
    $handoverData = null;
    if ($s_customer) {
        $handoverDataArr = getHandoverData($connHandover, null, $connWorkers, ['id' => $s_customer]);
        if (isset($handoverDataArr['data'])) {
            $handoverData = $handoverDataArr['data'];
        }
    }

    // Static definition of the approval chain structure [cite: 24]
    // Field names from t_handover [cite: 15, 16]
    $approvalChain = [
        ['level' => 1, 'role_code' => STATUS_PENDING_DEPT_HEAD,         'role_text' => STATUS_TEXTS[STATUS_PENDING_DEPT_HEAD],         'approver_id_field' => 's_superior',    'date_field' => 'dt_approved',   'comment_field' => 's_approved'],
        ['level' => 2, 'role_code' => STATUS_PENDING_MANAGER,           'role_text' => STATUS_TEXTS[STATUS_PENDING_MANAGER],           'approver_id_field' => null,            'date_field' => 'dt_approved_1', 'comment_field' => 's_approved_1'], // No specific field for Manager ID in t_handover
        ['level' => 3, 'role_code' => STATUS_PENDING_DIRECTOR,          'role_text' => STATUS_TEXTS[STATUS_PENDING_DIRECTOR],          'approver_id_field' => null,            'date_field' => 'dt_approved_2', 'comment_field' => 's_approved_2'],
        ['level' => 4, 'role_code' => STATUS_PENDING_EXECUTIVE_DIRECTOR,'role_text' => STATUS_TEXTS[STATUS_PENDING_EXECUTIVE_DIRECTOR],'approver_id_field' => null,            'date_field' => 'dt_approved_3', 'comment_field' => 's_approved_3'],
        ['level' => 5, 'role_code' => STATUS_PENDING_MANAGING_DIRECTOR, 'role_text' => STATUS_TEXTS[STATUS_PENDING_MANAGING_DIRECTOR],'approver_id_field' => null,            'date_field' => 'dt_approved_4', 'comment_field' => 's_approved_4'],
        ['level' => 6, 'role_code' => STATUS_PENDING_PRESIDENT,         'role_text' => STATUS_TEXTS[STATUS_PENDING_PRESIDENT],         'approver_id_field' => null,            'date_field' => 'dt_approved_5', 'comment_field' => 's_approved_5'],
        ['level' => 7, 'role_code' => STATUS_PENDING_GENERAL_AFFAIRS,   'role_text' => STATUS_TEXTS[STATUS_PENDING_GENERAL_AFFAIRS],   'approver_id_field' => null,            'date_field' => 'dt_checked',    'comment_field' => 's_checked'],
    ];

    if ($handoverData) {
        foreach ($approvalChain as $key => $step) {
            // Actual approver for Dept Head
            if ($step['approver_id_field'] && !empty($handoverData[$step['approver_id_field']]) && $connWorkers) {
                $approverInfo = getWorkerDetails($connWorkers, $handoverData[$step['approver_id_field']]);
                $approvalChain[$key]['approver_name'] = $approverInfo['user_name'] ?? 'N/A';
                $approvalChain[$key]['approver_id'] = $handoverData[$step['approver_id_field']];
            }
            // For other levels, t_handover does not store who IS the designated approver, only who approved.
            // So, 'approver_name' would typically be populated once they approve, by looking up their ID if it were stored.
            // This requires a system to know who (e.g. 'USER002') *is* the director for this item.

            $approvalChain[$key]['status'] = 'pending'; // Default
            if (!empty($handoverData[$step['date_field']])) {
                $approvalChain[$key]['status'] = 'approved';
                $approvalChain[$key]['date'] = $handoverData[$step['date_field']];
                $approvalChain[$key]['comment'] = $handoverData[$step['comment_field']];
            }
            if (!empty($handoverData['dt_denied']) && strtotime($handoverData['dt_denied']) >= strtotime($handoverData['dt_submitted'])) {
                 // If denied, mark subsequent steps as 'n/a' or similar if the denial happened before this step.
                 // For simplicity, if denied, all steps effectively stop. The overall status is REJECTED.
            }
        }
    }
    return ['success' => true, 'data' => $approvalChain, 'current_overall_status' => $handoverData ? $handoverData['status_text'] : 'N/A'];
}

?>