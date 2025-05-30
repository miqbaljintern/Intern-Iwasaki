<?php

// Description: Handles CRUD operations for worker data (worker table).

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Adjust for production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

require_once 'db.php'; // Loads the Database class

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

switch ($request_method) {
    case 'GET':
        if (!empty($_GET["s_worker"])) {
            $s_worker = $_GET["s_worker"];
            try {
                $stmt = $db->prepare("SELECT s_worker, user_name, s_corp_name, s_department, dt_start, dt_end FROM v_worker WHERE s_worker = :s_worker");
                $stmt->bindParam(':s_worker', $s_worker);
                $stmt->execute();
                $worker = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($worker) {
                    sendResponse(200, $worker);
                } else {
                    sendResponse(404, ["message" => "Employee not found."]);
                }
            } catch (PDOException $e) {
                error_log("Error fetching worker: " . $e->getMessage());
                sendResponse(500, ["message" => "Failed to retrieve employee data.", "error" => $e->getMessage()]);
            }
        } else {
            try {
                $stmt = $db->prepare("SELECT s_worker, user_name, s_corp_name, s_department, dt_start, dt_end FROM v_worker");
                $stmt->execute();
                $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                sendResponse(200, $workers);
            } catch (PDOException $e) {
                error_log("Error fetching all workers: " . $e->getMessage());
                sendResponse(500, ["message" => "Failed to retrieve all employee data.", "error" => $e->getMessage()]);
            }
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['s_worker']) || empty($data['user_name'])) {
            sendResponse(400, ["message" => "Employee ID (s_worker) and Employee Name (user_name) cannot be empty."]);
        }

        // Handle NULL values for optional columns
        $s_corp_name = !empty($data['s_corp_name']) ? $data['s_corp_name'] : null;
        $s_department = !empty($data['s_department']) ? $data['s_department'] : null;
        $dt_start = !empty($data['dt_start']) ? $data['dt_start'] : null;
        $dt_end = !empty($data['dt_end']) ? $data['dt_end'] : null;

        try {
            $stmt = $db->prepare("INSERT INTO v_worker (s_worker, user_name, s_corp_name, s_department, dt_start, dt_end) VALUES (:s_worker, :user_name, :s_corp_name, :s_department, :dt_start, :dt_end)");
            $stmt->bindParam(':s_worker', $data['s_worker']);
            $stmt->bindParam(':user_name', $data['user_name']);
            $stmt->bindParam(':s_corp_name', $s_corp_name);
            $stmt->bindParam(':s_department', $s_department);
            $stmt->bindParam(':dt_start', $dt_start);
            $stmt->bindParam(':dt_end', $dt_end);

            if ($stmt->execute()) {
                sendResponse(201, ["message" => "Employee created successfully.", "s_worker" => $data['s_worker']]);
            } else {
                sendResponse(500, ["message" => "Failed to create employee."]);
            }
        } catch (PDOException $e) {
            error_log("Error creating worker: " . $e->getMessage());
            if ($e->getCode() == 23000) { // Integrity constraint violation (e.g., duplicate entry)
                sendResponse(409, ["message" => "Failed to create employee. Employee ID already exists.", "error_code" => $e->getCode()]);
            } else {
                sendResponse(500, ["message" => "Failed to create employee.", "error" => $e->getMessage(), "error_code" => $e->getCode()]);
            }
        }
        break;

    case 'PUT':
        $s_worker_key = !empty($_GET["s_worker"]) ? $_GET["s_worker"] : null;
        $data = json_decode(file_get_contents("php://input"), true);

        // If s_worker (PK) is changed, s_worker_key is the old ID from URL/hidden field
        // and data['s_worker'] is the new ID from the form.
        // If PK is not changed, s_worker_key and data['s_worker'] will be the same.
        
        if (!$s_worker_key && isset($data['s_worker_key'])) {
            $s_worker_key = $data['s_worker_key'];
        } elseif (!$s_worker_key && isset($data['s_worker'])) {
            $s_worker_key = $data['s_worker']; // Assume PK is not changed if only data['s_worker'] exists
        }

        if (empty($s_worker_key) || empty($data['s_worker']) || empty($data['user_name'])) {
            sendResponse(400, ["message" => "Incomplete data for update. 's_worker_key' (old ID), 's_worker' (new ID), and 'user_name' are required."]);
        }

        $s_corp_name = !empty($data['s_corp_name']) ? $data['s_corp_name'] : null;
        $s_department = !empty($data['s_department']) ? $data['s_department'] : null;
        $dt_start = !empty($data['dt_start']) ? $data['dt_start'] : null;
        $dt_end = !empty($data['dt_end']) ? $data['dt_end'] : null;

        try {
            // Check if the worker to be updated exists
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM v_worker WHERE s_worker = :s_worker_key");
            $checkStmt->bindParam(':s_worker_key', $s_worker_key);
            $checkStmt->execute();
            if ($checkStmt->fetchColumn() == 0) {
                sendResponse(404, ["message" => "Employee with ID '$s_worker_key' not found for update."]);
            }

            $stmt = $db->prepare("UPDATE v_worker SET s_worker = :s_worker_new, user_name = :user_name, s_corp_name = :s_corp_name, s_department = :s_department, dt_start = :dt_start, dt_end = :dt_end WHERE s_worker = :s_worker_old");
            $stmt->bindParam(':s_worker_new', $data['s_worker']);
            $stmt->bindParam(':user_name', $data['user_name']);
            $stmt->bindParam(':s_corp_name', $s_corp_name);
            $stmt->bindParam(':s_department', $s_department);
            $stmt->bindParam(':dt_start', $dt_start);
            $stmt->bindParam(':dt_end', $dt_end);
            $stmt->bindParam(':s_worker_old', $s_worker_key);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    sendResponse(200, ["message" => "Employee updated successfully.", "s_worker" => $data['s_worker']]);
                } else {
                    // This can happen if the submitted data is identical to the existing data,
                    // or if the s_worker_key didn't match any record (though we check this above).
                    sendResponse(200, ["message" => "No changes made to employee data or employee not found.", "s_worker" => $s_worker_key]);
                }
            } else {
                sendResponse(500, ["message" => "Failed to update employee."]);
            }
        } catch (PDOException $e) {
            error_log("Error updating worker: " . $e->getMessage());
            if ($e->getCode() == 23000) { // Integrity constraint violation (e.g., new s_worker is a duplicate)
                sendResponse(409, ["message" => "Failed to update employee. The new Employee ID ('".$data['s_worker']."') may already exist.", "error_code" => $e->getCode()]);
            } else {
                sendResponse(500, ["message" => "Failed to update employee.", "error" => $e->getMessage(), "error_code" => $e->getCode()]);
            }
        }
        break;

    case 'DELETE':
        if (empty($_GET["s_worker"])) {
            sendResponse(400, ["message" => "Employee ID (s_worker) is required for deletion."]);
        }
        $s_worker = $_GET["s_worker"];

        try {
            // Check if the worker to be deleted exists
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM v_worker WHERE s_worker = :s_worker");
            $checkStmt->bindParam(':s_worker', $s_worker);
            $checkStmt->execute();
            if ($checkStmt->fetchColumn() == 0) {
                sendResponse(404, ["message" => "Employee with ID '$s_worker' not found for deletion."]);
            }

            $stmt = $db->prepare("DELETE FROM v_worker WHERE s_worker = :s_worker");
            $stmt->bindParam(':s_worker', $s_worker);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    sendResponse(200, ["message" => "Employee deleted successfully.", "s_worker" => $s_worker]);
                } else {
                    // This case should ideally be caught by the check above.
                    // If it reaches here, it means the worker was deleted between the check and this operation.
                    sendResponse(404, ["message" => "Employee not found or already deleted."]);
                }
            } else {
                sendResponse(500, ["message" => "Failed to delete employee."]);
            }
        } catch (PDOException $e) {
            error_log("Error deleting worker: " . $e->getMessage());
            sendResponse(500, ["message" => "Failed to delete employee.", "error" => $e->getMessage()]);
        }
        break;

    default:
        sendResponse(405, ["message" => "Request method not allowed."]);
        break;
}

$database->closeConnection();
?>