<?php
/**
 * Blocks (TG) Calculations Controller - Skeleton
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/blocks_results_controller.php';

/**
 * Main entry point for Blocks calculations (skeleton)
 */
function performBlocksFullCalculation() {
    requireAuth();

    try {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['periodType']) || !isset($data['dates'])) {
            sendError('Необходимо указать тип периода и даты', 400);
        }

        // Пока заглушка: не считаем формулы, только подтверждаем запуск и создаем пустой набор
        $periodType = $data['periodType'];
        if ($periodType === 'shift') {
            $date = $data['dates']['selectedDate'];
            $shifts = $data['shifts'] ?? [];
            $values = []; // сюда будут попадать рассчитанные значения
            if (!empty($values)) {
                saveBlocksResultValues([
                    'date' => $date,
                    'periodType' => 'shift',
                    'values' => $values
                ]);
            }
        } elseif ($periodType === 'day') {
            $date = $data['dates']['selectedDate'];
            $values = [];
            if (!empty($values)) {
                saveBlocksResultValues([
                    'date' => $date,
                    'periodType' => 'day',
                    'values' => $values
                ]);
            }
        } else { // period
            $start = $data['dates']['startDate'];
            $end = $data['dates']['endDate'];
            $values = [];
            if (!empty($values)) {
                saveBlocksResultValues([
                    'date' => $start,
                    'periodType' => 'period',
                    'period_start' => $start,
                    'period_end' => $end,
                    'values' => $values
                ]);
            }
        }

        sendSuccess([
            'message' => 'Скелет расчетов Блоков выполнен',
            'results' => 0,
            'calculatedParams' => 0
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при выполнении расчетов Блоков: ' . $e->getMessage());
    }
} 