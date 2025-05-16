<?php
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/db.php';

function getEquipment() {
    requireAuth();
    $type = $_GET['type'] ?? '';
    $where = '';
    $params = [];
    if ($type === 'block') {
        $where = 'WHERE et.name = ?';
        $params[] = 'ТГ';
    } elseif ($type === 'pgu') {
        $where = 'WHERE et.name = ?';
        $params[] = 'ПГУ';
    }
    $equipment = fetchAll(
        "SELECT e.id, e.name, e.type_id, e.description FROM equipment e JOIN equipment_types et ON e.type_id = et.id $where ORDER BY e.id",
        $params
    );
    sendSuccess(['equipment' => $equipment]);
} 