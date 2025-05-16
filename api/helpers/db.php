<?php
/**
 * Вспомогательные функции для работы с базой данных
 */

require_once __DIR__ . '/../config.php';

/**
 * Выполняет SQL-запрос с подготовленными параметрами
 * 
 * @param string $sql SQL-запрос
 * @param array $params Параметры запроса
 * @return PDOStatement Результат выполнения запроса
 */
function executeQuery($sql, $params = []) {
    $db = getDbConnection();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Получает одну запись из базы данных
 * 
 * @param string $sql SQL-запрос
 * @param array $params Параметры запроса
 * @return array|null Результат запроса или null, если запись не найдена
 */
function fetchOne($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetch();
}

/**
 * Получает все записи из базы данных
 * 
 * @param string $sql SQL-запрос
 * @param array $params Параметры запроса
 * @return array Массив результатов
 */
function fetchAll($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetchAll();
}

/**
 * Вставляет данные в таблицу
 * 
 * @param string $table Имя таблицы
 * @param array $data Ассоциативный массив с данными (поле => значение)
 * @return int ID вставленной записи
 */
function insert($table, $data) {
    $fields = array_keys($data);
    $placeholders = array_fill(0, count($fields), '?');
    
    $sql = "INSERT INTO $table (" . implode(', ', $fields) . ") 
            VALUES (" . implode(', ', $placeholders) . ")";
    
    $db = getDbConnection();
    $stmt = $db->prepare($sql);
    $stmt->execute(array_values($data));
    
    return $db->lastInsertId();
}

/**
 * Обновляет данные в таблице
 * 
 * @param string $table Имя таблицы
 * @param array $data Ассоциативный массив с данными (поле => значение)
 * @param string $where Условие WHERE
 * @param array $whereParams Параметры для условия WHERE
 * @return int Количество обновленных записей
 */
function update($table, $data, $where, $whereParams = []) {
    $set = [];
    foreach ($data as $field => $value) {
        $set[] = "$field = ?";
    }
    
    $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE $where";
    
    $params = array_merge(array_values($data), $whereParams);
    
    $stmt = executeQuery($sql, $params);
    return $stmt->rowCount();
}

/**
 * Удаляет записи из таблицы
 * 
 * @param string $table Имя таблицы
 * @param string $where Условие WHERE
 * @param array $params Параметры для условия WHERE
 * @return int Количество удаленных записей
 */
function delete($table, $where, $params = []) {
    $sql = "DELETE FROM $table WHERE $where";
    $stmt = executeQuery($sql, $params);
    return $stmt->rowCount();
}

/**
 * Проверяет существование записи в таблице
 * 
 * @param string $table Имя таблицы
 * @param string $where Условие WHERE
 * @param array $params Параметры для условия WHERE
 * @return bool Существует ли запись
 */
function exists($table, $where, $params = []) {
    $sql = "SELECT 1 FROM $table WHERE $where LIMIT 1";
    $stmt = executeQuery($sql, $params);
    return $stmt->fetchColumn() !== false;
} 