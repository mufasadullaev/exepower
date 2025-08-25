<?php
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/db.php';

// Получить назначения резервов на дату или активные
function getMeterReserveAssignments() {
	$user = requireAuth();
	$params = $_GET;
	$date = isset($params['date']) ? $params['date'] : null;
	$activeOnly = isset($params['active']) ? (int)$params['active'] : 0;
	try {
		if ($activeOnly) {
			$rows = fetchAll("SELECT * FROM meter_reserve_assignments WHERE end_time IS NULL ORDER BY start_time DESC", []);
			return sendSuccess($rows);
		}
		if (!$date) {
			return sendError('Не указана дата');
		}
		$startOfDay = $date . ' 00:00:00';
		$endOfDay = $date . ' 23:59:59';
		$rows = fetchAll(
			"SELECT * FROM meter_reserve_assignments
			 WHERE (start_time <= ?)
			   AND (end_time IS NULL OR end_time >= ?)
			 ORDER BY start_time ASC",
			[$endOfDay, $startOfDay]
		);
		return sendSuccess($rows);
	} catch (Exception $e) {
		return sendError('Ошибка получения назначений резерва: ' . $e->getMessage());
	}
}

// Начать обслуживание резервом (создать назначение)
function startMeterReserveAssignment() {
	$user = requireAuth();
	$input = json_decode(file_get_contents('php://input'), true);
	if (!isset($input['reserve_meter_id'], $input['primary_meter_id'], $input['start_time'])) {
		return sendError('Не все обязательные поля заполнены', 400);
	}
	$reserveId = (int)$input['reserve_meter_id'];
	$primaryId = (int)$input['primary_meter_id'];
	$startTime = $input['start_time'];
	$comment = isset($input['comment']) ? $input['comment'] : null;
	try {
		// Проверка: для данного резерва не должно быть открытого назначения
		$open = fetchOne("SELECT id FROM meter_reserve_assignments WHERE reserve_meter_id = ? AND end_time IS NULL", [$reserveId]);
		if ($open) {
			return sendError('Для данного резервного счетчика уже есть активное назначение', 400);
		}
		// Автоподстановка стартовых показаний: берём конец предыдущего назначения этого же резерва
		$last = fetchOne("SELECT end_reading FROM meter_reserve_assignments WHERE reserve_meter_id = ? AND end_time IS NOT NULL ORDER BY end_time DESC LIMIT 1", [$reserveId]);
		$startReading = isset($input['start_reading']) ? (float)$input['start_reading'] : null;
		if ($startReading === null) {
			if ($last && $last['end_reading'] !== null) {
				$startReading = (float)$last['end_reading'];
			} else {
				return sendError('Не найдено предыдущее окончание. Укажите стартовое показание вручную', 400);
			}
		}
		$insertId = insert('meter_reserve_assignments', [
			'reserve_meter_id' => $reserveId,
			'primary_meter_id' => $primaryId,
			'start_time' => $startTime,
			'start_reading' => $startReading,
			'user_id' => $user['id'],
			'comment' => $comment
		]);
		return sendSuccess(['id' => $insertId]);
	} catch (Exception $e) {
		return sendError('Ошибка создания назначения резерва: ' . $e->getMessage());
	}
}

// Завершить обслуживание (закрыть назначение)
function endMeterReserveAssignment($id) {
	$user = requireAuth();
	$input = json_decode(file_get_contents('php://input'), true);
	if (!isset($input['end_time'], $input['end_reading'])) {
		return sendError('Не указаны end_time и end_reading', 400);
	}
	$endTime = $input['end_time'];
	$endReading = (float)$input['end_reading'];
	$comment = isset($input['comment']) ? $input['comment'] : null;
	try {
		$assignment = fetchOne("SELECT * FROM meter_reserve_assignments WHERE id = ?", [$id]);
		if (!$assignment) {
			return sendError('Назначение не найдено', 404);
		}
		if ($assignment['end_time'] !== null) {
			return sendError('Назначение уже завершено', 400);
		}
		executeQuery("UPDATE meter_reserve_assignments SET end_time = ?, end_reading = ?, comment = COALESCE(?, comment) WHERE id = ?", [$endTime, $endReading, $comment, $id]);
		return sendSuccess(['id' => $id]);
	} catch (Exception $e) {
		return sendError('Ошибка завершения назначения резерва: ' . $e->getMessage());
	}
} 