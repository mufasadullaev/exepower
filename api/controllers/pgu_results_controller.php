<?php
/**
 * PGU Results Controller
 * Handles API endpoints related to PGU calculation results
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Get all PGU result parameters
 */
function getPguResultParams() {
    requireAuth();

    try {
        $sql = "SELECT id, name, unit, symbol, row_num FROM pgu_result_params ORDER BY id";
        $params = fetchAll($sql);
        sendSuccess(['params' => $params]);
    } catch (Exception $e) {
        sendError('Ошибка при получении параметров результатов ПГУ: ' . $e->getMessage());
    }
}

/**
 * Map shift names (shift1/shift2/shift3) to numeric IDs
 */
function mapShiftNamesToIds($shifts) {
    $map = [ 'shift1' => 1, 'shift2' => 2, 'shift3' => 3 ];
    $ids = [];
    foreach ($shifts as $s) {
        if (isset($map[$s])) $ids[] = $map[$s];
        else if (is_numeric($s)) $ids[] = (int)$s;
    }
    return array_values(array_unique($ids));
}

/**
 * Get PGU result values
 */
function getPguResultValues($data) {
    requireAuth();

    try {
        if (!isset($data['date']) || !isset($data['periodType'])) {
            sendError('Необходимо указать дату и тип периода', 400);
        }

        $date = $data['date'];
        $periodType = $data['periodType'];
        $pguIds = isset($data['pguIds']) ? $data['pguIds'] : [1,2,3];
        $shiftIds = [];
        if ($periodType === 'shift') {
            if (isset($data['shiftIds']) && is_array($data['shiftIds'])) {
                $shiftIds = array_map('intval', $data['shiftIds']);
            } elseif (isset($data['shifts']) && is_array($data['shifts'])) {
                $shiftIds = mapShiftNamesToIds($data['shifts']);
            }
            if (empty($shiftIds)) {
                // если не передали - по умолчанию все 3 смены
                $shiftIds = [1,2,3];
            }
        }

        // Получаем список параметров
        $paramsSql = "SELECT id, name, unit, symbol, row_num FROM pgu_result_params ORDER BY id";
        $params = fetchAll($paramsSql);

        // Загружаем значения
        $resultValues = [];
        if (!empty($pguIds)) {
            $pguIdsStr = implode(',', array_map('intval', $pguIds));
            $sql = "SELECT rv.param_id, rv.pgu_id, rv.value, rv.shift_id, rv.period_type, rv.cell
                         FROM pgu_result_values rv
                    WHERE rv.date = ? AND rv.period_type = ? AND rv.pgu_id IN ($pguIdsStr)";
            $bind = [$date, $periodType];
            if ($periodType === 'shift' && !empty($shiftIds)) {
                $in = implode(',', array_fill(0, count($shiftIds), '?'));
                $sql .= " AND rv.shift_id IN ($in)";
                $bind = array_merge($bind, $shiftIds);
            }
            $values = fetchAll($sql, $bind);

            foreach ($values as $row) {
                $paramId = (int)$row['param_id'];
                $pguId = (int)$row['pgu_id'];
                $shiftId = $row['shift_id'] !== null ? (int)$row['shift_id'] : null;
                $cell = $row['cell'];
                
                $valueData = [
                    'value' => $row['value'],
                    'cell' => $cell
                ];
                
                if ($periodType === 'shift') {
                    if (!isset($resultValues[$paramId])) $resultValues[$paramId] = [];
                    if (!isset($resultValues[$paramId][$shiftId])) $resultValues[$paramId][$shiftId] = [];
                    $resultValues[$paramId][$shiftId][$pguId] = $valueData;
                } else {
                    if (!isset($resultValues[$paramId])) $resultValues[$paramId] = [];
                    $resultValues[$paramId][$pguId] = $valueData;
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
                'symbol' => $param['symbol']
            ];
            if ($periodType === 'shift') {
                $paramData['valuesByShift'] = [];
                $paramData['cellsByShift'] = [];
                foreach ($shiftIds as $sid) {
                    $paramData['valuesByShift'][$sid] = [
                        1 => $resultValues[$paramId][$sid][1]['value'] ?? null,
                        2 => $resultValues[$paramId][$sid][2]['value'] ?? null,
                        3 => $resultValues[$paramId][$sid][3]['value'] ?? null,
                    ];
                    $paramData['cellsByShift'][$sid] = [
                        1 => $resultValues[$paramId][$sid][1]['cell'] ?? null,
                        2 => $resultValues[$paramId][$sid][2]['cell'] ?? null,
                        3 => $resultValues[$paramId][$sid][3]['cell'] ?? null,
                    ];
                }
            } else {
                $paramData['values'] = [
                    1 => $resultValues[$paramId][1]['value'] ?? null,
                    2 => $resultValues[$paramId][2]['value'] ?? null,
                    3 => $resultValues[$paramId][3]['value'] ?? null,
                ];
                $paramData['cells'] = [
                    1 => $resultValues[$paramId][1]['cell'] ?? null,
                    2 => $resultValues[$paramId][2]['cell'] ?? null,
                    3 => $resultValues[$paramId][3]['cell'] ?? null,
                ];
            }
            $responseData[] = $paramData;
        }

        sendSuccess([
            'params' => $responseData,
            'date' => $date,
            'periodType' => $periodType,
            'pguIds' => $pguIds,
            'shiftIds' => $shiftIds
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при получении результатов ПГУ: ' . $e->getMessage());
    }
}

/**
 * Save PGU result values
 */
function savePguResultValues($data) {
    requireAuth();

    try {
        if (!isset($data['date']) || !isset($data['periodType']) || !isset($data['values'])) {
            sendError('Необходимо указать дату, тип периода и значения', 400);
        }

        $date = $data['date'];
        $periodType = $data['periodType'];
        $values = $data['values'];
        $userId = $_SESSION['user_id'] ?? 1;

        // Удаляем только записи для конкретных смен, а не все за дату
        if ($periodType === 'shift') {
            // Получаем уникальные shift_id из значений
            $shiftIds = array_filter(array_unique(array_column($values, 'shift_id')));
            error_log("PGU Save: Deleting records for shifts: " . implode(', ', $shiftIds));
            if (!empty($shiftIds)) {
                $placeholders = str_repeat('?,', count($shiftIds) - 1) . '?';
                $deleteSql = "DELETE FROM pgu_result_values WHERE date = ? AND period_type = ? AND shift_id IN ($placeholders)";
                $deleteParams = array_merge([$date, $periodType], $shiftIds);
                executeQuery($deleteSql, $deleteParams);
            }
        } else {
            // Для day и period удаляем все записи за дату/период
            error_log("PGU Save: Deleting all records for date $date, period type $periodType");
            $deleteSql = "DELETE FROM pgu_result_values WHERE date = ? AND period_type = ?";
            executeQuery($deleteSql, [$date, $periodType]);
        }

            foreach ($values as $value) {
            if (isset($value['param_id']) && isset($value['pgu_id']) && isset($value['value'])) {
                // Получаем row_num для данного параметра
                $rowData = fetchOne("SELECT row_num FROM pgu_result_params WHERE id = ?", [$value['param_id']]);
                if (!$rowData) {
                    continue; // Пропускаем если параметр не найден
                }
                
                $rowNum = $rowData['row_num'];
                
                // Определяем букву колонки на основе pgu_id
                $columnLetter = '';
                switch ($value['pgu_id']) {
                    case 1:
                        $columnLetter = 'F';
                        break;
                    case 2:
                        $columnLetter = 'G';
                        break;
                    case 3:
                        $columnLetter = 'H';
                        break;
                    default:
                        $columnLetter = 'F'; // По умолчанию
                        break;
                }
                
                $cell = $columnLetter . $rowNum;
                
                    $insertSql = "INSERT INTO pgu_result_values 
                             (param_id, pgu_id, date, shift_id, value, user_id, period_type, cell) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $shiftId = isset($value['shift_id']) ? $value['shift_id'] : null;
                $rounded = round((float)$value['value'], 4);
                
                error_log("PGU Save: Inserting param_id={$value['param_id']}, pgu_id={$value['pgu_id']}, shift_id=$shiftId, cell=$cell, value=$rounded");
                    
                executeQuery($insertSql, [
                        $value['param_id'],
                    $value['pgu_id'],
                        $date,
                        $shiftId,
                    $rounded,
                        $userId,
                    $periodType,
                    $cell
                    ]);
                }
            }

            sendSuccess(['message' => 'Результаты ПГУ успешно сохранены']);
    } catch (Exception $e) {
        sendError('Ошибка при сохранении результатов ПГУ: ' . $e->getMessage());
    }
} 