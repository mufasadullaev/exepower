<?php
/**
 * Energy Controller
 * Handles API endpoints related to energy metrics, meters, readings and replacements
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Get all energy metrics
 */
function getEnergyMetrics() {
    // Check authentication
    requireAuth();

    try {
        // Get all energy metrics from the database
        $sql = "SELECT id, name, description FROM energy_metrics ORDER BY name";
        $metrics = fetchAll($sql);

        sendSuccess(['metrics' => $metrics]);
    } catch (Exception $e) {
        sendError('Ошибка при получении показателей электроэнергии: ' . $e->getMessage());
    }
}

/**
 * Get meters by energy metric
 * 
 * @param int $metricId Optional metric ID filter
 */
function getMeters($metricId = null) {
    // Check authentication
    requireAuth();

    try {
        // Build query based on whether metric_id is provided
        $params = [];
        $sql = "SELECT m.id, m.energy_metric_id, m.name, m.coefficient, em.name as metric_name 
                FROM meters m
                JOIN energy_metrics em ON m.energy_metric_id = em.id";
        
        if ($metricId !== null) {
            $sql .= " WHERE m.energy_metric_id = ?";
            $params[] = $metricId;
        }
        
        $sql .= " ORDER BY m.name";
        
        $meters = fetchAll($sql, $params);

        sendSuccess(['meters' => $meters]);
    } catch (Exception $e) {
        sendError('Ошибка при получении счётчиков: ' . $e->getMessage());
    }
}

/**
 * Get meter readings for a specific date and metric
 * 
 * @param string $date Date in YYYY-MM-DD format
 * @param int $metricId Energy metric ID
 */
function getMeterReadings($date, $metricId) {
    // Check authentication
    requireAuth();

    try {
        // Validate inputs
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            sendError('Некорректный формат даты', 400);
        }
        
        if (!$metricId || !is_numeric($metricId)) {
            sendError('Некорректный ID показателя', 400);
        }

        // Get all meters for this metric
        $metersSql = "SELECT id, name, coefficient FROM meters 
                      WHERE energy_metric_id = ? ORDER BY name";
        $meters = fetchAll($metersSql, [$metricId]);
        
        if (empty($meters)) {
            sendSuccess([
                'date' => $date,
                'metric_id' => $metricId,
                'meters' => [],
                'readings' => []
            ]);
            return;
        }
        
        // Get all shifts
        $shiftsSql = "SELECT id, name, start_time, end_time FROM shifts ORDER BY id";
        $shifts = fetchAll($shiftsSql);
        
        // Get readings for these meters on this date
        $meterIds = array_column($meters, 'id');
        $placeholders = implode(',', array_fill(0, count($meterIds), '?'));
        
        $readingsSql = "SELECT mr.id, mr.meter_id, mr.shift_id, mr.reading_start, mr.reading_end, 
                               mr.consumption
                        FROM meter_readings mr
                        WHERE mr.date = ? AND mr.meter_id IN ($placeholders)
                        ORDER BY mr.meter_id, mr.shift_id";
        
        $params = array_merge([$date], $meterIds);
        $readings = fetchAll($readingsSql, $params);
        
        // Get meter replacements for this date
        $replacementsSql = "SELECT * FROM meter_replacements 
                           WHERE DATE(replacement_dt) = ? 
                           AND meter_id IN ($placeholders)";
        
        $replacements = fetchAll($replacementsSql, $params);
        
        // Get previous day's last readings (shift 3)
        $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $prevReadingsSql = "SELECT mr.meter_id, mr.reading_end as prev_reading 
                           FROM meter_readings mr
                           WHERE mr.date = ? AND mr.meter_id IN ($placeholders) AND mr.shift_id = 3
                           ORDER BY mr.meter_id";
        
        $prevParams = array_merge([$prevDate], $meterIds);
        $prevReadings = fetchAll($prevReadingsSql, $prevParams);
        
        // Create a map of previous readings by meter_id
        $prevReadingsMap = [];
        foreach ($prevReadings as $prevReading) {
            $prevReadingsMap[$prevReading['meter_id']] = $prevReading['prev_reading'];
        }
        
        // Add previous reading to each meter
        foreach ($meters as &$meter) {
            $meter['prev_reading'] = $prevReadingsMap[$meter['id']] ?? 0;
        }
        
        // Organize readings by meter_id and shift_id for easy access
        $readingsMap = [];
        foreach ($readings as $reading) {
            $readingsMap[$reading['meter_id']][$reading['shift_id']] = $reading;
        }
        
        // Organize replacements by meter_id
        $replacementsMap = [];
        foreach ($replacements as $replacement) {
            $replacementsMap[$replacement['meter_id']] = $replacement;
        }

        sendSuccess([
            'date' => $date,
            'metric_id' => $metricId,
            'meters' => $meters,
            'shifts' => $shifts,
            'readings' => $readingsMap,
            'replacements' => $replacementsMap
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при получении показаний счётчиков: ' . $e->getMessage());
    }
}

/**
 * Save meter readings in bulk
 */
function saveMeterReadingsBulk() {
    // Check authentication
    requireAuth();

    try {
        // Get JSON data from request
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['date']) || !isset($data['readings']) || !is_array($data['readings'])) {
            sendError('Некорректные данные запроса', 400);
        }

        $date = $data['date'];
        $readings = $data['readings'];
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            sendError('Некорректный формат даты', 400);
        }
        
        // Start a transaction
        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // Process each reading
            foreach ($readings as $reading) {
                if (!isset($reading['meter_id']) || !isset($reading['shift_id']) || 
                    !isset($reading['reading_start']) || !isset($reading['reading_end'])) {
                    throw new Exception('Некорректные данные показаний');
                }
                
                // Get meter coefficient from meters table
                $meterSql = "SELECT coefficient FROM meters WHERE id = ?";
                $meterData = fetchOne($meterSql, [$reading['meter_id']]);
                if (!$meterData) {
                    throw new Exception('Счетчик не найден');
                }
                
                $coefficient = $meterData['coefficient'];
                
                // Используем значение consumption из запроса, если оно предоставлено
                // Иначе рассчитываем его на сервере
                $consumption = isset($reading['consumption']) 
                    ? $reading['consumption'] 
                    : ($reading['reading_end'] - $reading['reading_start']) * $coefficient / 1000;
                
                // Check if reading already exists
                $checkSql = "SELECT id FROM meter_readings 
                            WHERE meter_id = ? AND date = ? AND shift_id = ?";
                $existingReading = fetchOne($checkSql, [
                    $reading['meter_id'], 
                    $date, 
                    $reading['shift_id']
                ]);
                
                if ($existingReading) {
                    // Update existing reading
                    $updateSql = "UPDATE meter_readings 
                                 SET reading_start = ?, reading_end = ?, coefficient = ?, consumption = ?
                                 WHERE id = ?";
                    executeQuery($updateSql, [
                        $reading['reading_start'],
                        $reading['reading_end'],
                        $coefficient,
                        $consumption,
                        $existingReading['id']
                    ]);
                } else {
                    // Insert new reading
                    $insertSql = "INSERT INTO meter_readings 
                                 (meter_id, date, shift_id, reading_start, reading_end, coefficient, consumption)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                    executeQuery($insertSql, [
                        $reading['meter_id'],
                        $date,
                        $reading['shift_id'],
                        $reading['reading_start'],
                        $reading['reading_end'],
                        $coefficient,
                        $consumption
                    ]);
                }
            }
            
            // Commit the transaction
            $db->commit();
            
            sendSuccess(['message' => 'Показания успешно сохранены']);
        } catch (Exception $e) {
            // Rollback the transaction on error
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при сохранении показаний: ' . $e->getMessage());
    }
}

/**
 * Save meter readings in bulk for the new format (readings at 0h, 8h, 16h, 24h)
 */
function saveMeterReadingsBulkNew() {
    // Check authentication
    requireAuth();

    try {
        // Get JSON data from request
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['date']) || !isset($data['readings']) || !is_array($data['readings'])) {
            sendError('Некорректные данные запроса', 400);
        }

        $date = $data['date'];
        $readings = $data['readings'];
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            sendError('Некорректный формат даты', 400);
        }
        
        // Start a transaction
        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // Process each meter's readings
            foreach ($readings as $reading) {
                if (!isset($reading['meter_id']) || !isset($reading['reading0']) || 
                    !isset($reading['reading8']) || !isset($reading['reading16']) || 
                    !isset($reading['reading24'])) {
                    throw new Exception('Некорректные данные показаний');
                }
                
                $meterId = $reading['meter_id'];
                
                // Get meter coefficient from meters table
                $meterSql = "SELECT coefficient FROM meters WHERE id = ?";
                $meterData = fetchOne($meterSql, [$meterId]);
                if (!$meterData) {
                    throw new Exception('Счетчик не найден');
                }
                
                $coefficient = $meterData['coefficient'] ?? 1; // Default to 1 if not found
                
                // Рассчитываем consumption для каждой смены
                $consumption1 = isset($reading['consumption1']) 
                    ? $reading['consumption1'] 
                    : ($reading['reading8'] - $reading['reading0']) * $coefficient / 1000;
                
                $consumption2 = isset($reading['consumption2']) 
                    ? $reading['consumption2'] 
                    : ($reading['reading16'] - $reading['reading8']) * $coefficient / 1000;
                
                $consumption3 = isset($reading['consumption3']) 
                    ? $reading['consumption3'] 
                    : ($reading['reading24'] - $reading['reading16']) * $coefficient / 1000;
                
                // Shift 1 (0h to 8h)
                saveShiftReading($meterId, $date, 1, $reading['reading0'], $reading['reading8'], $consumption1);
                
                // Shift 2 (8h to 16h)
                saveShiftReading($meterId, $date, 2, $reading['reading8'], $reading['reading16'], $consumption2);
                
                // Shift 3 (16h to 24h)
                saveShiftReading($meterId, $date, 3, $reading['reading16'], $reading['reading24'], $consumption3);
            }
            
            // Commit the transaction
            $db->commit();
            
            sendSuccess(['message' => 'Показания успешно сохранены']);
        } catch (Exception $e) {
            // Rollback the transaction on error
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при сохранении показаний: ' . $e->getMessage());
    }
}

/**
 * Helper function to save a single shift reading
 * 
 * @param int $meterId Meter ID
 * @param string $date Date in YYYY-MM-DD format
 * @param int $shiftId Shift ID (1, 2, or 3)
 * @param float $readingStart Start reading
 * @param float $readingEnd End reading
 * @param float $consumption Optional consumption value
 */
function saveShiftReading($meterId, $date, $shiftId, $readingStart, $readingEnd, $consumption = null) {
    // Check if reading already exists
    $checkSql = "SELECT id FROM meter_readings 
                WHERE meter_id = ? AND date = ? AND shift_id = ?";
    $existingReading = fetchOne($checkSql, [
        $meterId, 
        $date, 
        $shiftId
    ]);
    
    // Get meter coefficient from meters table
    $meterSql = "SELECT coefficient FROM meters WHERE id = ?";
    $meterData = fetchOne($meterSql, [$meterId]);
    $coefficient = $meterData['coefficient'] ?? 1; // Default to 1 if not found
    
    // Используем предоставленное значение consumption или рассчитываем его
    $actualConsumption = $consumption !== null 
        ? $consumption 
        : ($readingEnd - $readingStart) * $coefficient / 1000;
    
    if ($existingReading) {
        // Update existing reading
        $updateSql = "UPDATE meter_readings 
                     SET reading_start = ?, reading_end = ?, coefficient = ?, consumption = ?
                     WHERE id = ?";
        executeQuery($updateSql, [
            $readingStart,
            $readingEnd,
            $coefficient,
            $actualConsumption,
            $existingReading['id']
        ]);
    } else {
        // Insert new reading
        $insertSql = "INSERT INTO meter_readings 
                     (meter_id, date, shift_id, reading_start, reading_end, coefficient, consumption)
                     VALUES (?, ?, ?, ?, ?, ?, ?)";
        executeQuery($insertSql, [
            $meterId,
            $date,
            $shiftId,
            $readingStart,
            $readingEnd,
            $coefficient,
            $actualConsumption
        ]);
    }
}

/**
 * Save meter replacement
 */
function saveMeterReplacement() {
    // Check authentication
    requireAuth();

    try {
        // Get JSON data from request
        $rawData = file_get_contents('php://input');
        $data = json_decode($rawData, true);
        
        // Debug the received data
        error_log("Received meter replacement data: " . $rawData);
        
        // Check required fields
        $requiredFields = ['meter_id', 'date', 'replacement_time', 'old_reading', 
                          'new_coefficient', 'new_scale', 'new_reading'];
        $missingFields = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            sendError('Некорректные данные запроса. Отсутствуют поля: ' . implode(', ', $missingFields), 400);
            return;
        }
        
        // Start a transaction
        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // Get meter information (to get the old coefficient and scale)
            $meterSql = "SELECT coefficient, name FROM meters WHERE id = ?";
            $meterInfo = fetchOne($meterSql, [$data['meter_id']]);
            
            if (!$meterInfo) {
                throw new Exception('Счётчик не найден (ID: ' . $data['meter_id'] . ')');
            }
            
            // Determine shift based on replacement time
            $timeHour = (int)substr($data['replacement_time'], 0, 2);
            $shiftId = 1; // Default to shift 1
            
            if ($timeHour >= 0 && $timeHour < 8) {
                $shiftId = 1;
            } else if ($timeHour >= 8 && $timeHour < 16) {
                $shiftId = 2;
            } else {
                $shiftId = 3;
            }
            
            // Get the reading boundaries for this shift
            $readingsSql = "SELECT id, reading_start, reading_end FROM meter_readings 
                           WHERE meter_id = ? AND date = ? AND shift_id = ?";
            $readingsData = fetchOne($readingsSql, [
                $data['meter_id'],
                $data['date'],
                $shiftId
            ]);
            
            if (!$readingsData) {
                throw new Exception('Показания для этой смены не найдены. Meter ID: ' . $data['meter_id'] . 
                                   ', Date: ' . $data['date'] . ', Shift: ' . $shiftId);
            }
            
            // Format the replacement datetime
            $replacementDt = $data['date'] . ' ' . $data['replacement_time'] . ':00';
            
            // Update the existing meter with new serial, name, coefficient and scale
            try {
                $updateMeterSql = "UPDATE meters 
                                  SET serial = ?,
                                      coefficient = ?, 
                                      scale = ? 
                                  WHERE id = ?";
                $stmt = $db->prepare($updateMeterSql);
                $stmt->execute([
                    $data['new_serial'],
                    $data['new_coefficient'],
                    $data['new_scale'],
                    $data['meter_id']
                ]);
            } catch (PDOException $e) {
                throw new Exception('SQL ошибка при обновлении счётчика: ' . $e->getMessage());
            }
            
            // Insert the replacement record
            $insertSql = "INSERT INTO meter_replacements 
                         (meter_id, replacement_dt, old_coefficient, old_scale, old_reading,
                          new_coefficient, new_scale, new_reading,
                          downtime_minutes, power_at_replacement)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            try {
                $stmt = $db->prepare($insertSql);
                $stmt->execute([
                    $data['meter_id'],
                    $replacementDt,
                    $meterInfo['coefficient'],
                    $data['old_scale'] ?? '99999.999',
                    $data['old_reading'],
                    $data['new_coefficient'],
                    $data['new_scale'] ?? '99999.999',
                    $data['new_reading'] ?? 0,
                    $data['downtime_minutes'] ?? 0,
                    $data['power_at_replacement'] ?? 0
                ]);
                
                $replacementId = $db->lastInsertId();
                
                if (!$replacementId) {
                    throw new Exception('Не удалось получить ID записи о замене счётчика');
                }
            } catch (PDOException $e) {
                throw new Exception('SQL ошибка при создании записи о замене: ' . $e->getMessage());
            }
            
            // Calculate corrected consumption
            $readingStart = $readingsData['reading_start'];
            $readingEnd = $readingsData['reading_end'];
            $oldReading = $data['old_reading'];
            $newReading = $data['new_reading'];
            $oldCoefficient = $meterInfo['coefficient'];
            $newCoefficient = $data['new_coefficient'];
            $downtimeMinutes = $data['downtime_minutes'] ?? 0;
            $powerAtReplacement = $data['power_at_replacement'] ?? 0;
            
            // Calculate parts according to the formula
            // Формула: (старые показания - начальные показания) * старый коэффициент / 1000 + 
            //          (время простоя в минутах / 60) * мощность при замене +
            //          (конечные показания - новые показания) * новый коэффициент / 1000
            $part1 = ($oldReading - $readingStart) * $oldCoefficient / 1000;
            $part2 = ($downtimeMinutes / 60) * $powerAtReplacement;
            $part3 = ($readingEnd - $newReading) * $newCoefficient / 1000;
            $correctedConsumption = $part1 + $part2 + $part3;
            
            // Отладочная информация
            error_log("Corrected consumption calculation:");
            error_log("Part1: ($oldReading - $readingStart) * $oldCoefficient / 1000 = $part1");
            error_log("Part2: ($downtimeMinutes / 60) * $powerAtReplacement = $part2");
            error_log("Part3: ($readingEnd - $newReading) * $newCoefficient / 1000 = $part3");
            error_log("Total: $correctedConsumption");
            
            // Ensure transaction isolation level is set to prevent interference
            $db->exec("SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE");
            
            // Используем прямые SQL-запросы для обновления consumption и coefficient
            try {
                // Обновляем текущую запись с новым коэффициентом и скорректированным потреблением
                // Используем prepared statement вместо прямого SQL для безопасности
                $updateCurrentSql = "UPDATE meter_readings 
                                    SET coefficient = ?, 
                                        consumption = ? 
                                    WHERE id = ?";
                $stmt = $db->prepare($updateCurrentSql);
                $stmt->execute([$newCoefficient, $correctedConsumption, $readingsData['id']]);
                error_log("Updated current reading with ID: {$readingsData['id']}, rows affected: " . $stmt->rowCount());
                
                // Обновляем все остальные записи для этого счетчика
                // Рассчитываем consumption вручную для каждой записи
                $getOtherReadingsSql = "SELECT id, reading_start, reading_end FROM meter_readings 
                                      WHERE meter_id = ? AND id != ?";
                $otherReadingsStmt = $db->prepare($getOtherReadingsSql);
                $otherReadingsStmt->execute([$data['meter_id'], $readingsData['id']]);
                $otherReadings = $otherReadingsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($otherReadings as $reading) {
                    $otherConsumption = ($reading['reading_end'] - $reading['reading_start']) * $newCoefficient / 1000;
                    $updateReadingSql = "UPDATE meter_readings 
                                       SET coefficient = ?, 
                                           consumption = ? 
                                       WHERE id = ?";
                    $updateStmt = $db->prepare($updateReadingSql);
                    $updateStmt->execute([$newCoefficient, $otherConsumption, $reading['id']]);
                }
                error_log("Updated other readings, count: " . count($otherReadings));
                
                // Обновляем показания текущего дня для других смен с учетом времени замены
                if ($shiftId == 1) {
                    // Обновляем смены 2 и 3 текущего дня
                    $getCurrentDayReadingsSql = "SELECT id, reading_start, reading_end FROM meter_readings
                                              WHERE meter_id = ? 
                                                AND date = ? 
                                                AND shift_id > 1";
                    $currentDayStmt = $db->prepare($getCurrentDayReadingsSql);
                    $currentDayStmt->execute([$data['meter_id'], $data['date']]);
                    $currentDayReadings = $currentDayStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($currentDayReadings as $reading) {
                        $consumption = ($reading['reading_end'] - $reading['reading_start']) * $newCoefficient / 1000;
                        $updateReadingSql = "UPDATE meter_readings 
                                           SET coefficient = ?, 
                                               consumption = ? 
                                           WHERE id = ?";
                        $updateStmt = $db->prepare($updateReadingSql);
                        $updateStmt->execute([$newCoefficient, $consumption, $reading['id']]);
                    }
                    error_log("Updated current day shifts, count: " . count($currentDayReadings));
                } else if ($shiftId == 2) {
                    // Обновляем смену 3 текущего дня
                    $getCurrentDayReadingsSql = "SELECT id, reading_start, reading_end FROM meter_readings
                                              WHERE meter_id = ? 
                                                AND date = ? 
                                                AND shift_id = 3";
                    $currentDayStmt = $db->prepare($getCurrentDayReadingsSql);
                    $currentDayStmt->execute([$data['meter_id'], $data['date']]);
                    $currentDayReadings = $currentDayStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($currentDayReadings as $reading) {
                        $consumption = ($reading['reading_end'] - $reading['reading_start']) * $newCoefficient / 1000;
                        $updateReadingSql = "UPDATE meter_readings 
                                           SET coefficient = ?, 
                                               consumption = ? 
                                           WHERE id = ?";
                        $updateStmt = $db->prepare($updateReadingSql);
                        $updateStmt->execute([$newCoefficient, $consumption, $reading['id']]);
                    }
                    error_log("Updated current day shift 3, count: " . count($currentDayReadings));
                }
                
                // Обновляем будущие записи для этого счетчика (если они уже созданы)
                $getFutureReadingsSql = "SELECT id, reading_start, reading_end FROM meter_readings
                                       WHERE meter_id = ? 
                                         AND date > ?";
                $futureStmt = $db->prepare($getFutureReadingsSql);
                $futureStmt->execute([$data['meter_id'], $data['date']]);
                $futureReadings = $futureStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($futureReadings as $reading) {
                    $consumption = ($reading['reading_end'] - $reading['reading_start']) * $newCoefficient / 1000;
                    $updateReadingSql = "UPDATE meter_readings 
                                       SET coefficient = ?, 
                                           consumption = ? 
                                       WHERE id = ?";
                    $updateStmt = $db->prepare($updateReadingSql);
                    $updateStmt->execute([$newCoefficient, $consumption, $reading['id']]);
                }
                error_log("Updated future readings, count: " . count($futureReadings));
                
                // Если замена счетчика происходит в смене 3, то обновляем показания следующего дня смены 1
                if ($shiftId == 3) {
                    $nextDate = date('Y-m-d', strtotime($data['date'] . ' +1 day'));
                    
                    // Проверяем, существуют ли уже показания для следующего дня смены 1
                    $checkNextDaySql = "SELECT id, reading_start, reading_end FROM meter_readings 
                                       WHERE meter_id = ? 
                                         AND date = ? 
                                         AND shift_id = 1";
                    $stmt = $db->prepare($checkNextDaySql);
                    $stmt->execute([$data['meter_id'], $nextDate]);
                    
                    if ($nextDayReading = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $consumption = ($nextDayReading['reading_end'] - $nextDayReading['reading_start']) * $newCoefficient / 1000;
                        $updateNextDaySql = "UPDATE meter_readings
                                           SET coefficient = ?,
                                               consumption = ?
                                           WHERE id = ?";
                        $updateStmt = $db->prepare($updateNextDaySql);
                        $updateStmt->execute([$newCoefficient, $consumption, $nextDayReading['id']]);
                        error_log("Updated next day shift 1, ID: " . $nextDayReading['id']);
                    }
                }
                
                // Принудительно обновляем consumption для текущей записи еще раз
                // для гарантии, что значение будет установлено правильно
                $finalUpdateSql = "UPDATE meter_readings 
                                  SET consumption = ? 
                                  WHERE id = ?";
                $stmt = $db->prepare($finalUpdateSql);
                $stmt->execute([$correctedConsumption, $readingsData['id']]);
                error_log("Final update for current reading, rows affected: " . $stmt->rowCount());
                
                // Проверяем, что значение consumption было обновлено правильно
                $checkSql = "SELECT consumption FROM meter_readings WHERE id = ?";
                $stmt = $db->prepare($checkSql);
                $stmt->execute([$readingsData['id']]);
                
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $currentConsumption = $row['consumption'];
                    error_log("Current consumption after update: $currentConsumption (expected: $correctedConsumption)");
                    
                    // Если значение все еще не соответствует ожидаемому, используем прямой SQL-запрос
                    if (abs($currentConsumption - $correctedConsumption) > 0.001) {
                        error_log("Consumption value is still incorrect, using direct SQL update");
                        $directUpdateSql = "UPDATE meter_readings 
                                          SET consumption = ? 
                                          WHERE id = ?";
                        $directStmt = $db->prepare($directUpdateSql);
                        $directStmt->execute([$correctedConsumption, $readingsData['id']]);
                        error_log("Direct update executed, rows affected: " . $directStmt->rowCount());
                    }
                }
                
                // Обновляем запись в таблице meters, чтобы сохранить обновленное consumption
                try {
                    // Получаем текущие показания для всех смен этого счетчика на эту дату
                    $getAllShiftsSql = "SELECT shift_id, consumption FROM meter_readings 
                                      WHERE meter_id = ? AND date = ? 
                                      ORDER BY shift_id";
                    $allShiftsStmt = $db->prepare($getAllShiftsSql);
                    $allShiftsStmt->execute([$data['meter_id'], $data['date']]);
                    $allShifts = $allShiftsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Обновляем информацию о consumption в таблице meters
                    // Это поможет клиентской стороне получить правильные данные
                    foreach ($allShifts as $shiftData) {
                        $shiftField = "consumption_shift" . $shiftData['shift_id'];
                        $updateMeterConsumptionSql = "UPDATE meters 
                                                   SET $shiftField = ? 
                                                   WHERE id = ?";
                        $updateConsStmt = $db->prepare($updateMeterConsumptionSql);
                        $updateConsStmt->execute([$shiftData['consumption'], $data['meter_id']]);
                        error_log("Updated meter $shiftField to {$shiftData['consumption']}");
                    }
                } catch (PDOException $e) {
                    error_log("Warning: Could not update meter consumption fields: " . $e->getMessage());
                    // Не выбрасываем исключение, так как это не критическая операция
                }
            } catch (PDOException $e) {
                throw new Exception('SQL ошибка при обновлении показаний: ' . $e->getMessage());
            }
            
            // Commit the transaction
            $db->commit();
            
            sendSuccess([
                'message' => 'Замена счётчика успешно сохранена',
                'replacement_id' => $replacementId
            ]);
        } catch (Exception $e) {
            // Rollback the transaction on error
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при сохранении замены счётчика: ' . $e->getMessage());
    }
}

/**
 * Delete meter replacement
 * 
 * @param int $replacementId Replacement ID to delete
 */
function deleteMeterReplacement($replacementId) {
    // Check authentication
    requireAuth();

    try {
        // Validate input
        if (!$replacementId || !is_numeric($replacementId)) {
            sendError('Некорректный ID замены счетчика', 400);
        }

        // Start a transaction
        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // First, get the replacement details to know which meter was replaced
            $replacementSql = "SELECT meter_id, DATE(replacement_dt) as date FROM meter_replacements WHERE id = ?";
            $replacement = fetchOne($replacementSql, [$replacementId]);
            
            if (!$replacement) {
                throw new Exception('Замена счетчика не найдена (ID: ' . $replacementId . ')');
            }
            
            // Delete the replacement
            $deleteSql = "DELETE FROM meter_replacements WHERE id = ?";
            executeQuery($deleteSql, [$replacementId]);
            
            // Get the meter's original coefficient (before the replacement)
            $meterSql = "SELECT coefficient FROM meters WHERE id = ?";
            $meter = fetchOne($meterSql, [$replacement['meter_id']]);
            
            if (!$meter) {
                throw new Exception('Счетчик не найден (ID: ' . $replacement['meter_id'] . ')');
            }
            
            // Update readings to recalculate consumption with the current coefficient
            $readingsSql = "SELECT id, reading_start, reading_end FROM meter_readings 
                           WHERE meter_id = ? AND date >= ?";
            $readings = fetchAll($readingsSql, [$replacement['meter_id'], $replacement['date']]);
            
            foreach ($readings as $reading) {
                $consumption = ($reading['reading_end'] - $reading['reading_start']) * $meter['coefficient'] / 1000;
                $updateSql = "UPDATE meter_readings 
                             SET consumption = ? 
                             WHERE id = ?";
                executeQuery($updateSql, [$consumption, $reading['id']]);
            }
            
            // Commit the transaction
            $db->commit();
            
            sendSuccess([
                'message' => 'Замена счетчика успешно удалена',
                'meter_id' => $replacement['meter_id']
            ]);
        } catch (Exception $e) {
            // Rollback the transaction on error
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при удалении замены счетчика: ' . $e->getMessage());
    }
}

/**
 * Update meter replacement
 * 
 * @param int $replacementId Replacement ID to update
 */
function updateMeterReplacement($replacementId) {
    // Check authentication
    requireAuth();

    try {
        // Get JSON data from request
        $rawData = file_get_contents('php://input');
        $data = json_decode($rawData, true);
        
        // Debug the received data
        error_log("Received meter replacement update data: " . $rawData);
        
        // Check required fields
        $requiredFields = ['meter_id', 'date', 'replacement_time', 'old_reading', 
                          'new_coefficient', 'new_scale', 'new_reading'];
        $missingFields = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            sendError('Некорректные данные запроса. Отсутствуют поля: ' . implode(', ', $missingFields), 400);
            return;
        }
        
        // Validate input
        if (!$replacementId || !is_numeric($replacementId)) {
            sendError('Некорректный ID замены счетчика', 400);
        }
        
        // Start a transaction
        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // First, check if the replacement exists
            $checkSql = "SELECT id FROM meter_replacements WHERE id = ?";
            $replacement = fetchOne($checkSql, [$replacementId]);
            
            if (!$replacement) {
                throw new Exception('Замена счетчика не найдена (ID: ' . $replacementId . ')');
            }
            
            // Format the replacement datetime
            $replacementDt = $data['date'] . ' ' . $data['replacement_time'] . ':00';
            
            // Update the replacement record
            $updateSql = "UPDATE meter_replacements 
                         SET replacement_dt = ?,
                             old_reading = ?,
                             old_coefficient = ?,
                             old_scale = ?,
                             new_coefficient = ?,
                             new_scale = ?,
                             new_reading = ?,
                             downtime_minutes = ?,
                             power_at_replacement = ?
                         WHERE id = ?";
            
            executeQuery($updateSql, [
                $replacementDt,
                $data['old_reading'],
                $data['old_coefficient'] ?? 1.0, // Default to 1.0 if missing
                $data['old_scale'] ?? '99999.999',
                $data['new_coefficient'],
                $data['new_scale'] ?? '99999.999',
                $data['new_reading'],
                $data['downtime_minutes'] ?? 0,
                $data['power_at_replacement'] ?? 0,
                $replacementId
            ]);
            
            // Update the meter with new coefficient and scale
            $updateMeterSql = "UPDATE meters 
                              SET serial = ?,
                                  coefficient = ?, 
                                  scale = ? 
                              WHERE id = ?";
            
            executeQuery($updateMeterSql, [
                $data['new_serial'],
                $data['new_coefficient'],
                $data['new_scale'],
                $data['meter_id']
            ]);
            
            // Determine shift based on replacement time
            $timeHour = (int)substr($data['replacement_time'], 0, 2);
            $shiftId = 1; // Default to shift 1
            
            if ($timeHour >= 0 && $timeHour < 8) {
                $shiftId = 1;
            } else if ($timeHour >= 8 && $timeHour < 16) {
                $shiftId = 2;
            } else {
                $shiftId = 3;
            }
            
            // Get the reading for this shift to update consumption
            $readingsSql = "SELECT id, reading_start, reading_end FROM meter_readings 
                           WHERE meter_id = ? AND date = ? AND shift_id = ?";
            $readingsData = fetchOne($readingsSql, [
                $data['meter_id'],
                $data['date'],
                $shiftId
            ]);
            
            if ($readingsData) {
                // Calculate corrected consumption
                $oldReading = $data['old_reading'];
                $newReading = $data['new_reading'];
                $newCoefficient = $data['new_coefficient'];
                $downtimeMinutes = $data['downtime_minutes'] ?? 0;
                $powerAtReplacement = $data['power_at_replacement'] ?? 0;
                
                // Calculate parts according to the formula
                $part1 = ($oldReading - $readingsData['reading_start']) * ($data['old_coefficient'] ?? 1.0) / 1000;
                $part2 = ($downtimeMinutes / 60) * $powerAtReplacement;
                $part3 = ($readingsData['reading_end'] - $newReading) * $newCoefficient / 1000;
                $correctedConsumption = $part1 + $part2 + $part3;
                
                // Update the reading with corrected consumption
                $updateReadingSql = "UPDATE meter_readings 
                                    SET coefficient = ?, 
                                        consumption = ? 
                                    WHERE id = ?";
                executeQuery($updateReadingSql, [$newCoefficient, $correctedConsumption, $readingsData['id']]);
                
                // Update other readings for this meter with the new coefficient
                $otherReadingsSql = "SELECT id, reading_start, reading_end FROM meter_readings 
                                    WHERE meter_id = ? AND id != ?";
                $otherReadings = fetchAll($otherReadingsSql, [$data['meter_id'], $readingsData['id']]);
                
                foreach ($otherReadings as $reading) {
                    $consumption = ($reading['reading_end'] - $reading['reading_start']) * $newCoefficient / 1000;
                    $updateSql = "UPDATE meter_readings 
                                 SET coefficient = ?, 
                                     consumption = ? 
                                 WHERE id = ?";
                    executeQuery($updateSql, [$newCoefficient, $consumption, $reading['id']]);
                }
            }
            
            // Commit the transaction
            $db->commit();
            
            sendSuccess([
                'message' => 'Замена счетчика успешно обновлена',
                'replacement_id' => $replacementId
            ]);
        } catch (Exception $e) {
            // Rollback the transaction on error
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при обновлении замены счетчика: ' . $e->getMessage());
    }
} 