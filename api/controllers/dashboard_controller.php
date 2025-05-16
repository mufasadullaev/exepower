<?php
/**
 * Контроллер для работы с данными дашборда
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Получение данных для дашборда
 */
function getDashboardData() {
    // Проверка аутентификации
    $user = requireAuth();
    
    // Получение списка оборудования
    $equipment = fetchAll("
        SELECT e.id, e.name, e.description, et.name as type_name 
        FROM equipment e
        JOIN equipment_types et ON e.type_id = et.id
        ORDER BY et.name, e.name
    ");
    
    // Получение списка смен
    $shifts = fetchAll("SELECT id, name, start_time, end_time FROM shifts ORDER BY id");
    
    // Получение последних значений параметров (можно добавить фильтрацию по дате)
    $latestValues = fetchAll("
        SELECT 
            pv.id, 
            pv.value, 
            pv.date, 
            p.name as parameter_name, 
            p.unit, 
            e.name as equipment_name,
            s.name as shift_name,
            u.username as user_name
        FROM parameter_values pv
        JOIN parameters p ON pv.parameter_id = p.id
        JOIN equipment e ON pv.equipment_id = e.id
        JOIN shifts s ON pv.shift_id = s.id
        JOIN users u ON pv.user_id = u.id
        ORDER BY pv.date DESC, pv.id DESC
        LIMIT 20
    ");
    
    // Формирование статистики
    $stats = [
        'equipmentCount' => count($equipment),
        'parametersCount' => fetchOne("SELECT COUNT(*) as count FROM parameters")['count'],
        'valuesCount' => fetchOne("SELECT COUNT(*) as count FROM parameter_values")['count'],
        'lastUpdate' => !empty($latestValues) ? $latestValues[0]['date'] : null
    ];
    
    return sendSuccess([
        'equipment' => $equipment,
        'shifts' => $shifts,
        'latestValues' => $latestValues,
        'stats' => $stats
    ]);
}

/**
 * Получение списка параметров для типа оборудования
 */
function getParameters() {
    // Проверка аутентификации
    $user = requireAuth();
    
    // Получение ID типа оборудования из запроса
    $equipmentTypeId = $_GET['equipment_type_id'] ?? null;
    
    if (!$equipmentTypeId) {
        return sendError('ID типа оборудования не указан', 400);
    }
    
    // Получение параметров для указанного типа оборудования
    $parameters = fetchAll(
        "SELECT id, name, description, unit FROM parameters WHERE equipment_type_id = ? ORDER BY name",
        [$equipmentTypeId]
    );
    
    return sendSuccess([
        'parameters' => $parameters
    ]);
}

/**
 * Сохранение значений параметров
 */
function saveParameterValues() {
    // Проверка аутентификации
    $user = requireAuth();
    
    // Получение данных из запроса
    $requestData = json_decode(file_get_contents('php://input'), true);
    
    // Проверка обязательных полей
    if (!isset($requestData['equipmentId']) || !isset($requestData['date']) || 
        !isset($requestData['shiftId']) || !isset($requestData['values'])) {
        return sendError('Не все обязательные поля заполнены', 400);
    }
    
    $equipmentId = $requestData['equipmentId'];
    $date = $requestData['date'];
    $shiftId = $requestData['shiftId'];
    $values = $requestData['values'];
    
    // Проверка формата даты
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return sendError('Неверный формат даты. Используйте YYYY-MM-DD', 400);
    }
    
    // Начало транзакции
    $db = getDbConnection();
    $db->beginTransaction();
    
    try {
        // Сохранение каждого значения
        $savedCount = 0;
        foreach ($values as $value) {
            if (!isset($value['parameterId']) || !isset($value['value'])) {
                continue;
            }
            
            $parameterId = $value['parameterId'];
            $paramValue = $value['value'];
            
            // Проверка существования записи
            $existingValue = fetchOne(
                "SELECT id FROM parameter_values 
                WHERE parameter_id = ? AND equipment_id = ? AND date = ? AND shift_id = ?",
                [$parameterId, $equipmentId, $date, $shiftId]
            );
            
            if ($existingValue) {
                // Обновление существующего значения
                update(
                    'parameter_values',
                    ['value' => $paramValue],
                    'id = ?',
                    [$existingValue['id']]
                );
            } else {
                // Создание нового значения
                insert('parameter_values', [
                    'parameter_id' => $parameterId,
                    'equipment_id' => $equipmentId,
                    'value' => $paramValue,
                    'date' => $date,
                    'shift_id' => $shiftId,
                    'user_id' => $user['id']
                ]);
            }
            
            $savedCount++;
        }
        
        // Подтверждение транзакции
        $db->commit();
        
        return sendSuccess([
            'message' => "Сохранено $savedCount значений параметров",
            'savedCount' => $savedCount
        ]);
    } catch (Exception $e) {
        // Откат транзакции в случае ошибки
        $db->rollBack();
        return sendError('Ошибка при сохранении значений: ' . $e->getMessage(), 500);
    }
}

/**
 * Получение значений параметров для оборудования за указанную дату и смену
 */
function getParameterValues() {
    // Проверка аутентификации
    $user = requireAuth();
    
    // Получение параметров запроса
    $equipmentId = $_GET['equipment_id'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');
    $shiftId = $_GET['shift_id'] ?? null;
    
    if (!$equipmentId) {
        return sendError('ID оборудования не указан', 400);
    }
    
    // Формирование условий запроса
    $conditions = ['pv.equipment_id = ?'];
    $params = [$equipmentId];
    
    if ($date) {
        $conditions[] = 'pv.date = ?';
        $params[] = $date;
    }
    
    if ($shiftId) {
        $conditions[] = 'pv.shift_id = ?';
        $params[] = $shiftId;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // Получение значений параметров
    $values = fetchAll("
        SELECT 
            pv.id,
            pv.parameter_id,
            p.name as parameter_name,
            p.unit,
            pv.value,
            pv.date,
            s.name as shift_name,
            u.username as user_name,
            pv.created_at
        FROM parameter_values pv
        JOIN parameters p ON pv.parameter_id = p.id
        JOIN shifts s ON pv.shift_id = s.id
        JOIN users u ON pv.user_id = u.id
        WHERE $whereClause
        ORDER BY p.name
    ", $params);
    
    // Получение информации об оборудовании
    $equipment = fetchOne(
        "SELECT e.*, et.name as type_name 
        FROM equipment e 
        JOIN equipment_types et ON e.type_id = et.id 
        WHERE e.id = ?",
        [$equipmentId]
    );
    
    // Получение всех параметров для этого типа оборудования
    $parameters = [];
    if ($equipment) {
        $parameters = fetchAll(
            "SELECT id, name, description, unit 
            FROM parameters 
            WHERE equipment_type_id = ? 
            ORDER BY name",
            [$equipment['type_id']]
        );
    }
    
    return sendSuccess([
        'equipment' => $equipment,
        'parameters' => $parameters,
        'values' => $values,
        'date' => $date,
        'shiftId' => $shiftId
    ]);
} 