<?php
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Получение текущего статуса инструментов для оборудования
 */
function getToolStatus($equipmentId) {
    $user = requireAuth();
    
    try {
        // Получаем последние события для каждого инструмента
        $statuses = fetchAll("
            SELECT 
                e1.tool_type,
                e1.event_type as status
            FROM equipment_tool_events e1
            INNER JOIN (
                SELECT tool_type, MAX(event_time) as max_time
                FROM equipment_tool_events
                WHERE equipment_id = ?
                GROUP BY tool_type
            ) e2 ON e1.tool_type = e2.tool_type AND e1.event_time = e2.max_time
            WHERE e1.equipment_id = ?
        ", [$equipmentId, $equipmentId]);
        
        // Формируем результат
        $result = [
            'evaporator' => 'off',
            'aos' => 'off'
        ];
        
        foreach ($statuses as $status) {
            $result[$status['tool_type']] = $status['status'];
        }
        
        return sendSuccess($result);
    } catch (Exception $e) {
        return sendError('Ошибка при получении статуса инструментов: ' . $e->getMessage());
    }
}

/**
 * Включение/выключение инструмента
 */
function toggleTool() {
    $user = requireAuth();
    
    // Получение данных из запроса
    $requestData = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($requestData['equipment_id']) || !isset($requestData['tool_type']) || !isset($requestData['event_type'])) {
        return sendError('Не все обязательные поля заполнены', 400);
    }
    
    $equipmentId = $requestData['equipment_id'];
    $toolType = $requestData['tool_type'];
    $eventType = $requestData['event_type'];
    
    try {
        // Проверяем, что оборудование запущено (только для включения инструмента)
        $lastEvent = fetchOne("
            SELECT event_type
            FROM equipment_events
            WHERE equipment_id = ?
            ORDER BY event_time DESC
            LIMIT 1
        ", [$equipmentId]);
        
        if (!$lastEvent) {
            return sendError('Оборудование не имеет событий', 400);
        }
        
        // Для включения инструмента оборудование должно быть запущено
        if ($eventType === 'on' && $lastEvent['event_type'] !== 'pusk') {
            return sendError('Нельзя включить инструмент когда оборудование остановлено', 400);
        }
        
        // Определяем текущую смену
        $currentHour = (int)date('H');
        $shiftId = 1; // По умолчанию первая смена
        if ($currentHour >= 8 && $currentHour < 16) {
            $shiftId = 2;
        } elseif ($currentHour >= 16) {
            $shiftId = 3;
        }
        
        // Используем переданное время события или текущее время
        $eventTime = isset($requestData['event_time']) ? $requestData['event_time'] : date('Y-m-d H:i:s');
        
        // Определяем смену на основе переданного времени события
        if (isset($requestData['event_time'])) {
            $eventDateTime = new DateTime($requestData['event_time']);
            $eventHour = (int)$eventDateTime->format('H');
            if ($eventHour >= 8 && $eventHour < 16) {
                $shiftId = 2;
            } elseif ($eventHour >= 16) {
                $shiftId = 3;
            }
        }
        
        // Создаем новое событие
        $eventData = [
            'equipment_id' => $equipmentId,
            'tool_type' => $toolType,
            'event_type' => $eventType,
            'event_time' => $eventTime,
            'shift_id' => $shiftId,
            'user_id' => $user['id']
        ];
        
        // Добавляем комментарий если он передан
        if (isset($requestData['comment']) && !empty($requestData['comment'])) {
            $eventData['comment'] = $requestData['comment'];
        }
        
        $eventId = insert('equipment_tool_events', $eventData);
        
        return sendSuccess([
            'message' => 'Статус инструмента успешно изменен',
            'event_id' => $eventId
        ]);
        
    } catch (Exception $e) {
        return sendError('Ошибка при изменении статуса инструмента: ' . $e->getMessage());
    }
} 