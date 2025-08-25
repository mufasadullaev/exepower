<?php

require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/response.php';

/**
 * Определяет pgu_id на основе equipment_id
 * @param int $equipmentId ID оборудования
 * @return int|null pgu_id или null, если оборудование не относится к ПГУ
 */
function getPguIdFromEquipment($equipmentId) {
    // Маппинг equipment_id -> pgu_id (обновлено для новых счетчиков)
    $equipmentToPguMap = [
        1 => null,  // Блок ТГ7 - не ПГУ
        2 => null,  // Блок ТГ8 - не ПГУ
        3 => 1,     // ГТ 1 -> ПГУ 1
        4 => 1,     // ПТ 1 -> ПГУ 1
        5 => 2,     // ГТ 2 -> ПГУ 2
        6 => 2      // ПТ 2 -> ПГУ 2
    ];
    
    return $equipmentToPguMap[$equipmentId] ?? null;
}

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
            LEFT JOIN equipment e ON m.equipment_id = e.id
            WHERE m.meter_type_id = ? AND m.is_active = 1
            ORDER BY m.id
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
        
        // Добавляем эффективные сменные значения для основных счётчиков (ВСР-*) с учётом назначений резерва
        try {
            // Получаем назначения резервов, пересекающие день
            $startOfDay = $date . ' 00:00:00';
            $endOfDay = $date . ' 23:59:59';
            $assignments = fetchAll(
                "SELECT * FROM meter_reserve_assignments WHERE (start_time <= ?) AND (end_time IS NULL OR end_time >= ?)",
                [$endOfDay, $startOfDay]
            );

            if (!empty($assignments)) {
                // Подтягиваем параметры счетчиков (коэффициент и шкалу) для всех задействованных резервов
                $reserveIds = array_values(array_unique(array_map(fn($a) => (int)$a['reserve_meter_id'], $assignments)));
                $metersInfo = [];
                if (!empty($reserveIds)) {
                    $placeholders = implode(',', array_fill(0, count($reserveIds), '?'));
                    $rows = fetchAll("SELECT id, coefficient_k, scale FROM meters WHERE id IN ($placeholders)", $reserveIds);
                    foreach ($rows as $m) {
                        $metersInfo[(int)$m['id']] = [
                            'k' => isset($m['coefficient_k']) ? (float)$m['coefficient_k'] : 1.0,
                            'scale' => isset($m['scale']) ? (float)$m['scale'] : 0.0,
                        ];
                    }
                }

                // Индексы смен
                $shifts = [
                    ['start' => '00:00:00', 'end' => '08:00:00', 'key' => 'shift1'],
                    ['start' => '08:00:00', 'end' => '16:00:00', 'key' => 'shift2'],
                    ['start' => '16:00:00', 'end' => '23:59:59', 'key' => 'shift3'],
                ];

                foreach ($assignments as $asg) {
                    if (!$asg['end_time'] || $asg['end_reading'] === null) {
                        // Открытые назначения не учитываем до закрытия
                        continue;
                    }
                    $primaryId = (int)$asg['primary_meter_id'];
                    $reserveId = (int)$asg['reserve_meter_id'];
                    $startTime = new DateTime(max($asg['start_time'], $startOfDay));
                    $endTime = new DateTime(min($asg['end_time'], $endOfDay));
                    if ($endTime <= $startTime) continue;

                    $startReading = (float)$asg['start_reading'];
                    $endReading = (float)$asg['end_reading'];
                    $k = $metersInfo[$reserveId]['k'] ?? 1.0;
                    $scale = $metersInfo[$reserveId]['scale'] ?? 0.0;

                    // Учёт переполнения шкалы
                    if ($scale > 0 && $endReading < $startReading) {
                        $rawDelta = ($scale - $startReading) + $endReading;
                    } else {
                        $rawDelta = $endReading - $startReading;
                    }
                    // Переводим в энергию (как и для обычных сменных значений)
                    $totalEnergy = ($rawDelta * $k) / 1000.0;

                    $intervalSeconds = max(1, $endTime->getTimestamp() - $startTime->getTimestamp());
                    $ratePerSecond = $totalEnergy / $intervalSeconds;

                    // Добавляем по сменам
                    foreach ($shifts as $s) {
                        $sStart = new DateTime($date . ' ' . $s['start']);
                        $sEnd = new DateTime($date . ' ' . $s['end']);
                        $overlapStart = max($startTime, $sStart);
                        $overlapEnd = min($endTime, $sEnd);
                        if ($overlapEnd > $overlapStart) {
                            $overlapSeconds = $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
                            $contribEnergy = $ratePerSecond * $overlapSeconds;
                            if (!isset($formattedReadings[$primaryId])) {
                                // если для основного нет записей на эту дату, создадим базовую
                                $formattedReadings[$primaryId] = [
                                    'meter_id' => $primaryId,
                                    'date' => $date,
                                    'shift1' => null, 'shift2' => null, 'shift3' => null, 'total' => null
                                ];
                            }
                            $effectiveKey = 'effective_' . $s['key'];
                            
                            // Инициализируем effective_shift только резервной добавкой (не копируем основное значение)
                            if (!isset($formattedReadings[$primaryId][$effectiveKey])) {
                                $formattedReadings[$primaryId][$effectiveKey] = 0.0;
                            }
                            $formattedReadings[$primaryId][$effectiveKey] += (float)$contribEnergy;
                            
                            // Добавляем резервную энергию к основной смене
                            $baseKey = $s['key'];
                            if (isset($formattedReadings[$primaryId][$baseKey])) {
                                $formattedReadings[$primaryId][$baseKey] += (float)$contribEnergy;
                            } else {
                                // Если основного значения нет, добавляем только резерв
                                $formattedReadings[$primaryId][$baseKey] = (float)$contribEnergy;
                            }
                        }
                    }
                }

                // Пересчёт total
                foreach ($formattedReadings as $mid => &$row) {
                    $hasEffective = isset($row['effective_shift1']) || isset($row['effective_shift2']) || isset($row['effective_shift3']);
                    if ($hasEffective) {
                        // effective_shift показывает только резервные добавки, округляем их
                        $row['effective_shift1'] = isset($row['effective_shift1']) ? round($row['effective_shift1'], 3) : 0;
                        $row['effective_shift2'] = isset($row['effective_shift2']) ? round($row['effective_shift2'], 3) : 0;
                        $row['effective_shift3'] = isset($row['effective_shift3']) ? round($row['effective_shift3'], 3) : 0;
                        
                        // effective_total - сумма всех резервных добавок
                        $row['effective_total'] = round(
                            $row['effective_shift1'] + $row['effective_shift2'] + $row['effective_shift3'], 
                            3
                        );
                        
                        // Пересчитываем total для основных смен (уже включают резерв)
                        $row['total'] = round(
                            ($row['shift1'] ?? 0) + ($row['shift2'] ?? 0) + ($row['shift3'] ?? 0), 
                            3
                        );
                    }
                }
                unset($row);
            }
        } catch (Exception $calcEx) {
            // Не роняем выдачу при ошибке расчёта
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
        
        // Логирование для диагностики
        error_log("saveReadings input: " . print_r($input, true));
        
        if (!isset($input['date']) || !isset($input['readings'])) {
            sendError('Неверный формат данных: отсутствует date или readings');
            return;
        }

        $date = $input['date'];
        $readings = $input['readings'];
        
        // Проверяем что readings это массив
        if (!is_array($readings)) {
            sendError('Неверный формат данных: readings должен быть массивом');
            return;
        }
        
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
                
                // Обеспечиваем что r0 не NULL (обязательное поле в БД)
                if (!isset($reading['r0']) || $reading['r0'] === null) {
                    $reading['r0'] = 0;
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

                // Сначала рассчитываем основные значения смен
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
                        if ($replacementTime >= $shift16Time) { // замена произошла во время третьей смены
                            // Расчет по старому счетчику до замены
                            $beforeReplacement = ($replacement['old_reading'] - $reading['r16']) * $replacement['old_coefficient'] / 1000;
                            // Расчет по новому счетчику после замены
                            $afterReplacement = ($reading['r24'] - $replacement['new_reading']) * $replacement['new_coefficient'] / 1000;
                            // Расчет простоя
                            $downtimePower = ($replacement['downtime_min'] / 60) * $replacement['power_mw'];
                            $shift3 = $beforeReplacement + $downtimePower + $afterReplacement;
                        } else { // замена произошла до третьей смены
                            $shift3 = ($reading['r24'] - $reading['r16']) * $replacement['new_coefficient'] / 1000;
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

                // Резервные добавки пока оставляем как есть - они будут рассчитаны в getReadings

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
            
            // Синхронизируем данные с pgu_fullparam_values
            foreach ($readings as $meterId => $reading) {
                try {
                    syncMeterReadingsToPguFullParams($meterId, $date, $user['id']);
                } catch (Exception $syncError) {
                    error_log("Ошибка синхронизации для счетчика $meterId: " . $syncError->getMessage());
                    // Не прерываем основную операцию из-за ошибки синхронизации
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

/**
 * Синхронизация данных счетчиков с pgu_fullparam_values
 * Копирует значения shift1, shift2, shift3 из meter_readings в pgu_fullparam_values
 * для параметров ГТ (row_num = 10), ПТ (row_num = 11) и рассчитывает отпуск (row_num = 13)
 */
function syncMeterReadingsToPguFullParams($meterId, $date, $userId) {
    try {
        $db = getDbConnection();
        
        // Получаем информацию о счетчике и оборудовании
        $stmt = $db->prepare('
            SELECT m.*, e.id as equipment_id, e.name as equipment_name, e.type_id
            FROM meters m
            JOIN equipment e ON m.equipment_id = e.id
            WHERE m.id = ?
        ');
        $stmt->execute([$meterId]);
        $meter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$meter) {
            throw new Exception("Счетчик не найден");
        }
        
        // Проверяем, что это счетчик выработки электроэнергии (тип 1)
        if ($meter['meter_type_id'] != 1) {
            error_log("Пропуск синхронизации: счетчик {$meter['id']} не является счетчиком выработки (тип: {$meter['meter_type_id']})");
            return; // Синхронизируем только счетчики выработки
        }
        
        // Получаем показания счетчика на указанную дату
        $stmt = $db->prepare('
            SELECT shift1, shift2, shift3
            FROM meter_readings
            WHERE meter_id = ? AND date = ?
        ');
        $stmt->execute([$meterId, $date]);
        $readings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$readings) {
            return; // Нет показаний для синхронизации
        }
        
        // Определяем pgu_id на основе оборудования
        $pguId = getPguIdFromEquipment($meter['equipment_id']);
        
        if ($pguId === null) {
            error_log("Пропуск синхронизации: оборудование {$meter['equipment_id']} не относится к ПГУ");
            return; // Не синхронизируем для оборудования, которое не относится к ПГУ
        }
        
        // Определяем тип оборудования (ГТ или ПТ) и соответствующий row_num
        $isGT = strpos($meter['equipment_name'], 'ГТ') !== false;
        $rowNum = $isGT ? 10 : 11; // ГТ -> row_num = 10, ПТ -> row_num = 11
        
        // Определяем букву колонки на основе ПГУ
        $columnLetter = $pguId == 1 ? 'F' : 'G'; // ПГУ 1 -> F, ПГУ 2 -> G
        
        // Получаем ID параметра
        $stmt = $db->prepare('SELECT id FROM pgu_fullparams WHERE row_num = ?');
        $stmt->execute([$rowNum]);
        $fullparam = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fullparam) {
            throw new Exception("Параметр с row_num = $rowNum не найден");
        }
        
        $fullparamId = $fullparam['id'];
        
        error_log("Синхронизация: оборудование {$meter['equipment_name']} (ID: {$meter['equipment_id']}) -> ПГУ $pguId, row_num=$rowNum, колонка=$columnLetter");
        
        // Синхронизируем данные по сменам
        $shifts = [
            1 => $readings['shift1'],
            2 => $readings['shift2'], 
            3 => $readings['shift3']
        ];
        
        foreach ($shifts as $shiftId => $value) {
            if ($value === null) {
                continue; // Пропускаем пустые значения
            }
            
            // Проверяем существование записи
            $stmt = $db->prepare('
                SELECT id FROM pgu_fullparam_values 
                WHERE fullparam_id = ? AND pgu_id = ? AND date = ? AND shift_id = ?
            ');
            $stmt->execute([$fullparamId, $pguId, $date, $shiftId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Обновляем существующую запись
                $stmt = $db->prepare('
                    UPDATE pgu_fullparam_values 
                    SET value = ?
                    WHERE id = ?
                ');
                $stmt->execute([$value, $existing['id']]);
            } else {
                // Создаем новую запись
                $stmt = $db->prepare('
                    INSERT INTO pgu_fullparam_values 
                    (fullparam_id, pgu_id, date, shift_id, value, cell)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $cell = $columnLetter . $rowNum; // F10 для ПГУ1/ГТ, F11 для ПГУ1/ПТ, G10 для ПГУ2/ГТ, G11 для ПГУ2/ПТ
                $stmt->execute([$fullparamId, $pguId, $date, $shiftId, $value, $cell]);
            }
        }
        
        error_log("Синхронизация meter_readings -> pgu_fullparam_values: meter_id=$meterId, date=$date, equipment={$meter['equipment_name']}, pgu_id=$pguId, row_num=$rowNum");
        
        // После синхронизации выработки рассчитываем отпуск электроэнергии (row_num = 13)
        calculateDeliveryValues($pguId, $date);
        
    } catch (Exception $e) {
        error_log("Ошибка синхронизации meter_readings -> pgu_fullparam_values: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Удаление данных из pgu_fullparam_values при удалении показаний счетчика
 */
function removeMeterReadingsFromPguFullParams($meterId, $date) {
    try {
        $db = getDbConnection();
        
        // Получаем информацию о счетчике и оборудовании
        $stmt = $db->prepare('
            SELECT m.equipment_id, m.meter_type_id, e.name as equipment_name
            FROM meters m
            JOIN equipment e ON m.equipment_id = e.id
            WHERE m.id = ?
        ');
        $stmt->execute([$meterId]);
        $meter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$meter || $meter['meter_type_id'] != 1) {
            return; // Только для счетчиков выработки
        }
        
        // Определяем pgu_id на основе оборудования
        $pguId = getPguIdFromEquipment($meter['equipment_id']);
        
        if ($pguId === null) {
            return; // Не удаляем для оборудования, которое не относится к ПГУ
        }
        
        // Определяем тип оборудования (ГТ или ПТ) и соответствующий row_num
        $isGT = strpos($meter['equipment_name'], 'ГТ') !== false;
        $rowNum = $isGT ? 10 : 11; // ГТ -> row_num = 10, ПТ -> row_num = 11
        
        // Получаем ID параметра
        $stmt = $db->prepare('SELECT id FROM pgu_fullparams WHERE row_num = ?');
        $stmt->execute([$rowNum]);
        $fullparam = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fullparam) {
            return;
        }
        
        // Удаляем записи для всех смен
        $stmt = $db->prepare('
            DELETE FROM pgu_fullparam_values 
            WHERE fullparam_id = ? AND pgu_id = ? AND date = ?
        ');
        $stmt->execute([$fullparam['id'], $pguId, $date]);
        
        error_log("Удаление данных из pgu_fullparam_values: meter_id=$meterId, date=$date, equipment={$meter['equipment_name']}, pgu_id=$pguId, row_num=$rowNum");
        
    } catch (Exception $e) {
        error_log("Ошибка удаления данных из pgu_fullparam_values: " . $e->getMessage());
    }
}

/**
 * Расчет отпуска электроэнергии (row_num = 13) на основе выработки и расходов
 */
function calculateDeliveryValues($pguId, $date) {
    try {
        $db = getDbConnection();
        
        // Определяем букву колонки на основе ПГУ
        $columnLetter = $pguId == 1 ? 'F' : 'G';
        
        // Маппинг оборудования ПГУ к счетчикам
        $pguMeterMapping = [
            1 => [3, 4], // ПГУ 1: ГТ 1 (id=3), ПТ 1 (id=4)
            2 => [5, 6]  // ПГУ 2: ГТ 2 (id=5), ПТ 2 (id=6)
        ];
        
        // Маппинг ПГУ к счетчикам расхода на собственные нужды  
        $pguOwnNeedsMapping = [
            1 => 3, // ПГУ 1: используется equipment_id=3 (ГТ1) для поиска ТСН-1
            2 => 5  // ПГУ 2: используется equipment_id=5 (ГТ2) для поиска ТСН-2
        ];
        
        $equipmentIds = $pguMeterMapping[$pguId] ?? [];
        if (empty($equipmentIds)) {
            return;
        }
        
        // Получаем все счетчики выработки для данного ПГУ
        $generationMeters = fetchAll(
            "SELECT m.id, m.equipment_id, m.coefficient_k, mr.shift1, mr.shift2, mr.shift3
             FROM meters m
             LEFT JOIN meter_readings mr ON m.id = mr.meter_id AND mr.date = ?
             WHERE m.equipment_id IN (" . implode(',', $equipmentIds) . ") 
             AND m.meter_type_id = 1 AND m.is_active = 1",
            [$date]
        );
        
        // Получаем счетчик расхода на собственные нужды для данного ПГУ (ТСН-1 или ТСН-2)
        $ownNeedsEquipmentId = $pguOwnNeedsMapping[$pguId];
        $ownNeedsMeters = fetchAll(
            "SELECT m.id, m.equipment_id, m.coefficient_k, mr.shift1, mr.shift2, mr.shift3
             FROM meters m
             LEFT JOIN meter_readings mr ON m.id = mr.meter_id AND mr.date = ?
             WHERE m.equipment_id = ? AND m.meter_type_id = 2 AND m.is_active = 1",
            [$date, $ownNeedsEquipmentId]
        );
        
        $householdNeedsMeters = fetchAll(
            "SELECT m.id, m.equipment_id, m.coefficient_k, mr.shift1, mr.shift2, mr.shift3
             FROM meters m
             LEFT JOIN meter_readings mr ON m.id = mr.meter_id AND mr.date = ?
             WHERE m.meter_type_id = 4 AND m.is_active = 1",
            [$date]
        );
        
        // Суммируем значения по сменам
        $generationValues = [1 => 0, 2 => 0, 3 => 0];
        $ownNeedsValues = [1 => 0, 2 => 0, 3 => 0];
        $householdNeedsValues = [1 => 0, 2 => 0, 3 => 0];
        
        foreach ($generationMeters as $meter) {
            $generationValues[1] += ($meter['shift1'] ?? 0);
            $generationValues[2] += ($meter['shift2'] ?? 0);
            $generationValues[3] += ($meter['shift3'] ?? 0);
        }
        
        foreach ($ownNeedsMeters as $meter) {
            $ownNeedsValues[1] += ($meter['shift1'] ?? 0);
            $ownNeedsValues[2] += ($meter['shift2'] ?? 0);
            $ownNeedsValues[3] += ($meter['shift3'] ?? 0);
        }
        
        foreach ($householdNeedsMeters as $meter) {
            $householdNeedsValues[1] += ($meter['shift1'] ?? 0);
            $householdNeedsValues[2] += ($meter['shift2'] ?? 0);
            $householdNeedsValues[3] += ($meter['shift3'] ?? 0);
        }
        
        // Рассчитываем отпуск электроэнергии для каждой смены
        $deliveryValues = [
            1 => $generationValues[1] - $ownNeedsValues[1] - $householdNeedsValues[1],
            2 => $generationValues[2] - $ownNeedsValues[2] - $householdNeedsValues[2],
            3 => $generationValues[3] - $ownNeedsValues[3] - $householdNeedsValues[3]
        ];
        
        // Получаем ID параметра общей выработки (row_num = 12)
        $stmt = $db->prepare('SELECT id FROM pgu_fullparams WHERE row_num = ?');
        $stmt->execute([12]);
        $fullparam12 = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fullparam12) {
            error_log("Параметр с row_num = 12 не найден");
            return;
        }
        
        // Получаем ID параметра отпуска электроэнергии (row_num = 13)
        $stmt = $db->prepare('SELECT id FROM pgu_fullparams WHERE row_num = ?');
        $stmt->execute([13]);
        $fullparam13 = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fullparam13) {
            error_log("Параметр с row_num = 13 не найден");
            return;
        }
        
        $fullparam12Id = $fullparam12['id'];
        $fullparam13Id = $fullparam13['id'];
        
        // Сохраняем общую выработку (F12/G12) и отпуск электроэнергии (F13/G13) для каждой смены
        foreach ($deliveryValues as $shiftId => $deliveryValue) {
            $totalGenerationValue = $generationValues[$shiftId];
            
            // Сохраняем общую выработку (F12/G12)
            $cell12 = $columnLetter . '12'; // F12 или G12
            $stmt = $db->prepare('
                SELECT id FROM pgu_fullparam_values 
                WHERE fullparam_id = ? AND pgu_id = ? AND date = ? AND shift_id = ?
            ');
            $stmt->execute([$fullparam12Id, $pguId, $date, $shiftId]);
            $existing12 = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing12) {
                // Обновляем существующую запись
                $stmt = $db->prepare('
                    UPDATE pgu_fullparam_values 
                    SET value = ?, cell = ?
                    WHERE id = ?
                ');
                $stmt->execute([$totalGenerationValue, $cell12, $existing12['id']]);
            } else {
                // Создаем новую запись
                $stmt = $db->prepare('
                    INSERT INTO pgu_fullparam_values 
                    (fullparam_id, pgu_id, date, shift_id, value, cell)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$fullparam12Id, $pguId, $date, $shiftId, $totalGenerationValue, $cell12]);
            }
            
            // Сохраняем отпуск электроэнергии (F13/G13)
            $cell13 = $columnLetter . '13'; // F13 или G13
            $stmt = $db->prepare('
                SELECT id FROM pgu_fullparam_values 
                WHERE fullparam_id = ? AND pgu_id = ? AND date = ? AND shift_id = ?
            ');
            $stmt->execute([$fullparam13Id, $pguId, $date, $shiftId]);
            $existing13 = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing13) {
                // Обновляем существующую запись
                $stmt = $db->prepare('
                    UPDATE pgu_fullparam_values 
                    SET value = ?, cell = ?
                    WHERE id = ?
                ');
                $stmt->execute([$deliveryValue, $cell13, $existing13['id']]);
            } else {
                // Создаем новую запись
                $stmt = $db->prepare('
                    INSERT INTO pgu_fullparam_values 
                    (fullparam_id, pgu_id, date, shift_id, value, cell)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$fullparam13Id, $pguId, $date, $shiftId, $deliveryValue, $cell13]);
            }
            
            error_log("Energy calculation: PGU $pguId, Shift $shiftId, Total Generation: $totalGenerationValue, Own needs: {$ownNeedsValues[$shiftId]}, Household needs: {$householdNeedsValues[$shiftId]}, Delivery: $deliveryValue");
        }
        
    } catch (Exception $e) {
        error_log("Ошибка расчета отпуска электроэнергии: " . $e->getMessage());
    }
} 

/**
 * Массовая синхронизация существующих данных meter_readings в pgu_fullparam_values
 * Используется для первоначальной настройки или восстановления данных
 */
function bulkSyncMeterReadingsToPguFullParams() {
    try {
        $user = requireAuth();
        
        // Проверяем права доступа (только для менеджеров)
        if ($user['role'] !== 'менеджер') {
            sendError('Недостаточно прав для массовой синхронизации', 403);
            return;
        }
        
        $db = getDbConnection();
        
        // Получаем все показания счетчиков выработки
        $stmt = $db->prepare('
            SELECT mr.meter_id, mr.date, mr.shift1, mr.shift2, mr.shift3,
                   m.equipment_id, m.meter_type_id
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE m.meter_type_id = 1
            ORDER BY mr.date DESC, mr.meter_id
        ');
        $stmt->execute();
        $allReadings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $syncedCount = 0;
        $errorCount = 0;
        
        foreach ($allReadings as $reading) {
            try {
                syncMeterReadingsToPguFullParams($reading['meter_id'], $reading['date'], $user['id']);
                $syncedCount++;
            } catch (Exception $e) {
                error_log("Ошибка синхронизации: meter_id={$reading['meter_id']}, date={$reading['date']}: " . $e->getMessage());
                $errorCount++;
            }
        }
        
        sendSuccess([
            'message' => 'Массовая синхронизация завершена',
            'synced_count' => $syncedCount,
            'error_count' => $errorCount
        ]);
        
    } catch (Exception $e) {
        sendError('Ошибка при массовой синхронизации: ' . $e->getMessage());
    }
} 