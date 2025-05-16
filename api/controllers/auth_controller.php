<?php
/**
 * Контроллер аутентификации
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Аутентификация пользователя
 */
function login() {
    // Получение данных из запроса
    $requestData = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($requestData['password'])) {
        return sendError('Пароль не указан', 400);
    }
    
    $password = $requestData['password'];
    
    // Для обратной совместимости - проверка мастер-пароля
    if ($password === MASTER_PASSWORD) {
        $user = [
            'id' => 1,
            'username' => 'admin',
            'role' => 'менеджер'
        ];
        
        $token = generateJWT($user);
        
        return sendSuccess([
            'token' => $token,
            'user' => $user
        ]);
    }
    
    // Проверка учетных данных в базе
    $username = $requestData['username'] ?? '';
    
    if (empty($username)) {
        return sendError('Имя пользователя не указано', 400);
    }
    
    $user = fetchOne(
        "SELECT id, username, password, role FROM users WHERE username = ?",
        [$username]
    );
    
    if (!$user || !password_verify($password, $user['password'])) {
        return sendError('Неверное имя пользователя или пароль', 401);
    }
    
    // Удаляем хеш пароля из ответа
    unset($user['password']);
    
    // Генерация JWT токена
    $token = generateJWT($user);
    
    return sendSuccess([
        'token' => $token,
        'user' => $user
    ]);
}

/**
 * Проверка токена
 */
function verify() {
    $user = getAuthenticatedUser();
    
    if (!$user) {
        return sendError('Недействительный токен', 401);
    }
    
    return sendSuccess([
        'user' => $user
    ]);
}

/**
 * Регистрация нового пользователя (только для менеджеров)
 */
function register() {
    // Проверка прав доступа
    $currentUser = requireAuth();
    
    if ($currentUser['role'] !== 'менеджер') {
        return sendError('Недостаточно прав для регистрации пользователей', 403);
    }
    
    // Получение данных из запроса
    $requestData = json_decode(file_get_contents('php://input'), true);
    
    // Проверка обязательных полей
    $requiredFields = ['username', 'password', 'role'];
    foreach ($requiredFields as $field) {
        if (!isset($requestData[$field]) || empty($requestData[$field])) {
            return sendError("Поле '$field' обязательно", 400);
        }
    }
    
    // Проверка роли
    $allowedRoles = ['рядовой', 'инженер', 'менеджер'];
    if (!in_array($requestData['role'], $allowedRoles)) {
        return sendError('Недопустимая роль', 400);
    }
    
    // Проверка уникальности имени пользователя
    if (exists('users', 'username = ?', [$requestData['username']])) {
        return sendError('Пользователь с таким именем уже существует', 400);
    }
    
    // Хеширование пароля
    $hashedPassword = password_hash($requestData['password'], PASSWORD_BCRYPT);
    
    // Создание пользователя
    $userId = insert('users', [
        'username' => $requestData['username'],
        'password' => $hashedPassword,
        'role' => $requestData['role']
    ]);
    
    if (!$userId) {
        return sendError('Ошибка при создании пользователя', 500);
    }
    
    return sendSuccess([
        'message' => 'Пользователь успешно создан',
        'userId' => $userId
    ]);
}

/**
 * Получение списка пользователей (только для менеджеров)
 */
function getUsers() {
    // Проверка прав доступа
    $currentUser = requireAuth();
    
    if ($currentUser['role'] !== 'менеджер') {
        return sendError('Недостаточно прав для просмотра пользователей', 403);
    }
    
    // Получение списка пользователей без паролей
    $users = fetchAll("SELECT id, username, role, created_at, updated_at FROM users");
    
    return sendSuccess([
        'users' => $users
    ]);
} 