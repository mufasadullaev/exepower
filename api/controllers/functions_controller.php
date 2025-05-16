<?php
/**
 * Functions Controller
 * Handles API endpoints related to functions and their coefficients
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Get all functions
 */
function getAllFunctions() {
    // Check authentication
    requireAuth();

    try {
        // Get all functions from the database
        $sql = "SELECT id, name, symbol, unit FROM functions ORDER BY name";
        $functions = fetchAll($sql);

        sendSuccess(['functions' => $functions]);
    } catch (Exception $e) {
        sendError('Ошибка при получении функций: ' . $e->getMessage());
    }
}

/**
 * Get all coefficient sets for a specific function
 * 
 * @param int $functionId The ID of the function
 */
function getFunctionCoeffSets($functionId) {
    // Check authentication
    requireAuth();

    try {
        // Validate function ID
        if (!$functionId || !is_numeric($functionId)) {
            sendError('Некорректный ID функции', 400);
        }

        // Check if function exists
        $functionSql = "SELECT id, name, symbol, unit FROM functions WHERE id = ?";
        $function = fetchOne($functionSql, [$functionId]);

        if (!$function) {
            sendError('Функция не найдена', 404);
        }

        // Get all coefficient sets for this function
        $sql = "SELECT id, function_id, x_value, created_at 
                FROM function_coeff_sets 
                WHERE function_id = ? 
                ORDER BY x_value";
        $coeffSets = fetchAll($sql, [$functionId]);

        sendSuccess([
            'function' => $function,
            'coeff_sets' => $coeffSets
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при получении наборов коэффициентов: ' . $e->getMessage());
    }
}

/**
 * Get coefficients for a specific coefficient set
 * 
 * @param int $functionId The ID of the function
 * @param int $setId The ID of the coefficient set
 */
function getCoefficients($functionId, $setId) {
    // Check authentication
    requireAuth();

    try {
        // Validate IDs
        if (!$functionId || !is_numeric($functionId) || !$setId || !is_numeric($setId)) {
            sendError('Некорректные параметры запроса', 400);
        }

        // Check if function exists
        $functionSql = "SELECT id, name, symbol, unit FROM functions WHERE id = ?";
        $function = fetchOne($functionSql, [$functionId]);

        if (!$function) {
            sendError('Функция не найдена', 404);
        }

        // Check if coefficient set exists and belongs to the function
        $setSql = "SELECT id, function_id, x_value, created_at 
                   FROM function_coeff_sets 
                   WHERE id = ? AND function_id = ?";
        $coeffSet = fetchOne($setSql, [$setId, $functionId]);

        if (!$coeffSet) {
            sendError('Набор коэффициентов не найден', 404);
        }

        // Get all coefficients for this set
        $sql = "SELECT id, coeff_set_id, coeff_index, coeff_value 
                FROM function_coefficients 
                WHERE coeff_set_id = ? 
                ORDER BY coeff_index";
        $coefficients = fetchAll($sql, [$setId]);

        sendSuccess([
            'function' => $function,
            'coeff_set' => $coeffSet,
            'coefficients' => $coefficients
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при получении коэффициентов: ' . $e->getMessage());
    }
}

/**
 * Update coefficients for a specific set
 * 
 * @param int $functionId The ID of the function
 * @param int $setId The ID of the coefficient set
 */
function updateCoefficients($functionId, $setId) {
    // Check authentication
    requireAuth();

    try {
        // Validate IDs
        if (!$functionId || !is_numeric($functionId) || !$setId || !is_numeric($setId)) {
            sendError('Некорректные параметры запроса', 400);
        }

        // Get JSON data from request
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['coefficients']) || !is_array($data['coefficients'])) {
            sendError('Некорректные данные запроса', 400);
        }

        // Check if function exists
        $functionSql = "SELECT id FROM functions WHERE id = ?";
        $function = fetchOne($functionSql, [$functionId]);

        if (!$function) {
            sendError('Функция не найдена', 404);
        }

        // Check if coefficient set exists and belongs to the function
        $setSql = "SELECT id FROM function_coeff_sets WHERE id = ? AND function_id = ?";
        $coeffSet = fetchOne($setSql, [$setId, $functionId]);

        if (!$coeffSet) {
            sendError('Набор коэффициентов не найден', 404);
        }

        // Start a transaction
        $db = getDbConnection();
        $db->beginTransaction();

        try {
            foreach ($data['coefficients'] as $coeff) {
                if (!isset($coeff['id']) || !isset($coeff['value'])) {
                    throw new Exception('Некорректные данные коэффициента');
                }

                // Update the coefficient
                $updateSql = "UPDATE function_coefficients 
                              SET coeff_value = ? 
                              WHERE id = ? AND coeff_set_id = ?";
                executeQuery($updateSql, [$coeff['value'], $coeff['id'], $setId]);
            }

            // Commit the transaction
            $db->commit();
            
            sendSuccess(['message' => 'Коэффициенты успешно обновлены']);
        } catch (Exception $e) {
            // Rollback the transaction on error
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при обновлении коэффициентов: ' . $e->getMessage());
    }
}

/**
 * Create a new coefficient set for a function
 * 
 * @param int $functionId The ID of the function
 */
function createCoeffSet($functionId) {
    // Check authentication
    requireAuth();

    try {
        // Validate function ID
        if (!$functionId || !is_numeric($functionId)) {
            sendError('Некорректный ID функции', 400);
        }

        // Get JSON data from request
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['x_value']) || !isset($data['coefficients']) || !is_array($data['coefficients'])) {
            sendError('Некорректные данные запроса', 400);
        }

        // Check if function exists
        $functionSql = "SELECT id FROM functions WHERE id = ?";
        $function = fetchOne($functionSql, [$functionId]);

        if (!$function) {
            sendError('Функция не найдена', 404);
        }

        // Check if a coefficient set with this x_value already exists
        $checkSql = "SELECT id FROM function_coeff_sets WHERE function_id = ? AND x_value = ?";
        $existingSet = fetchOne($checkSql, [$functionId, $data['x_value']]);

        if ($existingSet) {
            sendError('Набор коэффициентов для этого значения x уже существует', 409);
        }

        // Start a transaction
        $db = getDbConnection();
        $db->beginTransaction();

        try {
            // Insert the new coefficient set
            $setId = insert('function_coeff_sets', [
                'function_id' => $functionId,
                'x_value' => $data['x_value']
            ]);

            // Insert all coefficients
            foreach ($data['coefficients'] as $coeff) {
                if (!isset($coeff['index']) || !isset($coeff['value'])) {
                    throw new Exception('Некорректные данные коэффициента');
                }

                insert('function_coefficients', [
                    'coeff_set_id' => $setId,
                    'coeff_index' => $coeff['index'],
                    'coeff_value' => $coeff['value']
                ]);
            }

            // Commit the transaction
            $db->commit();
            
            sendSuccess([
                'message' => 'Набор коэффициентов успешно создан',
                'coeff_set_id' => $setId
            ]);
        } catch (Exception $e) {
            // Rollback the transaction on error
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        sendError('Ошибка при создании набора коэффициентов: ' . $e->getMessage());
    }
} 