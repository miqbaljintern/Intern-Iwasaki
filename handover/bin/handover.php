<?php

header('Content-Type: application/json');

require_once 'connect_db.php';
require_once 'send_mail.php'; // send_mail.php sudah include const.php

$conn = connectDB();
if (!$conn) {
    echo json_encode(['error' => 'Unable to connect to database.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$params = $_POST; // Asumsikan semua parameter ada di $_POST sesuai dokumen [cite: 31]

$result = [];

switch ($action) {
    case 'getHandoverList':
        $result = getHandoverList($conn, $params);
        break;
    case 'getHandoverData':
        $result = getHandoverData($conn, $params);
        break;
    case 'updateHandoverData':
        $result = updateHandoverData($conn, $params);
        // Pengiriman email ditangani di dalam updateHandoverData
        break;
    case 'getApprovalRoot': // [cite: 30]
        $result = getApprovalRoot($conn, $params);
        break;
    default:
        $result = ['error' => 'Invalid or unspecified action.'];
        http_response_code(400);
}

echo json_encode($result);
$conn->close();

// --- Helper Function to Determine Current Status Text ---
function determineStatusText($handoverData) {
    if (!empty($handoverData['dt_denied'])) { // [cite: 16]
        return STATUS_TEXT_REJECTED;
    }
    if (!empty($handoverData['dt_checked'])) { // [cite: 16]
        return STATUS_TEXT_CONFIRMATION_COMPLETED;
    }
    if (!empty($handoverData['dt_approved_5'])) { // [cite: 16]
        return STATUS_TEXT_WAITING_GENERAL_AFFAIRS;
    }
    if (!empty($handoverData['dt_approved_4'])) { // [cite: 16]
        return STATUS_TEXT_WAITING_PRESIDENT;
    }
    if (!empty($handoverData['dt_approved_3'])) { // [cite: 15]
        return STATUS_TEXT_WAITING_SENIOR_MANAGING_DIRECTOR;
    }
    if (!empty($handoverData['dt_approved_2'])) { // [cite: 15]
        return STATUS_TEXT_WAITING_EXECUTIVE_DIRECTOR;
    }
    if (!empty($handoverData['dt_approved_1'])) { // [cite: 15]
        return STATUS_TEXT_WAITING_DIRECTOR;
    }
    if (!empty($handoverData['dt_approved'])) { // [cite: 15]
        return STATUS_TEXT_WAITING_DIVISION_HEAD; // Assuming Division Head is next after Dept Head
    }
    if (!empty($handoverData['dt_submitted'])) { // [cite: 15]
        // This logic assumes 's_superior' is the Dept Head ID.
        // If dt_submitted is set and s_superior is set, it's waiting for Dept Head.
        return STATUS_TEXT_WAITING_DEPT_HEAD;
    }
    return STATUS_TEXT_DRAFT; // Default if no other status applies
}


/**
 * Retrieves handover information list.
 * @param mysqli $conn
 * @param array $params
 * $params['stauts']: progress text (e.g., "Waiting for confirmation from department head") [cite: 24]
 * $params['completed']: string 'true' or 'false' to include completed items [cite: 24]
 * $params['keywords']: search keywords [cite: 24]
 * $params['person_in_charge']: predecessor ID (s_predecessor) [cite: 24]
 * $params['sort_by']: 'dt_submitted', 'id_tkc_cd', 's_name' (s_name for involved party name) [cite: 24]
 * $params['sort_dir']: 'ASC' or 'DESC'
 * @return array
 */
function getHandoverList($conn, $params) { // [cite: 31]
    $selectFields = "h.s_customer, h.dt_submitted, h.id_tkc_cd, h.s_name, h.s_rep_name, 
                     (h.n_advisory_fee + h.n_account_closing_fee + COALESCE(h.n_others_fee, 0)) as total_compensation, 
                     h.s_predecessor, 
                     pred_worker.user_name as predecessor_name,
                     h.dt_approved, h.dt_approved_1, h.dt_approved_2, h.dt_approved_3, h.dt_approved_4, h.dt_approved_5,
                     h.dt_checked, h.dt_denied";
    // It's complex to get all approver names in one go without multiple joins or subqueries.
    // For simplicity, predecessor_name is fetched. Other approver names might be looked up on client-side or when viewing details.

    $sql = "SELECT $selectFields FROM t_handover h 
            LEFT JOIN v_worker pred_worker ON h.s_predecessor = pred_worker.s_worker 
            WHERE 1=1"; // v_worker assumed to exist as per [cite: 19, 21]

    $queryParams = [];
    $paramTypes = "";

    // Filter by "person in charge" is the predecessor [cite: 24]
    if (!empty($params['person_in_charge'])) {
        $sql .= " AND h.s_predecessor = ?";
        $queryParams[] = $params['person_in_charge'];
        $paramTypes .= "s";
    }

    // Filter by keywords [cite: 24] (company name, representative name, address, questions, complaints, requests, and other matters to be passed on)
    if (!empty($params['keywords'])) {
        $keyword = "%" . $params['keywords'] . "%";
        $sql .= " AND (h.s_name LIKE ? OR h.s_rep_name LIKE ? OR h.s_address LIKE ? OR h.s_special_notes LIKE ? OR h.s_other_notes LIKE ?)"; // [cite: 5, 15, 12]
        for ($i = 0; $i < 5; $i++) {
            $queryParams[] = $keyword;
            $paramTypes .= "s";
        }
    }

    // Build status filtering conditions (this is complex due to how status is determined)
    $statusFilterSql = "";
    if (isset($params['stauts']) && $params['stauts'] !== STATUS_TEXT_ALL && $params['stauts'] !== '') {
        // This requires translating text status to DB conditions.
        // Example for one status, needs to be expanded for all.
        switch ($params['stauts']) {
            case STATUS_TEXT_WAITING_DEPT_HEAD: // [cite: 24]
                $statusFilterSql = " AND h.dt_submitted IS NOT NULL AND h.s_superior IS NOT NULL AND h.dt_approved IS NULL AND h.dt_denied IS NULL";
                break;
            case STATUS_TEXT_WAITING_DIVISION_HEAD: // [cite: 24]
                $statusFilterSql = " AND h.dt_approved IS NOT NULL AND h.dt_approved_1 IS NULL AND h.dt_denied IS NULL";
                break;
            // ... Add cases for all statuses defined in const.php [cite: 24, 15, 16]
            case STATUS_TEXT_CONFIRMATION_COMPLETED: // [cite: 24]
                 $statusFilterSql = " AND h.dt_checked IS NOT NULL AND h.dt_denied IS NULL";
                 break;
            case STATUS_TEXT_REJECTED:
                 $statusFilterSql = " AND h.dt_denied IS NOT NULL";
                 break;
        }
        $sql .= $statusFilterSql;
    }

    // "Include completed" checkbox [cite: 24]
    // If 'completed' is not checked (or 'false'), exclude "Confirmation completed" IF no specific status is selected
    // If a specific status is selected, that takes precedence.
    if (empty($statusFilterSql) && isset($params['completed']) && ($params['completed'] == 'false' || $params['completed'] == 0)) {
        $sql .= " AND (h.dt_checked IS NULL OR h.dt_denied IS NOT NULL)"; // Exclude completed unless it's also denied
    }


    // Sorting [cite: 24] "You can specify the submission date, the CD of the party involved, or the company name of the party involved."
    // Document also mentions "Ability to sort by customer code/trade name" [cite: 5] (s_customer / s_name)
    $allowedSortBy = ['dt_submitted', 'id_tkc_cd', 's_name', 's_customer'];
    $sortBy = 'h.dt_submitted'; // Default sort
    if (isset($params['sort_by']) && in_array($params['sort_by'], $allowedSortBy)) {
        $sortBy = 'h.' . $params['sort_by'];
    }
    $sortDir = (isset($params['sort_dir']) && strtoupper($params['sort_dir']) === 'ASC') ? 'ASC' : 'DESC';
    $sql .= " ORDER BY " . $sortBy . " " . $sortDir;

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($paramTypes) && !empty($queryParams)) {
            $stmt->bind_param($paramTypes, ...$queryParams);
        }
        $stmt->execute();
        $queryResult = $stmt->get_result();
        $data = [];
        while ($row = $queryResult->fetch_assoc()) {
            $row['status_text'] = determineStatusText($row); // Add a display-friendly status
             // Add "total amount of compensation", "predecessor name", "status" as per Home Screen [cite: 24]
            // $row['predecessor_name'] is already fetched if v_worker join is successful
            $data[] = $row;
        }
        $stmt->close();
        return ['success' => true, 'data' => $data];
    } else {
        return ['error' => 'Failed to prepare SQL statement: ' . $conn->error, 'sql' => $sql];
    }
}

/**
 * Retrieves specific handover data.
 * @param mysqli $conn
 * @param array $params Parameters, $params['id'] is the handover ID (s_customer). [cite: 30]
 * @return array
 */
function getHandoverData($conn, $params) { // [cite: 31]
    if (empty($params['id'])) {
        return ['error' => 'Handover ID not provided.'];
    }
    $id = $params['id']; // s_customer is PK char(7) [cite: 12]

    // Fetch all columns from t_handover. Consider joining with v_worker for predecessor/superior/in_charge names.
    $sql = "SELECT h.*, 
                   pred.user_name as predecessor_name, 
                   sup.user_name as superior_name, 
                   succ.user_name as successor_name 
            FROM t_handover h
            LEFT JOIN v_worker pred ON h.s_predecessor = pred.s_worker
            LEFT JOIN v_worker sup ON h.s_superior = sup.s_worker
            LEFT JOIN v_worker succ ON h.s_in_charge = succ.s_worker
            WHERE h.s_customer = ?"; // [cite: 19, 21] for v_worker structure
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        if ($data) {
            $data['status_text'] = determineStatusText($data);
            // Optionally, fetch related data from v_customer if needed for display [cite: 17]
            // $customerViewSql = "SELECT * FROM " . DB_NAME_CUSTOMERS . ".t_base_info_corp WHERE id_customer = ?"; (Adjust based on actual v_customer definition [cite: 19])
            return ['success' => true, 'data' => $data];
        } else {
            return ['error' => 'Data not found.'];
        }
    } else {
        return ['error' => 'Failed to prepare SQL statement: ' . $conn->error];
    }
}

/**
 * Updates handover information and handles approval/rejection.
 * @param mysqli $conn
 * @param array $params Data to update. Must include 's_customer'.
 * May include 'approval_action' (e.g., 'approve_dept_head', 'reject_dept_head', etc.)
 * May include 'approval_comment'
 * May include 'current_user_id' (ID of the user performing the action)
 * May include 's_in_charge' for final handover [cite: 30]
 * @return array
 */
function updateHandoverData($conn, $params) { // [cite: 31]
    if (empty($params['s_customer'])) {
        return ['error' => 'Handover ID (s_customer) not provided.'];
    }
    $s_customer_id = $params['s_customer'];

    // Fetch current data to determine current state and for email details
    $currentHandoverDataResult = getHandoverData($conn, ['id' => $s_customer_id]);
    if (!$currentHandoverDataResult['success']) {
        return ['error' => 'Could not retrieve current handover data for update.'];
    }
    $currentHandover = $currentHandoverDataResult['data'];
    $customerNameForEmail = $currentHandover['s_name'] ?? 'N/A'; // [cite: 12]

    $updateFields = [];
    $updateValues = [];
    $paramTypes = "";
    $now = date('Y-m-d H:i:s');
    $emailToSend = null; // Stores parameters for sendMail

    // Define all updatable fields from t_handover [cite: 12, 13, 14, 15, 16]
    // This list should be comprehensive. Example:
    $allowedFields = [
        'id_tkc_cd', 's_name', 's_address', 's_type', 'dt_from', 'dt_to', 'n_advisory_fee',
        'n_account_closing_fee', 'n_others_fee', 's_rep_name', 's_rep_personal',
        's_rep_partner_name', 's_rep_partner_personal', 's_rep_others_name', 's_rep_others_personal',
        's_corp_tel', 's_corp_fax', 's_rep_tel', 's_rep_email', 's_rep_contact', 'n_recovery',
        'n_advisory_yet', 'n_account_closing_yet', 'n_others_yet', 'dt_recovery', 's_recover_reason',
        'dt_completed', 'n_place', 's_place_others', 's_convenient', 's_required_time',
        's_affiliated_company', 's_heeding_audit', 'n_interim_return', 'n_consumption_tax',
        's_heeding_settlement', 'dt_last_tax_audit', 's_tax_audit_memo', 'n_exemption_for_dependents',
        's_exemption_for_dependents', 'n_last_year_end_adjustment', 's_last_year_end_adjustment',
        'n_payroll_report', 's_payroll_report', 'n_legal_report', 's_legal_report',
        'n_deadline_exceptions', 's_deadline_exceptions', 'n_late_payment', 's_late_payment', // Using n_late_payment once [cite: 14]
        'n_depreciable_assets_tax', 's_depreciable_assets_tax', 'n_final_tax_return',
        's_final_tax_return', 's_taxpayer_name', 'n_health_insurance', 'n_employment_insurance',
        'n_workers_accident_insurance', // Second n_late_payment (No.60) [cite: 15] is skipped assuming it's a duplicate of No.50
        'n_greetings_method', 's_special_notes', 's_other_notes',
        's_predecessor', 's_superior', 's_in_charge' // FKs [cite: 15, 16]
    ];

    // Loop through allowed fields and add to update query if present in $params
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $params)) {
            $updateFields[] = $field . " = ?";
            $value = $params[$field];
            // Handle NULL for empty strings for nullable columns (define types carefully)
            // This is a simplified type handling, needs to be robust based on actual column types and nullability from [cite: 12, 13, 14, 15, 16]
            if ($value === '' && isColumnNullable($field)) { // isColumnNullable is a hypothetical helper
                $updateValues[] = null;
            } else {
                $updateValues[] = $value;
            }
            // Determine param type (s, i, d) - simplified
            if (is_numeric($value) && !str_starts_with($field, 's_') && !str_starts_with($field, 'dt_') && $field !== 'id_tkc_cd') { // Heuristic
                 if (strpos($field, 'n_') === 0 && in_array($field, ['n_advisory_fee', 'n_account_closing_fee'])) { // NOT NULL INTs [cite: 12]
                    $paramTypes .= "i";
                 } else if (is_int($value + 0)) {
                    $paramTypes .= "i";
                 } else {
                    $paramTypes .= "d";
                 }
            } else {
                $paramTypes .= "s";
            }
        }
    }
    
    // Initial submission (Predecessor submits to Department Head)
    // Assumes 'submit_to_dept_head' action comes from client when predecessor submits for the first time.
    // 's_superior' (Dept Head ID) should be set in $params by the client.
    if (isset($params['approval_action']) && $params['approval_action'] === 'submit_initial') {
        if (empty($currentHandover['dt_submitted']) && !empty($params['s_superior'])) { // Only if not already submitted
            $updateFields[] = "dt_submitted = ?"; // [cite: 15]
            $updateValues[] = $now;
            $paramTypes .= "s";
            $updateFields[] = "s_superior = ?"; // FK Department Head ID [cite: 15]
            $updateValues[] = $params['s_superior'];
            $paramTypes .= "s";

            // Email to Department Head (s_superior)
            $nextApproverInfo = getWorkerInfo($conn, $params['s_superior']);
            $emailToSend = [
                'mail_to' => $nextApproverInfo['email'] ?? DEFAULT_APPROVER_EMAIL,
                'name_to' => $nextApproverInfo['name'] ?? DEFAULT_APPROVER_NAME,
                'customer_name' => $customerNameForEmail,
                'status' => STATUS_TEXT_WAITING_DEPT_HEAD
            ];
        }
    }


    // Approval/Rejection Logic
    if (isset($params['approval_action']) && isset($params['current_user_id'])) {
        $action = $params['approval_action'];
        $comment = $params['approval_comment'] ?? '';
        // $current_user_id = $params['current_user_id']; // ID of person performing action. Not directly stored in t_handover for each step beyond s_superior.

        $nextApproverId = null; // This needs to be determined by getApprovalRoot or other logic
        $nextStatusText = '';

        // Department Head approves/rejects (current user is department head, identified by s_superior)
        if ($action === 'approve_dept_head' && $currentHandover['s_superior'] === $params['current_user_id'] && empty($currentHandover['dt_approved']) && empty($currentHandover['dt_denied'])) {
            $updateFields[] = "dt_approved = ?"; $updateValues[] = $now; $paramTypes .= "s"; // [cite: 15]
            $updateFields[] = "s_approved = ?"; $updateValues[] = $comment; $paramTypes .= "s"; // [cite: 15]
            // Determine next approver (Division Head/Manager)
            // For now, let's assume the client sends 'next_approver_division_head_id' or it's looked up.
            // This is a simplification. A robust system might store the next approver ID in t_handover or use getApprovalRoot.
            // $nextApproverId = $params['next_approver_division_head_id'] ?? null;
            $nextStatusText = STATUS_TEXT_WAITING_DIVISION_HEAD;
        } elseif ($action === 'reject_dept_head' && $currentHandover['s_superior'] === $params['current_user_id'] && empty($currentHandover['dt_denied'])) {
            $updateFields[] = "dt_denied = ?"; $updateValues[] = $now; $paramTypes .= "s"; // [cite: 16]
            $updateFields[] = "s_approved = ?"; $updateValues[] = $comment; $paramTypes .= "s"; // Dept head comment on rejection
            // Email to Predecessor
            $nextApproverInfo = getWorkerInfo($conn, $currentHandover['s_predecessor']);
            $nextStatusText = STATUS_TEXT_REJECTED;
        }
        // Manager (Division Head) approves/rejects (dt_approved is set, current user is approver for this stage)
        // Assuming client sends 'next_approver_director_id'
        else if ($action === 'approve_division_head' && !empty($currentHandover['dt_approved']) && empty($currentHandover['dt_approved_1']) && empty($currentHandover['dt_denied'])) {
            $updateFields[] = "dt_approved_1 = ?"; $updateValues[] = $now; $paramTypes .= "s"; // [cite: 15]
            $updateFields[] = "s_approved_1 = ?"; $updateValues[] = $comment; $paramTypes .= "s"; // [cite: 15]
            $nextStatusText = STATUS_TEXT_WAITING_DIRECTOR;
        }  elseif ($action === 'reject_division_head' && !empty($currentHandover['dt_approved']) && empty($currentHandover['dt_denied'])) {
            $updateFields[] = "dt_denied = ?"; $updateValues[] = $now; $paramTypes .= "s";
            $updateFields[] = "s_approved_1 = ?"; $updateValues[] = $comment; $paramTypes .= "s"; // Manager comment on rejection
            $nextApproverInfo = getWorkerInfo($conn, $currentHandover['s_predecessor']);
            $nextStatusText = STATUS_TEXT_REJECTED;
        }
        // ... Add similar blocks for Director (dt_approved_2, s_approved_2) [cite: 15]
        // ... Executive Director (dt_approved_3, s_approved_3) [cite: 15, 16]
        // ... Senior Managing Director (dt_approved_4, s_approved_4) [cite: 16]
        // ... President (dt_approved_5, s_approved_5) [cite: 16]
        
        // General Affairs Confirmation (dt_checked, s_checked) [cite: 16]
        else if ($action === 'approve_general_affairs' && !empty($currentHandover['dt_approved_5']) && empty($currentHandover['dt_checked']) && empty($currentHandover['dt_denied'])) {
            $updateFields[] = "dt_checked = ?"; $updateValues[] = $now; $paramTypes .= "s";
            $updateFields[] = "s_checked = ?"; $updateValues[] = $comment; $paramTypes .= "s";
            // If s_in_charge (Successor ID) is provided at this stage, update it.
            if (isset($params['s_in_charge']) && !empty($params['s_in_charge'])) {
                $updateFields[] = "s_in_charge = ?"; 
                $updateValues[] = $params['s_in_charge']; 
                $paramTypes .= "s"; // [cite: 16]
            }
            $nextStatusText = STATUS_TEXT_CONFIRMATION_COMPLETED;
            // Email to Successor (s_in_charge) and possibly Predecessor
            $nextApproverInfo = getWorkerInfo($conn, $params['s_in_charge'] ?? $currentHandover['s_in_charge']);

        } elseif ($action === 'reject_general_affairs' && !empty($currentHandover['dt_approved_5']) && empty($currentHandover['dt_denied'])) {
             $updateFields[] = "dt_denied = ?"; $updateValues[] = $now; $paramTypes .= "s";
             $updateFields[] = "s_checked = ?"; $updateValues[] = $comment; $paramTypes .= "s"; // GA comment on rejection
             $nextApproverInfo = getWorkerInfo($conn, $currentHandover['s_predecessor']);
             $nextStatusText = STATUS_TEXT_REJECTED;
        }

        // Prepare email if action was taken and next status determined
        if ($nextStatusText && ($nextStatusText !== STATUS_TEXT_CONFIRMATION_COMPLETED || $action === 'approve_general_affairs')) {
             // If nextApproverId was set, use it. Otherwise, determine recipient based on status.
            if (!isset($nextApproverInfo) && $nextStatusText !== STATUS_TEXT_REJECTED) { // For rejections, it usually goes back to predecessor
                // This part needs a more robust way to get the *actual next approver's ID and email*
                // For now, using a placeholder or assuming the email goes to a general address for the role
                $rootInfo = getApprovalRoot($conn, ['incharge' => $currentHandover['s_predecessor'], 'current_status_data' => $currentHandover]); // Pass current data to help determine next
                $nextApproverRoleDetails = null;
                foreach($rootInfo['data'] as $step) {
                    if ($step['status_text_before_this_approval'] === determineStatusText($currentHandover)) {
                         $nextApproverRoleDetails = $step;
                         break;
                    }
                }
                 if ($nextApproverRoleDetails) {
                    // This is still simplified; you'd need to look up the actual user for that role.
                    // For roles like Director, President, etc., it might be a fixed email or a lookup.
                    // For Dept Head, it's s_superior.
                    if ($nextStatusText === STATUS_TEXT_WAITING_DEPT_HEAD) { // This case is actually initial submission
                         $nextApproverInfo = getWorkerInfo($conn, $currentHandover['s_superior']);
                    } else {
                        // Placeholder for other roles
                         $nextApproverInfo = ['email' => DEFAULT_APPROVER_EMAIL, 'name' => $nextApproverRoleDetails['approver_role']];
                    }
                }
            }

            if (isset($nextApproverInfo)) {
                 $emailToSend = [
                    'mail_to' => $nextApproverInfo['email'] ?? DEFAULT_APPROVER_EMAIL,
                    'name_to' => $nextApproverInfo['name'] ?? DEFAULT_APPROVER_NAME,
                    'customer_name' => $customerNameForEmail,
                    'status' => $nextStatusText . ($nextStatusText === STATUS_TEXT_REJECTED ? ": " . $comment : "")
                ];
            }
        }
    }


    if (empty($updateFields)) {
        return ['success' => true, 'message' => 'No data to update.', 'no_change' => true];
    }

    $sql = "UPDATE t_handover SET " . implode(", ", $updateFields) . " WHERE s_customer = ?";
    $updateValues[] = $s_customer_id;
    $paramTypes .= "s";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$updateValues);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Data updated successfully.'];
            if ($emailToSend) {
                $mailResult = sendMail($emailToSend); // [cite: 30, 31]
                $response['email_status'] = $mailResult;
            }
            // Fetch the updated data to return with new status text
            $updatedDataResult = getHandoverData($conn, ['id' => $s_customer_id]);
            if($updatedDataResult['success']){
                $response['updated_data'] = $updatedDataResult['data'];
            }
            return $response;
        } else {
            return ['error' => 'Failed to update data: ' . $stmt->error, 'sql' => $sql, 'values' => $updateValues, 'types' => $paramTypes];
        }
        $stmt->close();
    } else {
        return ['error' => 'Failed to prepare SQL statement for update: ' . $conn->error, 'sql' => $sql];
    }
}

/**
 * Retrieves approval route.
 * @param mysqli $conn
 * @param array $params Parameters. $params['incharge'] is predecessor ID. Can also include $params['current_status_data']
 * @return array
 */
function getApprovalRoot($conn, $params) { // [cite: 31]
    // $inchargeId = $params['incharge'] ?? null; // Predecessor ID
    // $currentHandoverData = $params['current_status_data'] ?? null; // Pass current handover record

    // This defines the general approval path. Determining the *actual next approver user* is more complex.
    // s_superior is the Dept Head. Other approvers (Director, President etc.) are not stored as FKs in t_handover.
    // This function could return the defined roles and the client/server determines who that user is.
    $approvalRoute = [
        [
            'level' => 1, 'approver_role' => 'Department Head',
            'status_field_date' => 'dt_approved', 'status_field_comment' => 's_approved', // [cite: 15]
            'identifying_fk_column' => 's_superior', // ID of Dept Head stored here [cite: 15]
            'status_text_before_this_approval' => STATUS_TEXT_DRAFT, // Or status after dt_submitted
            'next_status_text_on_approve' => STATUS_TEXT_WAITING_DIVISION_HEAD
        ],
        [
            'level' => 2, 'approver_role' => 'Division Head (Manager)', // [cite: 24]
            'status_field_date' => 'dt_approved_1', 'status_field_comment' => 's_approved_1', // [cite: 15]
            'status_text_before_this_approval' => STATUS_TEXT_WAITING_DEPT_HEAD,
            'next_status_text_on_approve' => STATUS_TEXT_WAITING_DIRECTOR
        ],
        [
            'level' => 3, 'approver_role' => 'Director', // [cite: 24]
            'status_field_date' => 'dt_approved_2', 'status_field_comment' => 's_approved_2', // [cite: 15]
            'status_text_before_this_approval' => STATUS_TEXT_WAITING_DIVISION_HEAD,
            'next_status_text_on_approve' => STATUS_TEXT_WAITING_EXECUTIVE_DIRECTOR
        ],
        [
            'level' => 4, 'approver_role' => 'Executive Director', // [cite: 24]
            'status_field_date' => 'dt_approved_3', 'status_field_comment' => 's_approved_3', // [cite: 15, 16]
            'status_text_before_this_approval' => STATUS_TEXT_WAITING_DIRECTOR,
            'next_status_text_on_approve' => STATUS_TEXT_WAITING_SENIOR_MANAGING_DIRECTOR
        ],
        [
            'level' => 5, 'approver_role' => 'Senior Managing Director', // [cite: 24]
            'status_field_date' => 'dt_approved_4', 'status_field_comment' => 's_approved_4', // [cite: 16]
            'status_text_before_this_approval' => STATUS_TEXT_WAITING_EXECUTIVE_DIRECTOR,
            'next_status_text_on_approve' => STATUS_TEXT_WAITING_PRESIDENT
        ],
        [
            'level' => 6, 'approver_role' => 'President', // [cite: 24]
            'status_field_date' => 'dt_approved_5', 'status_field_comment' => 's_approved_5', // [cite: 16]
            'status_text_before_this_approval' => STATUS_TEXT_WAITING_SENIOR_MANAGING_DIRECTOR,
            'next_status_text_on_approve' => STATUS_TEXT_WAITING_GENERAL_AFFAIRS
        ],
        [
            'level' => 7, 'approver_role' => 'General Affairs', // [cite: 24]
            'status_field_date' => 'dt_checked', 'status_field_comment' => 's_checked', // [cite: 16]
            'status_text_before_this_approval' => STATUS_TEXT_WAITING_PRESIDENT,
            'next_status_text_on_approve' => STATUS_TEXT_CONFIRMATION_COMPLETED
        ],
    ];

    return ['success' => true, 'data' => $approvalRoute];
}


// Helper function to get worker info (email, name) from v_worker
// This assumes v_worker has s_worker, user_name, and an email column.
// The PDF for v_worker shows s_worker, user_name, s_corp_name, s_department, dt_start, dt_end [cite: 19]
// It does NOT show an email address. This needs to be added to v_worker or another table.
// For now, it's a placeholder.
function getWorkerInfo($conn, $workerId) {
    if (empty($workerId)) return ['name' => DEFAULT_APPROVER_NAME, 'email' => DEFAULT_APPROVER_EMAIL];

    // TODO: Adjust SQL once v_worker contains an email field or if another table provides it.
    // $sql = "SELECT user_name, email_address FROM v_worker WHERE s_worker = ? LIMIT 1";
    // $stmt = $conn->prepare($sql);
    // if($stmt){
    //    $stmt->bind_param("s", $workerId);
    //    $stmt->execute();
    //    $result = $stmt->get_result();
    //    if($data = $result->fetch_assoc()){
    //        $stmt->close();
    //        return ['name' => $data['user_name'], 'email' => $data['email_address']];
    //    }
    //    $stmt->close();
    // }
    // Placeholder:
    return ['name' => "Worker {$workerId}", 'email' => "worker{$workerId}@example.com"]; // Ganti dengan lookup sebenarnya
}

// Hypothetical helper function (needs to be defined based on DB schema details for nullability)
function isColumnNullable($columnName) {
    $nullableColumns = [ // From schema [cite: 12, 13, 14, 15, 16]
        'id_tkc_cd', 'n_others_fee', 's_rep_partner_name', 's_rep_partner_personal',
        's_rep_others_name', 's_rep_others_personal', 's_corp_fax', 's_rep_tel', 's_rep_email',
        's_rep_contact', 'n_recovery', 'n_advisory_yet', 'n_account_closing_yet', 'n_others_yet',
        'dt_recovery', 's_recover_reason', 's_place_others', 's_affiliated_company',
        'dt_last_tax_audit', 's_tax_audit_memo', 'n_exemption_for_dependents', 's_exemption_for_dependents',
        'n_last_year_end_adjustment', 's_last_year_end_adjustment', 'n_payroll_report', 's_payroll_report',
        'n_legal_report', 's_legal_report', 'n_deadline_exceptions', 's_deadline_exceptions', 
        's_late_payment', 'n_depreciable_assets_tax', 's_depreciable_assets_tax',
        'n_final_tax_return', 's_final_tax_return', 's_taxpayer_name', 'n_health_insurance',
        'n_employment_insurance', 'n_workers_accident_insurance', // n_late_payment (no.60) assumed duplicate
        'n_late_payment', 'n_greetings_method', 's_special_notes', // s_other_notes is NOT NULL
        'dt_approved', 's_approved', 'dt_approved_1', 's_approved_1', 'dt_approved_2', 's_approved_2',
        'dt_approved_3', 's_approved_3', 'dt_approved_4', 's_approved_4', 'dt_approved_5', 's_approved_5',
        'dt_checked', 's_checked', 'dt_denied', 's_in_charge'
    ];
    return in_array($columnName, $nullableColumns);
}

?>