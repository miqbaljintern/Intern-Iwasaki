<?php

require_once 'const.php';

/**
 * Function to create a connection to the primary handover database.
 * @return mysqli|false Returns a mysqli connection object if successful, false if failed.
 */
function connectDB() { // [cite: 31]
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME_HANDOVER);

    if ($conn->connect_error) {
        // error_log("Connection failed: " . $conn->connect_error);
        return false;
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Anda bisa menambahkan fungsi serupa jika perlu koneksi ke DB_NAME_CUSTOMERS atau DB_NAME_WORKERS secara terpisah
// function connectCustomersDB() { ... }
// function connectWorkersDB() { ... }

?>