<?php

// Description: Handles CRUD operations for customer data (v_customer table).

header('Content-Type: application/json'); // Sets the output content type as JSON
header('Access-Control-Allow-Origin: *'); // Allows access from all origins (adjust for production)
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

require_once 'db.php'; // Loads the Database class

$database = new Database();
$db = $database->connect();

// Gets the HTTP request method (GET, POST, PUT, DELETE)
$request_method = $_SERVER["REQUEST_METHOD"];

// Handles OPTIONS request (preflight request for CORS)
if ($request_method == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Function to send JSON response
function sendResponse($statusCode, $data) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

// CRUD logic based on request method
switch ($request_method) {
    case 'GET':
        // Get customer data
        if (!empty($_GET["s_customer"])) {
            // Get a single customer based on s_customer
            $s_customer = $_GET["s_customer"];
            try {
                $stmt = $db->prepare("SELECT s_customer, id_tkc_cd FROM v_customer WHERE s_customer = :s_customer");
                $stmt->bindParam(':s_customer', $s_customer);
                $stmt->execute();
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($customer) {
                    sendResponse(200, $customer);
                } else {
                    sendResponse(404, ["message" => "Customer not found."]);
                }
            } catch (PDOException $e) {
                error_log("Error fetching customer: " . $e->getMessage());
                sendResponse(500, ["message" => "Failed to retrieve customer data.", "error" => $e->getMessage()]);
            }
        } else {
            // Get all customers
            try {
                $stmt = $db->prepare("SELECT s_customer, id_tkc_cd FROM v_customer");
                $stmt->execute();
                $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                sendResponse(200, $customers);
            } catch (PDOException $e) {
                error_log("Error fetching all customers: " . $e->getMessage());
                sendResponse(500, ["message" => "Failed to retrieve all customer data.", "error" => $e->getMessage()]);
            }
        }
        break;

    case 'POST':
        // Create a new customer or handle other actions
        $data = json_decode(file_get_contents("php://input"), true);

        if (isset($data['action'])) {
            // If 'action' parameter exists, we can handle update/delete here if not using PUT/DELETE
            // Example: if ($data['action'] === 'update_via_post') { ... }
            // For now, we focus on creation (CREATE) with standard POST
        }

        // Basic input validation
        if (empty($data['s_customer'])) {
            sendResponse(400, ["message" => "s_customer cannot be empty."]);
        }
        // id_tkc_cd can be null/empty according to the schema

        try {
            $stmt = $db->prepare("INSERT INTO v_customer (s_customer, id_tkc_cd) VALUES (:s_customer, :id_tkc_cd)");
            $stmt->bindParam(':s_customer', $data['s_customer']);
            $stmt->bindParam(':id_tkc_cd', $data['id_tkc_cd']); // id_tkc_cd can be null

            if ($stmt->execute()) {
                sendResponse(201, ["message" => "Customer created successfully.", "s_customer" => $data['s_customer']]);
            } else {
                sendResponse(500, ["message" => "Failed to create customer."]);
            }
        } catch (PDOException $e) {
            error_log("Error creating customer: " . $e->getMessage());
            // Check if the error is due to duplicate primary key
            if ($e->getCode() == 23000) { // SQLSTATE error code for integrity constraint violation
                 sendResponse(409, ["message" => "Failed to create customer. Customer ID already exists.", "error_code" => $e->getCode()]);
            } else {
                 sendResponse(500, ["message" => "Failed to create customer.", "error" => $e->getMessage(), "error_code" => $e->getCode()]);
            }
        }
        break;

    case 'PUT':
        // Update an existing customer
        // Get s_customer from URL (e.g., api/customer_handler.php?s_customer=CUST001)
        // or from the body if not in URL
        $s_customer_key = !empty($_GET["s_customer"]) ? $_GET["s_customer"] : null;
        $data = json_decode(file_get_contents("php://input"), true);

        if (!$s_customer_key && isset($data['s_customer_key'])) { // s_customer_key for the old id if s_customer is changed
            $s_customer_key = $data['s_customer_key'];
        } elseif (!$s_customer_key && isset($data['s_customer'])) {
             $s_customer_key = $data['s_customer']; // Assume s_customer is not changed, or new s_customer is same as old
        }


        if (empty($s_customer_key) || empty($data['s_customer']) ) {
            sendResponse(400, ["message" => "Incomplete data for update. 's_customer_key' (old key if changed) and 's_customer' (new) are required."]);
        }
        // id_tkc_cd can be null/empty

        try {
            // First, check if the customer with s_customer_key exists
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM v_customer WHERE s_customer = :s_customer_key");
            $checkStmt->bindParam(':s_customer_key', $s_customer_key);
            $checkStmt->execute();
            if ($checkStmt->fetchColumn() == 0) {
                sendResponse(404, ["message" => "Customer with ID '$s_customer_key' not found for update."]);
            }

            // If s_customer (PK) is changed, this is more complex and might need special handling
            // For v_customer, we assume s_customer can be changed, but this is rare for a PK.
            // If s_customer cannot be changed, then s_customer_key will be the same as data['s_customer']
            $stmt = $db->prepare("UPDATE v_customer SET s_customer = :s_customer_new, id_tkc_cd = :id_tkc_cd WHERE s_customer = :s_customer_old");
            $stmt->bindParam(':s_customer_new', $data['s_customer']);
            $stmt->bindParam(':id_tkc_cd', $data['id_tkc_cd']);
            $stmt->bindParam(':s_customer_old', $s_customer_key);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    sendResponse(200, ["message" => "Customer updated successfully.", "s_customer" => $data['s_customer']]);
                } else {
                    // No rows affected, maybe data is the same or customer not found (already checked above)
                    sendResponse(200, ["message" => "No changes to customer data or customer not found.", "s_customer" => $s_customer_key]);
                }
            } else {
                sendResponse(500, ["message" => "Failed to update customer."]);
            }
        } catch (PDOException $e) {
            error_log("Error updating customer: " . $e->getMessage());
             if ($e->getCode() == 23000) { // Possible new s_customer is a duplicate of another
                 sendResponse(409, ["message" => "Failed to update customer. New Customer ID already exists.", "error_code" => $e->getCode()]);
            } else {
                sendResponse(500, ["message" => "Failed to update customer.", "error" => $e->getMessage(), "error_code" => $e->getCode()]);
            }
        }
        break;

    case 'DELETE':
        // Delete customer
        // Get s_customer from URL (e.g., api/customer_handler.php?s_customer=CUST001)
        if (empty($_GET["s_customer"])) {
            sendResponse(400, ["message" => "s_customer is required for deletion."]);
        }
        $s_customer = $_GET["s_customer"];

        try {
            // First, check if the customer exists
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM v_customer WHERE s_customer = :s_customer");
            $checkStmt->bindParam(':s_customer', $s_customer);
            $checkStmt->execute();
            if ($checkStmt->fetchColumn() == 0) {
                sendResponse(404, ["message" => "Customer with ID '$s_customer' not found for deletion."]);
            }

            $stmt = $db->prepare("DELETE FROM v_customer WHERE s_customer = :s_customer");
            $stmt->bindParam(':s_customer', $s_customer);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    sendResponse(200, ["message" => "Customer deleted successfully.", "s_customer" => $s_customer]);
                } else {
                    // Should not happen if the check above was successful
                    sendResponse(404, ["message" => "Customer not found or already deleted."]);
                }
            } else {
                sendResponse(500, ["message" => "Failed to delete customer."]);
            }
        } catch (PDOException $e) {
            error_log("Error deleting customer: " . $e->getMessage());
            sendResponse(500, ["message" => "Failed to delete customer.", "error" => $e->getMessage()]);
        }
        break;

    default:
        // Invalid request method
        sendResponse(405, ["message" => "Request method not allowed."]);
        break;
}

$database->closeConnection();
?>