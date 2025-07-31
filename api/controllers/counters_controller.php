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
        $user = requireAuth();
        
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
                // Получаем информацию о счетчике
                $stmt = $db->prepare('SELECT coefficient_k FROM meters WHERE id = ?');
                $stmt->execute([$meterId]);
                $meter = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$meter) {
                    throw new Exception("Счетчик не найден");
                }

                // Проверяем, была ли замена счетчика в этот день
                $stmt = $db->prepare('
                    SELECT replacement_time, old_coefficient, new_coefficient, old_reading, new_reading, downtime_min, power_mw
                    FROM meter_replacements 
                    WHERE meter_id = ? AND replacement_date = ?
                ');
                $stmt->execute([$meterId, $date]);
                $replacement = $stmt->fetch(PDO::FETCH_ASSOC);

                // Валидация показаний
                if ($replacement) {
                    // Если была замена, проверяем показания с учетом времени замены
                    $replacementTime = $replacement['replacement_time'];
                    $shift8Time = '08:00';
                    $shift16Time = '16:00';
                    $shift24Time = '24:00';

                    // Проверяем показания до замены
                    if ($replacementTime > $shift8Time) {
                        // До замены в первой смене
                        if (isset($reading['r0']) && isset($reading['r8']) && $reading['r8'] < $reading['r0']) {
                            throw new Exception("Показание R8 не может быть меньше R0 (до замены счетчика)");
                        }
                    }
                    if ($replacementTime > $shift16Time) {
                        // До замены во второй смене
                        if (isset($reading['r8']) && isset($reading['r16']) && $reading['r16'] < $reading['r8']) {
                            throw new Exception("Показание R16 не может быть меньше R8 (до замены счетчика)");
                        }
                    }
                    if ($replacementTime > $shift24Time) {
                        // До замены в третьей смене
                        if (isset($reading['r16']) && isset($reading['r24']) && $reading['r24'] < $reading['r16']) {
                            throw new Exception("Показание R24 не может быть меньше R16 (до замены счетчика)");
                        }
                    }

                    // Проверяем показания после замены
                    if ($replacementTime < $shift8Time) {
                        // Замена в первой смене
                        if (isset($reading['r8']) && $reading['r8'] < $replacement['new_reading']) {
                            throw new Exception("Показание R8 не может быть меньше показания нового счетчика");
                        }
                        if (isset($reading['r16']) && $reading['r16'] < $replacement['new_reading']) {
                            throw new Exception("Показание R16 не может быть меньше показания нового счетчика");
                        }
                        if (isset($reading['r24']) && $reading['r24'] < $replacement['new_reading']) {
                            throw new Exception("Показание R24 не может быть меньше показания нового счетчика");
                        }
                    } else if ($replacementTime < $shift16Time) {
                        // Замена во второй смене
                        if (isset($reading['r16']) && $reading['r16'] < $replacement['new_reading']) {
                            throw new Exception("Показание R16 не может быть меньше показания нового счетчика");
                        }
                        if (isset($reading['r24']) && $reading['r24'] < $replacement['new_reading']) {
                            throw new Exception("Показание R24 не может быть меньше показания нового счетчика");
                        }
                        // Проверяем R24 > R16, так как замена была во второй смене
                        if (isset($reading['r16']) && isset($reading['r24']) && $reading['r24'] < $reading['r16']) {
                            throw new Exception("Показание R24 не может быть меньше R16");
                        }
                    } else if ($replacementTime < $shift24Time) {
                        // Замена в третьей смене
                        if (isset($reading['r24']) && $reading['r24'] < $replacement['new_reading']) {
                            throw new Exception("Показание R24 не может быть меньше показания нового счетчика");
                        }
                    }
                } else {
                    // Если замены не было, проверяем последовательность показаний
                    if (isset($reading['r0']) && isset($reading['r8']) && $reading['r8'] < $reading['r0']) {
                        throw new Exception("Показание R8 не может быть меньше R0");
                    }
                    if (isset($reading['r8']) && isset($reading['r16']) && $reading['r16'] < $reading['r8']) {
                        throw new Exception("Показание R16 не может быть меньше R8");
                    }
                    if (isset($reading['r16']) && isset($reading['r24']) && $reading['r24'] < $reading['r16']) {
                        throw new Exception("Показание R24 не может быть меньше R16");
                    }
                }

                // Рассчитываем значения смен
                $shift1 = null;
                $shift2 = null;
                $shift3 = null;

                if ($replacement) {
                    // Если была замена, рассчитываем с учетом времени замены
                    $replacementTime = $replacement['replacement_time'];
                    $shift8Time = '08:00';
                    $shift16Time = '16:00';
                    $shift24Time = '24:00';

                    // Смена 1 (0-8)
                    if (isset($reading['r0']) && isset($reading['r8'])) {
                        if ($replacementTime < $shift8Time) { // замена произошла во время первой смены
                            // Расчет по старому счетчику до замены
                            $beforeReplacement = ($replacement['old_reading'] - $reading['r0']) * $replacement['old_coefficient'] / 1000;
                            // Расчет по новому счетчику после замены
                            $afterReplacement = ($reading['r8'] - $replacement['new_reading']) * $replacement['new_coefficient'] / 1000;
                            // Расчет простоя
                            $downtimePower = ($replacement['downtime_min'] / 60) * $replacement['power_mw'];
                            $shift1 = $beforeReplacement + $downtimePower + $afterReplacement;
                        } else { // замена произошла после первой смены
                            $shift1 = ($reading['r8'] - $reading['r0']) * $replacement['old_coefficient'] / 1000;
                        }
                    }

                    // Смена 2 (8-16)
                    if (isset($reading['r8']) && isset($reading['r16'])) {
                        if ($replacementTime >= $shift8Time && $replacementTime < $shift16Time) { // замена произошла во время второй смены
                            // Расчет по старому счетчику до замены
                            $beforeReplacement = ($replacement['old_reading'] - $reading['r8']) * $replacement['old_coefficient'] / 1000;
                            // Расчет по новому счетчику после замены
                            $afterReplacement = ($reading['r16'] - $replacement['new_reading']) * $replacement['new_coefficient'] / 1000;
                            // Расчет простоя
                            $downtimePower = ($replacement['downtime_min'] / 60) * $replacement['power_mw'];
                            $shift2 = $beforeReplacement + $downtimePower + $afterReplacement;
                        } else if ($replacementTime < $shift8Time) { // замена произошла до второй смены
                            $shift2 = ($reading['r16'] - $reading['r8']) * $replacement['new_coefficient'] / 1000;
                        } else { // замена произошла после второй смены
                            $shift2 = ($reading['r16'] - $reading['r8']) * $replacement['old_coefficient'] / 1000;
                        }
                    }

                    // Смена 3 (16-24)
                    if (isset($reading['r16']) && isset($reading['r24'])) {
                        if ($replacementTime >= $shift16Time && $replacementTime < $shift24Time) { // замена произошла во время третьей смены
                            // Расчет по старому счетчику до замены
                            $beforeReplacement = ($replacement['old_reading'] - $reading['r16']) * $replacement['old_coefficient'] / 1000;
                            // Расчет по новому счетчику после замены
                            $afterReplacement = ($reading['r24'] - $replacement['new_reading']) * $replacement['new_coefficient'] / 1000;
                            // Расчет простоя
                            $downtimePower = ($replacement['downtime_min'] / 60) * $replacement['power_mw'];
                            $shift3 = $beforeReplacement + $downtimePower + $afterReplacement;
                        } else if ($replacementTime < $shift16Time) { // замена произошла до третьей смены
                            $shift3 = ($reading['r24'] - $reading['r16']) * $replacement['new_coefficient'] / 1000;
                        } else { // замена произошла после третьей смены
                            $shift3 = ($reading['r24'] - $reading['r16']) * $replacement['old_coefficient'] / 1000;
                        }
                    }
                } else {
                    // Если замены не было, используем обычный расчет
                    if (isset($reading['r0']) && isset($reading['r8'])) {
                        $shift1 = ($reading['r8'] - $reading['r0']) * $meter['coefficient_k'] / 1000;
                    }
                    if (isset($reading['r8']) && isset($reading['r16'])) {
                        $shift2 = ($reading['r16'] - $reading['r8']) * $meter['coefficient_k'] / 1000;
                    }
                    if (isset($reading['r16']) && isset($reading['r24'])) {
                        $shift3 = ($reading['r24'] - $reading['r16']) * $meter['coefficient_k'] / 1000;
                    }
                }

                $total = ($shift1 !== null ? $shift1 : 0) + 
                        ($shift2 !== null ? $shift2 : 0) + 
                        ($shift3 !== null ? $shift3 : 0);

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
                            user_id = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ');
                    $stmt->execute([
                        $reading['r0'],
                        $reading['r8'],
                        $reading['r16'],
                        $reading['r24'],
                        $shift1,
                        $shift2,
                        $shift3,
                        $total,
                        $user['id'],
                        $existing['id']
                    ]);
                } else {
                    // Создаем новые показания
                    $stmt = $db->prepare('
                        INSERT INTO meter_readings 
                        (meter_id, date, r0, r8, r16, r24, shift1, shift2, shift3, total, user_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $meterId,
                        $date,
                        $reading['r0'],
                        $reading['r8'],
                        $reading['r16'],
                        $reading['r24'],
                        $shift1,
                        $shift2,
                        $shift3,
                        $total,
                        $user['id']
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
 * Получение информации о замене счетчика по дате
 */
function getReplacementByDate() {
    try {
        if (!isset($_GET['meter_id']) || !isset($_GET['date'])) {
            sendError('Не указан счетчик или дата');
            return;
        }

        $meterId = $_GET['meter_id'];
        $date = $_GET['date'];
        $db = getDbConnection();
        
        $stmt = $db->prepare('
            SELECT * FROM meter_replacements 
            WHERE meter_id = ? AND replacement_date = ?
        ');
        $stmt->execute([$meterId, $date]);
        $replacement = $stmt->fetch(PDO::FETCH_ASSOC);
        
        sendSuccess($replacement ? $replacement : null);
    } catch (Exception $e) {
        sendError('Ошибка при получении данных о замене: ' . $e->getMessage());
    }
}

/**
 * Сохранение замены счетчика
 */
function saveReplacement() {
    try {
        $user = requireAuth();
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['meter_id']) || !isset($input['replacement_date'])) {
            sendError('Неверный формат данных');
            return;
        }

        // Валидация числовых значений
        if (!is_numeric($input['new_reading']) || $input['new_reading'] < 0) {
            sendError('Показание нового счетчика должно быть числом не меньше 0');
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
                downtime_min, power_mw, user_id)
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
                $user['id']
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

/**
 * Обновление информации о замене счетчика
 */
function updateReplacement($replacementId) {
    try {
        $user = requireAuth();
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['meter_id']) || !isset($input['replacement_date'])) {
            sendError('Неверный формат данных');
            return;
        }

        // Валидация числовых значений
        if (!is_numeric($input['new_reading']) || $input['new_reading'] < 0) {
            sendError('Показание нового счетчика должно быть числом не меньше 0');
            return;
        }

        $db = getDbConnection();
        
        // Проверяем существование записи
        $stmt = $db->prepare('SELECT * FROM meter_replacements WHERE id = ?');
        $stmt->execute([$replacementId]);
        $existingReplacement = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingReplacement) {
            sendError('Запись о замене счетчика не найдена', 404);
            return;
        }
        
        $db->beginTransaction();
        
        try {
            // Обновляем информацию о замене
            $stmt = $db->prepare('
                UPDATE meter_replacements 
                SET replacement_date = ?, replacement_time = ?,
                    old_serial = ?, old_coefficient = ?, old_scale = ?, old_reading = ?,
                    new_serial = ?, new_coefficient = ?, new_scale = ?, new_reading = ?,
                    downtime_min = ?, power_mw = ?, comment = ?,
                    user_id = ?
                WHERE id = ?
            ');
            $stmt->execute([
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
                $input['comment'] ?? '',
                $user['id'],
                $replacementId
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
            sendSuccess(['message' => 'Данные о замене счетчика успешно обновлены']);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при обновлении замены счетчика: ' . $e->getMessage());
    }
}

/**
 * Отмена замены счетчика
 */
function cancelReplacement() {
    try {
        $user = requireAuth();
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['meter_id']) || !isset($input['replacement_date'])) {
            sendError('Неверный формат данных');
            return;
        }
        
        $meterId = $input['meter_id'];
        $date = $input['replacement_date'];
        $db = getDbConnection();
        
        $db->beginTransaction();
        
        try {
            // Получаем запись о замене
            $stmt = $db->prepare('
                SELECT * FROM meter_replacements 
                WHERE meter_id = ? AND replacement_date = ?
            ');
            $stmt->execute([$meterId, $date]);
            $replacement = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$replacement) {
                $db->rollBack();
                sendError('Запись о замене счетчика не найдена');
                return;
            }
            
            // Восстанавливаем оригинальные данные счетчика
            $stmt = $db->prepare('
                UPDATE meters 
                SET serial_number = ?, coefficient_k = ?, scale = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $replacement['old_serial'],
                $replacement['old_coefficient'],
                $replacement['old_scale'],
                $meterId
            ]);
            
            // Логируем операцию отмены замены
            error_log("Attempting to log cancellation: meter_id=$meterId, replacement_id={$replacement['id']}, date=$date, user_id={$user['id']}");
            
            $stmt = $db->prepare('
                INSERT INTO meter_replacement_cancellations 
                (meter_id, replacement_id, cancellation_date, user_id)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$meterId, $replacement['id'], $date, $user['id']]);
            
            error_log("Cancellation logged successfully");
            
            // Удаляем запись о замене
            $stmt = $db->prepare('
                DELETE FROM meter_replacements 
                WHERE meter_id = ? AND replacement_date = ?
            ');
            $stmt->execute([$meterId, $date]);
            
            $db->commit();
            sendSuccess(['message' => 'Замена счетчика успешно отменена']);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при отмене замены счетчика: ' . $e->getMessage());
    }
} 