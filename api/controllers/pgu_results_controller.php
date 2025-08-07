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
    // Check authentication
    requireAuth();

    try {
        // Get all PGU result parameters from the database
        $sql = "SELECT id, name, unit, symbol FROM pgu_result_params ORDER BY id";
        $params = fetchAll($sql);

        // Debug: log the result
        error_log("PGU Result Params Query: " . $sql);
        error_log("PGU Result Params Count: " . count($params));
        error_log("PGU Result Params Data: " . json_encode($params));

        sendSuccess(['params' => $params]);
    } catch (Exception $e) {
        error_log("PGU Result Params Error: " . $e->getMessage());
        sendError('Ошибка при получении параметров результатов ПГУ: ' . $e->getMessage());
    }
}

/**
 * Get PGU result values for specific parameters and equipment
 * 
 * @param array $data Request data containing date, periodType, shifts, etc.
 */
function getPguResultValues($data) {
    // Check authentication
    requireAuth();

    try {
        // Validate required parameters
        if (!isset($data['date']) || !isset($data['periodType'])) {
            sendError('Необходимо указать дату и тип периода', 400);
        }

        $date = $data['date'];
        $periodType = $data['periodType'];
        $shifts = isset($data['shifts']) ? $data['shifts'] : [];
        $equipmentIds = isset($data['equipmentIds']) ? $data['equipmentIds'] : [];

        // Get all PGU result parameters
        $paramsSql = "SELECT id, name, unit, symbol FROM pgu_result_params ORDER BY id";
        $params = fetchAll($paramsSql);

        // Get result values for the specified date and period
        $resultValues = [];
        
        if (!empty($equipmentIds)) {
            $equipmentIdsStr = implode(',', array_map('intval', $equipmentIds));
            
            $valuesSql = "SELECT rv.param_id, rv.equipment_id, rv.value, rv.shift_id, rv.period_type
                         FROM pgu_result_values rv
                         WHERE rv.date = ? 
                         AND rv.period_type = ?
                         AND rv.equipment_id IN ($equipmentIdsStr)";
            
            $values = fetchAll($valuesSql, [$date, $periodType]);
            
            // Organize values by param_id and equipment_id
            foreach ($values as $value) {
                $key = $value['param_id'] . '_' . $value['equipment_id'];
                $resultValues[$key] = $value;
            }
        }

        // Prepare response data
        $responseData = [];
        foreach ($params as $param) {
            $paramData = [
                'id' => $param['id'],
                'name' => $param['name'],
                'unit' => $param['unit'],
                'symbol' => $param['symbol'],
                'values' => []
            ];

            // Add values for each equipment
            foreach ($equipmentIds as $equipmentId) {
                $key = $param['id'] . '_' . $equipmentId;
                $paramData['values'][$equipmentId] = isset($resultValues[$key]) ? $resultValues[$key]['value'] : null;
            }

            $responseData[] = $paramData;
        }

        sendSuccess([
            'params' => $responseData,
            'date' => $date,
            'periodType' => $periodType,
            'equipmentIds' => $equipmentIds
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при получении результатов ПГУ: ' . $e->getMessage());
    }
}

/**
 * Save PGU result values
 * 
 * @param array $data Request data containing values to save
 */
function savePguResultValues($data) {
    // Check authentication
    requireAuth();

    try {
        // Validate required parameters
        if (!isset($data['date']) || !isset($data['periodType']) || !isset($data['values'])) {
            sendError('Необходимо указать дату, тип периода и значения', 400);
        }

        $date = $data['date'];
        $periodType = $data['periodType'];
        $values = $data['values'];
        $userId = $_SESSION['user_id'] ?? 1; // Default to user ID 1 if not set

        // Start transaction
        beginTransaction();

        try {
            // Delete existing values for this date and period type
            $deleteSql = "DELETE FROM pgu_result_values WHERE date = ? AND period_type = ?";
            execute($deleteSql, [$date, $periodType]);

            // Insert new values
            foreach ($values as $value) {
                if (isset($value['param_id']) && isset($value['equipment_id']) && isset($value['value'])) {
                    $insertSql = "INSERT INTO pgu_result_values 
                                 (param_id, equipment_id, date, shift_id, value, user_id, period_type) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                    
                    $shiftId = isset($value['shift_id']) ? $value['shift_id'] : null;
                    
                    execute($insertSql, [
                        $value['param_id'],
                        $value['equipment_id'],
                        $date,
                        $shiftId,
                        $value['value'],
                        $userId,
                        $periodType
                    ]);
                }
            }

            // Commit transaction
            commitTransaction();

            sendSuccess(['message' => 'Результаты ПГУ успешно сохранены']);
        } catch (Exception $e) {
            // Rollback transaction on error
            rollbackTransaction();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при сохранении результатов ПГУ: ' . $e->getMessage());
    }
} 