<?php

require_once 'const.php';

/**
 * Sends an email.
 * @param array $params Email parameters:
 * 'mail_to': destination address
 * 'name_to': name of the recipient
 * 'customer_name': customer name for subject/body
 * 'status': Status text for subject/body
 * 'mail_fr' (optional): sender address, defaults to EMAIL_FROM_ADDRESS
 * 'mail_cc' (optional): cc address
 * @return array Result of the email sending process. [cite: 31]
 */
function sendMail($params) { // [cite: 31]
    $to = $params['mail_to'];
    $subject = "Audit Handover Notification: " . ($params['customer_name'] ?? 'N/A') . " - Status: " . ($params['status'] ?? 'Update'); // [cite: 31]

    $message = "Hello " . ($params['name_to'] ?? 'User') . ",\n\n";
    $message .= "There is an update regarding the handover document for customer: " . ($params['customer_name'] ?? 'N/A') . ".\n";
    $message .= "Current status: " . ($params['status'] ?? 'Update') . ".\n\n";
    $message .= "Please check the system for more details.\n\n";
    $message .= "Thank you,\n" . EMAIL_FROM_NAME;

    $fromAddress = $params['mail_fr'] ?? EMAIL_FROM_ADDRESS; // [cite: 31]

    $headers = "From: " . EMAIL_FROM_NAME . " <" . $fromAddress . ">\r\n";
    if (!empty($params['mail_cc'])) { // [cite: 31]
        $headers .= "Cc: " . $params['mail_cc'] . "\r\n";
    }
    $headers .= "Reply-To: " . $fromAddress . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();


    $result = [];
    if (mail($to, $subject, $message, $headers)) {
        $result['success'] = true;
        $result['message'] = 'Email sent successfully to ' . $to;
    } else {
        $result['success'] = false;
        $result['message'] = 'Failed to send email to ' . $to;
        // error_log("Failed to send email. To: $to, Subject: $subject, Headers: $headers");
    }
    return $result; // [cite: 31]
}
?>