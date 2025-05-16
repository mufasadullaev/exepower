<?php
/**
 * Вспомогательные функции для аутентификации
 */

require_once __DIR__ . '/../config.php';

// Полифилл для getallheaders() на случай, если функция не определена
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

/**
 * Генерирует JWT токен
 * 
 * @param array $user Данные пользователя
 * @return string JWT токен
 */
function generateJWT($user) {
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT'
    ];
    
    $payload = [
        'sub' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'iat' => time(),
        'exp' => time() + TOKEN_EXPIRY
    ];
    
    $headerEncoded = base64UrlEncode(json_encode($header));
    $payloadEncoded = base64UrlEncode(json_encode($payload));
    
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    $signatureEncoded = base64UrlEncode($signature);
    
    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

/**
 * Проверяет JWT токен
 * 
 * @param string $token JWT токен
 * @return array|false Данные пользователя или false в случае ошибки
 */
function verifyJWT($token) {
    $parts = explode('.', $token);
    
    if (count($parts) !== 3) {
        return false;
    }
    
    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
    
    // Проверка подписи
    $signature = base64UrlDecode($signatureEncoded);
    $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    
    if (!hash_equals($signature, $expectedSignature)) {
        return false;
    }
    
    // Декодирование данных
    $payload = json_decode(base64UrlDecode($payloadEncoded), true);
    
    // Проверка срока действия
    if (!isset($payload['exp']) || $payload['exp'] < time()) {
        return false;
    }
    
    return [
        'id' => $payload['sub'],
        'username' => $payload['username'],
        'role' => $payload['role']
    ];
}

/**
 * Получает аутентифицированного пользователя из токена
 * 
 * @return array|false Данные пользователя или false в случае ошибки
 */
function getAuthenticatedUser() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (empty($authHeader) || !preg_match('/^Bearer\s+(.*)$/', $authHeader, $matches)) {
        return false;
    }
    
    $token = $matches[1];
    return verifyJWT($token);
}

/**
 * Проверяет аутентификацию и возвращает данные пользователя
 * 
 * @return array Данные пользователя
 */
function requireAuth() {
    $user = getAuthenticatedUser();
    
    if (!$user) {
        sendError('Требуется аутентификация', 401);
        exit;
    }
    
    return $user;
}

/**
 * Кодирует строку в base64url формат
 * 
 * @param string $data Данные для кодирования
 * @return string Закодированная строка
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Декодирует строку из base64url формата
 * 
 * @param string $data Данные для декодирования
 * @return string Декодированная строка
 */
function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
} 