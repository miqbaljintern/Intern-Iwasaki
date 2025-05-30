<?php

require_once 'const.php';

/**
 * Function to create a connection to the specified database.
 * @param string $dbNameConstant The constant name for the database (e.g., 'DB_NAME_HANDOVER')
 * @return mysqli|false Returns a mysqli connection object if successful, false if failed.
 */
function connectDB($dbNameConstant = DB_NAME_HANDOVER) { // Function name adapted [cite: 31]
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, $dbNameConstant);

    if ($conn->connect_error) {
        error_log("Connection failed to ".$dbNameConstant.": " . $conn->connect_error); // Log error
        return false;
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}
?>