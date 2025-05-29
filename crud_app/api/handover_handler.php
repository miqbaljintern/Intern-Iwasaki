<?php

// Description: Handles CRUD operations for data from the 't_handover' table.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Adjust for production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

require_once 'db.php';

$database = new Database();
$db = $database->connect();

$request_method = $_SERVER["REQUEST_METHOD"];

if ($request_method == 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendResponse($statusCode, $data) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

// Define all columns of the 't_handover' table for convenience
$table_columns = [
    's_customer', 'id_tkc_cd', 's_name', 's_address', 's_type', 'dt_from', 'dt_to',
    'n_advisory_fee', 'n_account_closing_fee', 'n_others_fee', 's_rep_name', 's_rep_personal',
    's_rep_partner_name', 's_rep_partner_personal', 's_rep_others_name', 's_rep_others_personal',
    's_corp_tel', 's_corp_fax', 's_rep_tel', 's_rep_email', 's_rep_contact', 'n_recovery',
    'n_advisory_yet', 'n_account_closing_yet', 'n_others_yet', 'dt_recovery', 's_recover_reason',
    'dt_completed', 'n_place', 's_place_others', 's_convenient', 's_required_time',
    's_affiliated_company', 's_heeding_audit', 'n_interim_return', 'n_consumption_tax',
    's_heeding_settlement', 'dt_last_tax_audit', 's_tax_audit_memo', 'n_exemption_for_dependents',
    's_exemption_for_dependents', // Column 41
    'n_last_year_end_adjustment', 's_last_year_end_adjustment', 'n_payroll_report',
    's_payroll_report', // Column 45
    'n_legal_report', 's_legal_report', // Column 47
    'n_deadline_exceptions', 's_deadline_exceptions', // Column 49
    's_late_payment', 'n_depreciable_assets_tax', 's_depreciable_assets_tax', // Column 53
    'n_final_tax_return', 's_final_tax_return', // Column 55
    's_taxpayer_name', 'n_health_insurance', 'n_employment_insurance', 'n_workers_accident_insurance',
    'n_late_payment', // This is column 60 for late payment status (TINYINT)
    'n_greetings_method', 's_special_notes', 's_other_notes', 's_predecessor',
    's_superior', 'dt_submitted', 'dt_approved', 's_approved', 'dt_approved_1', 's_approved_1',
    'dt_approved_2', 's_approved_2', 'dt_approved_3', 's_approved_3', 'dt_approved_4', 's_approved_4',
    'dt_approved_5', 's_approved_5', 'dt_checked', 's_checked', 'dt_denied', 's_in_charge'
];
// dt_submitted has DEFAULT NOW(), so we don't need to send it during INSERT if we want the default
// but we will still accept it if sent from the frontend and not empty.

switch ($request_method) {
    case 'GET':
        if (!empty($_GET["s_customer"])) {
            $s_customer = $_GET["s_customer"];
            try {
                $stmt = $db->prepare("SELECT * FROM t_handover WHERE s_customer = :s_customer");
                $stmt->bindParam(':s_customer', $s_customer);
                $stmt->execute();
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($item) {
                    sendResponse(200, $item);
                } else {
                    sendResponse(404, ["message" => "Data not found."]);
                }
            } catch (PDOException $e) {
                error_log("Error fetching item: " . $e->getMessage());
                sendResponse(500, ["message" => "Failed to retrieve data.", "error" => $e->getMessage()]);
            }
        } else {
            try {
                $stmt = $db->prepare("SELECT s_customer, s_name, s_type, dt_submitted FROM t_handover ORDER BY dt_submitted DESC");
                $stmt->execute();
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                sendResponse(200, $items);
            } catch (PDOException $e) {
                error_log("Error fetching all items: " . $e->getMessage());
                sendResponse(500, ["message" => "Failed to retrieve all data.", "error" => $e->getMessage()]);
            }
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);

        // Basic validation (s_customer is PK and must exist, s_name is also mandatory)
        if (empty($data['s_customer']) || empty($data['s_name'])) {
            sendResponse(400, ["message" => "s_customer and s_name cannot be empty."]);
        }

        $sql_columns = [];
        $sql_placeholders = [];
        $params_to_bind = [];

        foreach ($table_columns as $column) {
            // Special handling for dt_submitted during INSERT:
            if ($column === 'dt_submitted') {
                if (isset($data[$column]) && !empty($data[$column])) {
                    // If the frontend sends a specific (non-empty) value for dt_submitted, use that.
                    // Ensure the format from JS is 'YYYY-MM-DD HH:MM:SS'
                    $sql_columns[] = $column;
                    $sql_placeholders[] = ':' . $column;
                    $params_to_bind[':' . $column] = $data[$column];
                }
                // If dt_submitted is empty or not in $data, this column is skipped,
                // so the DB will use DEFAULT NOW().
            } else {
                // Logic for other columns
                if (isset($data[$column])) { // If the key exists and its value is set (can be an empty string)
                    $sql_columns[] = $column;
                    $sql_placeholders[] = ':' . $column;
                    // Convert empty string to null for columns that allow it.
                    // If a column is NOT NULL without a default, an empty string will be an issue if not valid.
                    $params_to_bind[':' . $column] = ($data[$column] === '' && is_string($data[$column])) ? null : $data[$column];
                } elseif (array_key_exists($column, $data) && $data[$column] === null) { // If the field is sent as an explicit null
                    $sql_columns[] = $column;
                    $sql_placeholders[] = ':' . $column;
                    $params_to_bind[':' . $column] = null;
                }
                // Columns from $table_columns that are not in $data will be ignored.
                // This is important for NOT NULL columns without a DEFAULT to be validated on the frontend or backend.
            }
        }

        if (empty($sql_columns)) {
            // This should not happen if s_customer and s_name (which are mandatory) are in $table_columns and sent.
            sendResponse(400, ["message" => "No valid data to add. Ensure s_customer and s_name are filled."]);
        }

        $query = "INSERT INTO t_handover (" . implode(', ', $sql_columns) . ") VALUES (" . implode(', ', $sql_placeholders) . ")";

        try {
            $stmt = $db->prepare($query);
            foreach ($params_to_bind as $placeholder => &$value) { // Pass by reference
                if ($value === null) {
                    $stmt->bindParam($placeholder, $value, PDO::PARAM_NULL);
                } else {
                    // For other data types, let PDO try to infer or specify explicitly if necessary
                    // e.g., PDO::PARAM_INT for integer.
                    $stmt->bindParam($placeholder, $value);
                }
            }
            unset($value); // Remove reference after the loop

            if ($stmt->execute()) {
                sendResponse(201, ["message" => "Data created successfully.", "s_customer" => $data['s_customer']]);
            } else {
                // Usually $stmt->execute() will throw PDOException if it fails.
                // This block is a fallback if execute returns false without an exception.
                $errorInfo = $stmt->errorInfo();
                error_log("Failed to create data (execute false): " . implode(";", $errorInfo) . " | Query: " . $query . " | Params: " . json_encode($params_to_bind));
                sendResponse(500, ["message" => "Failed to create data (from execute false).", "error_details" => $errorInfo]);
            }
        } catch (PDOException $e) {
            // Log more detailed error
            error_log("PDOException while creating data: " . $e->getMessage() . " | Query: " . $query . " | Params: " . json_encode($params_to_bind));
            if ($e->getCode() == 23000) { // Integrity constraint violation (e.g., duplicate PK)
                sendResponse(409, ["message" => "Failed to create data. Customer ID may already exist or there is another unique data violation.", "error_code" => $e->getCode(), "error_detail" => $e->getMessage()]);
            } else {
                sendResponse(500, ["message" => "Failed to create data due to a database error.", "error" => $e->getMessage(), "error_code" => $e->getCode()]);
            }
        }
        break;

    case 'PUT':
        $s_customer_key = !empty($_GET["s_customer"]) ? $_GET["s_customer"] : null;
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($s_customer_key)) {
            sendResponse(400, ["message" => "s_customer (key) is required for update."]);
        }

        $sql_set_parts = [];
        $params_to_bind = [];
        $params_to_bind[':s_customer_key'] = $s_customer_key;

        foreach ($table_columns as $column) {
            if ($column === 's_customer') continue; // Do not update PK here (or handle with care if it's allowed)

            // For dt_submitted on UPDATE, if sent empty/null, you might want to set it to NULL
            // or ignore it so it doesn't change. Currently, if sent, it will be updated.
            if (isset($data[$column])) {
                $sql_set_parts[] = $column . ' = :' . $column;
                $params_to_bind[':' . $column] = ($data[$column] === '' && is_string($data[$column])) ? null : $data[$column];
            } elseif (array_key_exists($column, $data) && $data[$column] === null) {
                $sql_set_parts[] = $column . ' = :' . $column;
                $params_to_bind[':' . $column] = null;
            }
        }
        // If you want dt_submitted to be updated with NOW() during PUT if left empty, special logic is needed:
        // if (isset($data['dt_submitted']) && empty($data['dt_submitted'])) {
        //     $sql_set_parts[] = 'dt_submitted = NOW()'; // Or :dt_submitted with the NOW() value from PHP
        // } elseif (isset($data['dt_submitted']) && !empty($data['dt_submitted'])) {
        //      // already handled in the loop above
        // }


        if (empty($sql_set_parts)) {
            sendResponse(400, ["message" => "No data to update."]);
        }

        $query = "UPDATE t_handover SET " . implode(', ', $sql_set_parts) . " WHERE s_customer = :s_customer_key";

        try {
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM t_handover WHERE s_customer = :s_customer_key");
            $checkStmt->bindParam(':s_customer_key', $s_customer_key);
            $checkStmt->execute();
            if ($checkStmt->fetchColumn() == 0) {
                sendResponse(404, ["message" => "Data with ID '$s_customer_key' not found for update."]);
            }

            $stmt = $db->prepare($query);
            foreach ($params_to_bind as $placeholder => &$value) {
                if ($value === null) {
                    $stmt->bindParam($placeholder, $value, PDO::PARAM_NULL);
                } else {
                    $stmt->bindParam($placeholder, $value);
                }
            }
            unset($value);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    sendResponse(200, ["message" => "Data updated successfully.", "s_customer" => $s_customer_key]);
                } else {
                    sendResponse(200, ["message" => "No changes to the data or data not found (but existed before the update query).", "s_customer" => $s_customer_key]);
                }
            } else {
                $errorInfo = $stmt->errorInfo();
                error_log("Failed to update data (execute false): " . implode(";", $errorInfo) . " | Query: " . $query . " | Params: " . json_encode($params_to_bind));
                sendResponse(500, ["message" => "Failed to update data (from execute false).", "error_details" => $errorInfo]);
            }
        } catch (PDOException $e) {
            error_log("PDOException while updating data: " . $e->getMessage() . " | Query: " . $query . " | Params: " . json_encode($params_to_bind));
            if ($e->getCode() == 23000) { // Integrity constraint violation
                sendResponse(409, ["message" => "Failed to update data. There might be a unique data duplication.", "error_code" => $e->getCode(), "error_detail" => $e->getMessage()]);
            } else {
                sendResponse(500, ["message" => "Failed to update data.", "error" => $e->getMessage(), "error_code" => $e->getCode()]);
            }
        }
        break;

    case 'DELETE':
        if (empty($_GET["s_customer"])) {
            sendResponse(400, ["message" => "s_customer is required for deletion."]);
        }
        $s_customer = $_GET["s_customer"];

        try {
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM t_handover WHERE s_customer = :s_customer");
            $checkStmt->bindParam(':s_customer', $s_customer);
            $checkStmt->execute();
            if ($checkStmt->fetchColumn() == 0) {
                sendResponse(404, ["message" => "Data with ID '$s_customer' not found for deletion."]);
            }

            $stmt = $db->prepare("DELETE FROM t_handover WHERE s_customer = :s_customer");
            $stmt->bindParam(':s_customer', $s_customer);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    sendResponse(200, ["message" => "Data deleted successfully.", "s_customer" => $s_customer]);
                } else {
                    // This should not happen if checkStmt found data.
                    sendResponse(404, ["message" => "Data not found or already deleted (rowCount 0)."]);
                }
            } else {
                $errorInfo = $stmt->errorInfo();
                error_log("Failed to delete data (execute false): " . implode(";", $errorInfo));
                sendResponse(500, ["message" => "Failed to delete data (from execute false).", "error_details" => $errorInfo]);
            }
        } catch (PDOException $e) {
            error_log("PDOException while deleting data: " . $e->getMessage());
            sendResponse(500, ["message" => "Failed to delete data.", "error" => $e->getMessage(), "error_code" => $e->getCode()]);
        }
        break;

    default:
        sendResponse(405, ["message" => "Request method not allowed."]);
        break;
}

$database->closeConnection(); // Ensure the connection is closed
?>