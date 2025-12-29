<?php
/**
 * Blocks (TG) Results Controller
 * Handles API endpoints related to Blocks calculation results
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Get all Blocks result parameters (tg_result_params)
 */
function getBlocksResultParams() {
    requireAuth();

    try {
        $sql = "SELECT id, name, unit, symbol, category FROM tg_result_params ORDER BY id";
        $params = fetchAll($sql);
        sendSuccess(['params' => $params]);
    } catch (Exception $e) {
        sendError('Ошибка при получении параметров результатов Блоков: ' . $e->getMessage());
    }
}

/**
 * Map shift names (shift1/shift2/shift3) to numeric IDs
 */
function mapShiftNamesToIdsBlocks($shifts) {
    $map = [ 'shift1' => 1, 'shift2' => 2, 'shift3' => 3 ];
    $ids = [];
    foreach ($shifts as $s) {
        if (isset($map[$s])) $ids[] = $map[$s];
        else if (is_numeric($s)) $ids[] = (int)$s;
    }
    return array_values(array_unique($ids));
}

/**
 * Get Blocks result values from tg_result_values
 */
function getBlocksResultValues($data) {
    requireAuth();

    try {
        if (!isset($data['date']) || !isset($data['periodType'])) {
            sendError('Необходимо указать дату и тип периода', 400);
        }

        $date = $data['date'];
        $periodType = $data['periodType'];
        // По умолчанию три блока: 7 (ТГ7), 8 (ТГ8), 9 (ОЧ-130)
        $blockIds = isset($data['blockIds']) && is_array($data['blockIds']) ? array_map('intval', $data['blockIds']) : [7,8,9];
        $shiftIds = [];
        if ($periodType === 'shift') {
            if (isset($data['shiftIds']) && is_array($data['shiftIds'])) {
                $shiftIds = array_map('intval', $data['shiftIds']);
            } elseif (isset($data['shifts']) && is_array($data['shifts'])) {
                $shiftIds = mapShiftNamesToIdsBlocks($data['shifts']);
            }
            if (empty($shiftIds)) {
                $shiftIds = [1,2,3];
            }
        }

        // Получаем список параметров
        $paramsSql = "SELECT id, name, unit, symbol, category FROM tg_result_params ORDER BY id";
        $params = fetchAll($paramsSql);

        // Загружаем значения
        $resultValues = [];
        if (!empty($blockIds)) {
            $idsStr = implode(',', array_map('intval', $blockIds));
            $sql = "SELECT rv.param_id, rv.tg_id, rv.value, rv.shift_id, rv.period_type
                        FROM tg_result_values rv
                   WHERE rv.date = ? AND rv.period_type = ? AND rv.tg_id IN ($idsStr)";
            $bind = [$date, $periodType];
            if ($periodType === 'shift' && !empty($shiftIds)) {
                $in = implode(',', array_fill(0, count($shiftIds), '?'));
                $sql .= " AND rv.shift_id IN ($in)";
                $bind = array_merge($bind, $shiftIds);
            }
            $values = fetchAll($sql, $bind);

            foreach ($values as $row) {
                $paramId = (int)$row['param_id'];
                $blockId = (int)$row['tg_id'];
                $shiftId = $row['shift_id'] !== null ? (int)$row['shift_id'] : null;
                $value = $row['value'];
                if ($periodType === 'shift') {
                    if (!isset($resultValues[$paramId])) $resultValues[$paramId] = [];
                    if (!isset($resultValues[$paramId][$shiftId])) $resultValues[$paramId][$shiftId] = [];
                    $resultValues[$paramId][$shiftId][$blockId] = $value;
                } else {
                    if (!isset($resultValues[$paramId])) $resultValues[$paramId] = [];
                    $resultValues[$paramId][$blockId] = $value;
                }
            }
        }

        // Формируем ответ
        $responseData = [];
        foreach ($params as $param) {
            $paramId = (int)$param['id'];
            $paramData = [
                'id' => $paramId,
                'name' => $param['name'],
                'unit' => $param['unit'],
                'symbol' => $param['symbol'],
                'category' => $param['category']
            ];
            if ($periodType === 'shift') {
                $paramData['valuesByShift'] = [];
                foreach ($shiftIds as $sid) {
                    $paramData['valuesByShift'][$sid] = [];
                    foreach ($blockIds as $blockId) {
                        $paramData['valuesByShift'][$sid][$blockId] = $resultValues[$paramId][$sid][$blockId] ?? null;
                    }
                }
            } else {
                $paramData['values'] = [];
                foreach ($blockIds as $blockId) {
                    $paramData['values'][$blockId] = $resultValues[$paramId][$blockId] ?? null;
                }
            }
            $responseData[] = $paramData;
        }

        sendSuccess([
            'params' => $responseData,
            'date' => $date,
            'periodType' => $periodType,
            'blockIds' => $blockIds,
            'shiftIds' => $shiftIds
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при получении результатов Блоков: ' . $e->getMessage());
    }
}

/**
 * Save Blocks result values into tg_result_values
 */
function saveBlocksResultValues($data) {
    requireAuth();

    try {
        if (!isset($data['date']) || !isset($data['periodType']) || !isset($data['values'])) {
            sendError('Необходимо указать дату, тип периода и значения', 400);
        }

        $date = $data['date'];
        $periodType = $data['periodType'];
        $values = $data['values'];
        $userId = $_SESSION['user_id'] ?? 1;

        if ($periodType === 'shift') {
            $shiftIds = array_filter(array_unique(array_column($values, 'shift_id')));
            error_log("Shift IDs for deletion: " . json_encode($shiftIds));
            if (!empty($shiftIds)) {
                $placeholders = str_repeat('?,', count($shiftIds) - 1) . '?';
                $deleteSql = "DELETE FROM tg_result_values WHERE date = ? AND period_type = ? AND shift_id IN ($placeholders)";
                $deleteParams = array_merge([$date, $periodType], $shiftIds);
                error_log("Delete SQL: $deleteSql, Params: " . json_encode($deleteParams));
                executeQuery($deleteSql, $deleteParams);
            }
        } else {
            $deleteSql = "DELETE FROM tg_result_values WHERE date = ? AND period_type = ?";
            executeQuery($deleteSql, [$date, $periodType]);
        }

        foreach ($values as $index => $value) {
            if (isset($value['param_id']) && isset($value['tg_id']) && isset($value['value'])) {
                try {
                    $insertSql = "INSERT INTO tg_result_values 
                                 (param_id, tg_id, date, shift_id, value, user_id, period_type, period_start, period_end, cell) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $shiftId = isset($value['shift_id']) ? $value['shift_id'] : null;
                    
                    // Преобразуем значение в число
                    // Сначала проверяем, является ли значение числом (включая экспоненциальную нотацию)
                    $valueStr = (string)$value['value'];
                    if (!is_numeric($valueStr)) {
                        error_log("WARNING: Non-numeric value for param_id={$value['param_id']}, tg_id={$value['tg_id']}, shift_id={$shiftId}, index={$index}: {$valueStr}");
                        $rawValue = 0;
                    } else {
                        $rawValue = (float)$valueStr;
                    }
                    
                    // decimal(15,6) максимальное значение: 999999999.999999
                    // Но нужно учесть, что 15 цифр всего, из них 6 после запятой = 9 до запятой
                    // Используем немного меньшее значение для безопасности
                    $maxValue = 999999999.999999;
                    $minValue = -999999999.999999;
                    
                    // Проверяем и ограничиваем значение
                    if ($rawValue > $maxValue) {
                        error_log("WARNING: Value exceeds max for param_id={$value['param_id']}, tg_id={$value['tg_id']}, shift_id={$shiftId}, index={$index}: {$rawValue} > {$maxValue}");
                        $rawValue = $maxValue;
                    } elseif ($rawValue < $minValue) {
                        error_log("WARNING: Value below min for param_id={$value['param_id']}, tg_id={$value['tg_id']}, shift_id={$shiftId}, index={$index}: {$rawValue} < {$minValue}");
                        $rawValue = $minValue;
                    }
                    
                    // Проверяем на NaN и Infinity
                    if (!is_finite($rawValue)) {
                        error_log("WARNING: Non-finite value for param_id={$value['param_id']}, tg_id={$value['tg_id']}, shift_id={$shiftId}, index={$index}: {$rawValue}");
                        $rawValue = 0;
                    }
                    
                    // Округляем до 6 знаков после запятой (максимум для decimal(15,6))
                    $rounded = round($rawValue, 6);
                    
                    // Дополнительная проверка после округления
                    if ($rounded > $maxValue) {
                        $rounded = $maxValue;
                    } elseif ($rounded < $minValue) {
                        $rounded = $minValue;
                    }
                    
                    // Финальное значение после всех проверок
                    $finalValue = $rounded;
                    
                    // Логируем большие значения для отладки
                    if (abs($finalValue) > 1000000) {
                        error_log("Large value saved: param_id={$value['param_id']}, tg_id={$value['tg_id']}, shift_id={$shiftId}, value={$finalValue}");
                    }
                    
                    $periodStart = $data['period_start'] ?? null;
                    $periodEnd = $data['period_end'] ?? null;
                    $cell = $value['cell'] ?? null;

                    error_log("Inserting value: param_id={$value['param_id']}, tg_id={$value['tg_id']}, shift_id={$shiftId}, value={$finalValue}");
                    
                    executeQuery($insertSql, [
                        (int)$value['param_id'],
                        (int)$value['tg_id'],
                        $date,
                        $shiftId !== null ? (int)$shiftId : null,
                        $finalValue,
                        (int)$userId,
                        $periodType,
                        $periodStart,
                        $periodEnd,
                        $cell
                    ]);
                } catch (Exception $e) {
                    error_log("ERROR inserting value at index {$index}: param_id={$value['param_id']}, tg_id={$value['tg_id']}, shift_id={$shiftId}, value={$value['value']}, error: " . $e->getMessage());
                    throw new Exception("Ошибка при сохранении значения для param_id={$value['param_id']}, tg_id={$value['tg_id']}, shift_id={$shiftId}: " . $e->getMessage());
                }
            }
        }

        sendSuccess(['message' => 'Результаты Блоков успешно сохранены']);
    } catch (Exception $e) {
        sendError('Ошибка при сохранении результатов Блоков: ' . $e->getMessage());
    }
} 