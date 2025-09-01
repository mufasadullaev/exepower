<?php
/**
 * Скрипт для добавления нового пользователя
 * ВНИМАНИЕ: Используйте только для разработки/тестирования
 */

require_once __DIR__ . '/../helpers/db.php';

// Данные нового пользователя
$username = 'user';
$password = 'user123';
$role = 'рядовой'; // Роль по умолчанию

try {
    // Проверяем, существует ли пользователь с таким именем
    if (exists('users', 'username = ?', [$username])) {
        echo "Пользователь с именем '$username' уже существует!\n";
        exit(1);
    }
    
    // Хешируем пароль
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    
    // Создаем пользователя
    $userId = insert('users', [
        'username' => $username,
        'password' => $hashedPassword,
        'role' => $role
    ]);
    
    if ($userId) {
        echo "Пользователь успешно создан!\n";
        echo "ID: $userId\n";
        echo "Имя пользователя: $username\n";
        echo "Пароль: $password\n";
        echo "Роль: $role\n";
        echo "\nТеперь вы можете войти в систему с этими учетными данными.\n";
    } else {
        echo "Ошибка при создании пользователя!\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>

