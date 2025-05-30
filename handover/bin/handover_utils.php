<?php
require_once 'const.php';

/**
 * Determines the current status of a handover item based on its data.
 * @param array $handoverData Associative array of the handover item.
 * @return int The status code from const.php.
 */
function determineCurrentStatus($handoverData) {
    if (!empty($handoverData['dt_denied'])) return STATUS_REJECTED;
    if (!empty($handoverData['s_in_charge'])) return STATUS_HANDED_OVER; // Successor assigned
    if (!empty($handoverData['dt_checked'])) return STATUS_COMPLETED; // General Affairs confirmed
    if (!empty($handoverData['dt_approved_5'])) return STATUS_PENDING_GENERAL_AFFAIRS; // President approved, waiting GA
    if (!empty($handoverData['dt_approved_4'])) return STATUS_PENDING_PRESIDENT;
    if (!empty($handoverData['dt_approved_3'])) return STATUS_PENDING_MANAGING_DIRECTOR;
    if (!empty($handoverData['dt_approved_2'])) return STATUS_PENDING_EXECUTIVE_DIRECTOR;
    if (!empty($handoverData['dt_approved_1'])) return STATUS_PENDING_DIRECTOR;
    if (!empty($handoverData['dt_approved'])) return STATUS_PENDING_MANAGER; // Dept Head approved, waiting Manager
    if (!empty($handoverData['dt_submitted'])) return STATUS_PENDING_DEPT_HEAD; // Submitted, waiting Dept Head
    return STATUS_DRAFT; // Default if no dates are set (e.g. just created)
}

/**
 * Get user details from v_worker view.
 * @param mysqli $conn Database connection (to LAA1611970-workers)
 * @param string $workerId
 * @return array|null User data or null if not found.
 */
function getWorkerDetails($connWorkers, $workerId) {
    if (!$connWorkers || empty($workerId)) return null;
    // Assuming v_worker is accessible. If not, direct query LAA1611970-workers.t_worker
    // user_name = concat(w.s_Iname, ' ', w.s_fname) [cite: 22]
    // The view v_worker already provides user_name, s_corp_name, s_department [cite: 20]
    $sql = "SELECT s_worker, user_name, s_corp_name, s_department FROM v_worker WHERE s_worker = ? AND (dt_end IS NULL OR dt_end >= CURDATE())"; // [cite: 20]
    $stmt = $connWorkers->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $workerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        // Ideally, you'd also get the email address if it's in t_worker or a related table.
        // For now, we assume user_name is sufficient for 'name_to' in email.
        // Let's simulate an email, if the table `t_worker` had an email column e.g. `s_email`
        // $data['s_email'] = $workerId . '@example.com'; // Placeholder
        return $data;
    }
    error_log("Failed to prepare statement for getWorkerDetails: " . $connWorkers->error);
    return null;
}

/**
 * Get customer details from v_customer view.
 * @param mysqli $conn Database connection (to LAA1611970-customers)
 * @param string $customerId
 * @return array|null Customer data or null if not found.
 */
function getCustomerDetails($connCustomers, $customerId) {
    if (!$connCustomers || empty($customerId)) return null;
    // v_customer has s_customer, id_tkc_cd [cite: 18]
    // It references LAA1611970-customers.t_base_info_corp.id_customer => s_customer [cite: 19]
    // We might want more fields from t_base_info_corp for display, e.g., company name from there if s_name in t_handover can be different.
    // For now, let's assume v_customer provides enough, or t_handover.s_name is canonical.
    $sql = "SELECT s_customer, id_tkc_cd FROM v_customer WHERE s_customer = ?"; // [cite: 18]
    $stmt = $connCustomers->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $customerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data;
    }
    error_log("Failed to prepare statement for getCustomerDetails: " . $connCustomers->error);
    return null;
}

/**
 * Get the ID of the next approver in the chain.
 * @param array $handoverData Current handover data.
 * @param int $currentStatusCode Current status code.
 * @param mysqli $connWorkers Connection to worker DB.
 * @return array ['id' => 'worker_id', 'name' => 'Worker Name', 'email' => 'worker_email', 'role_text' => 'Role Text'] or null
 */
function getNextApproverInfo($handoverData, $currentStatusCode, $connWorkers) {
    $nextApproverId = null;
    $nextRoleText = null;

    switch ($currentStatusCode) {
        case STATUS_PENDING_DEPT_HEAD: // Current status is "waiting dept head", so dept head is next.
            $nextApproverId = $handoverData['s_superior']; // s_superior is Dept Head ID [cite: 15]
            $nextRoleText = STATUS_TEXTS[STATUS_PENDING_DEPT_HEAD];
            break;
        // To find next approvers (Manager, Director etc.), we need a way to map roles or get user hierarchy.
        // t_handover stores *who approved*, not *who is next* for higher levels.
        // This part needs a clear definition of how to find e.g. the Manager for s_predecessor.
        // For now, let's assume the IDs for next approvers (Manager, Director, etc.) are passed from frontend
        // or we need a more complex org structure table.
        // If we rely on the frontend to know who to assign or if the system has a fixed role structure:
        case STATUS_PENDING_MANAGER:
            // Need a way to identify who is the 'Manager' for this specific handover's originator (s_predecessor) or department.
            // This is a simplification. In reality, this would query an org chart.
            // For now, we will assume this info is passed or known by other means if needed for email.
            // $nextApproverId = find_manager_for($handoverData['s_predecessor']);
            $nextRoleText = STATUS_TEXTS[STATUS_PENDING_MANAGER];
            break;
        // ... and so on for other roles.
        // For General Affairs, President - these might be fixed roles/users.
        case STATUS_PENDING_GENERAL_AFFAIRS:
            // $nextApproverId = get_ga_user_id(); // e.g. from a config
            $nextRoleText = STATUS_TEXTS[STATUS_PENDING_GENERAL_AFFAIRS];
            break;
    }

    if ($nextApproverId) {
        $workerInfo = getWorkerDetails($connWorkers, $nextApproverId);
        if ($workerInfo) {
            return [
                'id' => $workerInfo['s_worker'],
                'name' => $workerInfo['user_name'],
                'email' => $workerInfo['s_worker'] . '@example.com', // Placeholder email
                'role_text' => $nextRoleText
            ];
        }
    }
    // If no specific next approver ID can be determined automatically for email (e.g. Manager upwards without org chart)
    // the email might need to go to a group, or the system relies on users checking their dashboards.
    // For this example, if ID is not found, we'll return role text for the status message.
    if ($nextRoleText) return ['role_text' => $nextRoleText];

    return null;
}

?>