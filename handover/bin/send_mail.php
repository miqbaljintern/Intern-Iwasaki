<?php

require_once 'const.php';

/**
 * Sends an email.
 * @param array $params Email parameters: mail_to, name_to, customer_name, status_text. 
 * Optional: mail_fr, mail_cc
 * @return array Result of the email sending process. [cite: 31]
 */
function sendMail($params) { // [cite: 31]
    if (empty($params['mail_to']) || empty($params['name_to']) || empty($params['customer_name']) || empty($params['status_text'])) {
        return ['success' => false, 'message' => 'Missing required parameters for sending email.'];
    }

    $to = $params['mail_to'];
    $subject = "Audit Handover Notification: " . $params['customer_name'] . " - Status: " . $params['status_text'];
    
    $message = "Hello " . $params['name_to'] . ",\n\n";
    $message .= "There is an update regarding the handover document for customer: " . $params['customer_name'] . ".\n";
    $message .= "Current status: " . $params['status_text'] . ".\n\n";
    if (!empty($params['comment'])) {
        $message .= "Comment: " . $params['comment'] . "\n\n";
    }
    $message .= "Please check the system for more details.\n\n";
    $message .= "Thank you,\n" . EMAIL_FROM_NAME;

    $fromAddress = $params['mail_fr'] ?? EMAIL_FROM_ADDRESS;

    $headers = "From: " . EMAIL_FROM_NAME . " <" . $fromAddress . ">\r\n";
    if (!empty($params['mail_cc'])) {
        $headers .= "Cc: " . $params['mail_cc'] . "\r\n";
    }
    $headers .= "Reply-To: " . $fromAddress . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $result = [];
    // For development, you might want to log emails instead of sending them
    // or use a tool like MailHog
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE === true) {
        error_log("--- EMAIL TO: $to ---\nSubject: $subject\nHeaders: $headers\nMessage:\n$message\n--- END EMAIL ---");
        $result['success'] = true;
        $result['message'] = 'Email logged successfully (DEVELOPMENT MODE).';
    } else {
        if (mail($to, $subject, $message, $headers)) {
            $result['success'] = true;
            $result['message'] = 'Email sent successfully.';
        } else {
            $result['success'] = false;
            $result['message'] = 'Failed to send email.';
            error_log("Failed to send email to: " . $to . " Subject: " . $subject);
        }
    }
    return $result;
}
?>