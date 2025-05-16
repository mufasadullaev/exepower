<?php

require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/response.php';

/**
 * Получение типов счетчиков
 */
function getMeterTypes() {
    try {
        $db = getDbConnection();
        $stmt = $db->query('SELECT * FROM meter_types ORDER BY name');
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendSuccess($types);
    } catch (Exception $e) {
        sendError('Ошибка при получении типов счетчиков: ' . $e->getMessage());
    }
}

/**
 * Получение счетчиков по типу
 */
function getMeters() {
    try {
        if (!isset($_GET['type_id'])) {
            sendError('Не указан тип счетчика');
            return;
        }

        $typeId = $_GET['type_id'];
        $db = getDbConnection();
        
        $stmt = $db->prepare('
            SELECT m.*, mt.name as type_name, e.name as equipment_name
            FROM meters m
            JOIN meter_types mt ON m.meter_type_id = mt.id
            JOIN equipment e ON m.equipment_id = e.id
            WHERE m.meter_type_id = ? AND m.is_active = 1
            ORDER BY m.name
        ');
        $stmt->execute([$typeId]);
        $meters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess($meters);
    } catch (Exception $e) {
        sendError('Ошибка при получении счетчиков: ' . $e->getMessage());
    }
}

/**
 * Получение показаний на дату
 */
function getReadings() {
    try {
        if (!isset($_GET['date'])) {
            sendError('Не указана дата');
            return;
        }

        $date = $_GET['date'];
        $db = getDbConnection();
        
        // Получаем показания на указанную дату
        $stmt = $db->prepare('
            SELECT mr.*, m.name as meter_name, m.coefficient_k
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE mr.date = ?
        ');
        $stmt->execute([$date]);
        $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Форматируем данные для фронтенда
        $formattedReadings = [];
        foreach ($readings as $reading) {
            $formattedReadings[$reading['meter_id']] = $reading;
        }
        
        sendSuccess($formattedReadings);
    } catch (Exception $e) {
        sendError('Ошибка при получении показаний: ' . $e->getMessage());
    }
}

/**
 * Сохранение показаний
 */
function saveReadings() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['date']) || !isset($input['readings'])) {
            sendError('Неверный формат данных');
            return;
        }

        $date = $input['date'];
        $readings = $input['readings'];
        $db = getDbConnection();
        
        $db->beginTransaction();
        
        try {
            foreach ($readings as $meterId => $reading) {
                // Проверяем существование показаний
                $stmt = $db->prepare('
                    SELECT id FROM meter_readings 
                    WHERE meter_id = ? AND date = ?
                ');
                $stmt->execute([$meterId, $date]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Обновляем существующие показания
                    $stmt = $db->prepare('
                        UPDATE meter_readings 
                        SET r0 = ?, r8 = ?, r16 = ?, r24 = ?,
                            shift1 = ?, shift2 = ?, shift3 = ?, total = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ');
                    $stmt->execute([
                        $reading['r0'],
                        $reading['r8'],
                        $reading['r16'],
                        $reading['r24'],
                        $reading['shift1'],
                        $reading['shift2'],
                        $reading['shift3'],
                        $reading['total'],
                        $existing['id']
                    ]);
                } else {
                    // Создаем новые показания
                    $stmt = $db->prepare('
                        INSERT INTO meter_readings 
                        (meter_id, date, r0, r8, r16, r24, shift1, shift2, shift3, total)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $meterId,
                        $date,
                        $reading['r0'],
                        $reading['r8'],
                        $reading['r16'],
                        $reading['r24'],
                        $reading['shift1'],
                        $reading['shift2'],
                        $reading['shift3'],
                        $reading['total']
                    ]);
                }
            }
            
            $db->commit();
            sendSuccess(['message' => 'Показания успешно сохранены']);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при сохранении показаний: ' . $e->getMessage());
    }
}

/**
 * Сохранение замены счетчика
 */
function saveReplacement() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['meter_id']) || !isset($input['replacement_date'])) {
            sendError('Неверный формат данных');
            return;
        }

        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // Сохраняем информацию о замене
            $stmt = $db->prepare('
                INSERT INTO meter_replacements 
                (meter_id, replacement_date, replacement_time, old_serial, old_coefficient,
                old_scale, old_reading, new_serial, new_coefficient, new_scale, new_reading,
                downtime_min, power_mw, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $input['meter_id'],
                $input['replacement_date'],
                $input['replacement_time'],
                $input['old_serial'],
                $input['old_coefficient'],
                $input['old_scale'],
                $input['old_reading'],
                $input['new_serial'],
                $input['new_coefficient'],
                $input['new_scale'],
                $input['new_reading'],
                $input['downtime_min'],
                $input['power_mw'],
                $_SESSION['user_id'] ?? null
            ]);
            
            // Обновляем информацию о счетчике
            $stmt = $db->prepare('
                UPDATE meters 
                SET serial_number = ?, coefficient_k = ?, scale = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $input['new_serial'],
                $input['new_coefficient'],
                $input['new_scale'],
                $input['meter_id']
            ]);
            
            $db->commit();
            sendSuccess(['message' => 'Замена счетчика успешно сохранена']);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при сохранении замены счетчика: ' . $e->getMessage());
    }
} 