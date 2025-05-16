<?php
/**
 * Send a standardized JSON response
 * 
 * @param int $status HTTP status code
 * @param array $data Response data
 * @return void
 */
function sendResponse($status = 200, $data = []) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/**
 * Send a success response
 * 
 * @param array $data Response data
 * @param string $message Success message
 * @return void
 */
function sendSuccess($data = [], $message = 'Success') {
    sendResponse(200, [
        'status' => 'success',
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * Send an error response
 * 
 * @param string $message Error message
 * @param int $code HTTP status code
 * @return void
 */
function sendError($message, $code = 400) {
    error_log("API Error: " . $message);
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => $message,
            'code' => $code
        ]
    ]);
    exit;
} 