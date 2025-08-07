<?php
/**
 * Тестовый скрипт для проверки маппинга оборудования к ПГУ
 */

require_once 'config.php';
require_once 'helpers/db.php';
require_once 'controllers/counters_controller.php';

echo "=== Тест маппинга оборудования к ПГУ ===\n\n";

try {
    $db = getDbConnection();
    
    // Получаем все счетчики выработки с информацией об оборудовании
    $stmt = $db->prepare('
        SELECT m.id as meter_id, m.name as meter_name, m.equipment_id,
               e.name as equipment_name, e.type_id
        FROM meters m
        JOIN equipment e ON m.equipment_id = e.id
        WHERE m.meter_type_id = 1
        ORDER BY m.id
    ');
    $stmt->execute();
    $meters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Счетчики выработки электроэнергии:\n";
    echo str_repeat('-', 80) . "\n";
    printf("%-5s %-25s %-5s %-20s %-5s %-10s %-25s\n", 
           'ID', 'Название счетчика', 'EqID', 'Оборудование', 'Type', 'ПГУ', 'Маппинг');
    echo str_repeat('-', 105) . "\n";
    
    foreach ($meters as $meter) {
        $pguId = getPguIdFromEquipment($meter['equipment_id']);
        $pguText = $pguId ? "ПГУ $pguId" : "не ПГУ";
        
        // Определяем тип оборудования и row_num
        $isGT = strpos($meter['equipment_name'], 'ГТ') !== false;
        $rowNum = $isGT ? 10 : 11;
        $columnLetter = $pguId == 1 ? 'F' : 'G';
        $mapping = $pguId ? "row_num=$rowNum, колонка=$columnLetter" : "не синхронизируется";
        
        printf("%-5s %-25s %-5s %-20s %-5s %-10s %-25s\n",
               $meter['meter_id'],
               $meter['meter_name'],
               $meter['equipment_id'],
               $meter['equipment_name'],
               $meter['type_id'],
               $pguText,
               $mapping);
    }
    
    echo str_repeat('-', 105) . "\n\n";
    
    // Проверяем существующие показания
    $stmt = $db->prepare('
        SELECT mr.meter_id, mr.date, mr.shift1, mr.shift2, mr.shift3,
               m.equipment_id, e.name as equipment_name
        FROM meter_readings mr
        JOIN meters m ON mr.meter_id = m.id
        JOIN equipment e ON m.equipment_id = e.id
        WHERE m.meter_type_id = 1
        ORDER BY mr.date DESC, mr.meter_id
        LIMIT 5
    ');
    $stmt->execute();
    $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Последние показания счетчиков выработки:\n";
    echo str_repeat('-', 100) . "\n";
    printf("%-5s %-12s %-20s %-5s %-10s %-10s %-10s %-10s\n",
           'ID', 'Дата', 'Оборудование', 'EqID', 'ПГУ', 'Смена1', 'Смена2', 'Смена3');
    echo str_repeat('-', 100) . "\n";
    
    foreach ($readings as $reading) {
        $pguId = getPguIdFromEquipment($reading['equipment_id']);
        $pguText = $pguId ? "ПГУ $pguId" : "не ПГУ";
        
        printf("%-5s %-12s %-20s %-5s %-10s %-10s %-10s %-10s\n",
               $reading['meter_id'],
               $reading['date'],
               $reading['equipment_name'],
               $reading['equipment_id'],
               $pguText,
               $reading['shift1'] ?? 'NULL',
               $reading['shift2'] ?? 'NULL',
               $reading['shift3'] ?? 'NULL');
    }
    
    echo str_repeat('-', 100) . "\n\n";
    
    // Проверяем существующие записи в pgu_fullparam_values
    $stmt = $db->prepare('
        SELECT pfv.*, pfp.title as param_title
        FROM pgu_fullparam_values pfv
        JOIN pgu_fullparams pfp ON pfv.fullparam_id = pfp.id
        WHERE pfp.row_num = 10
        ORDER BY pfv.date DESC, pfv.pgu_id, pfv.shift_id
        LIMIT 10
    ');
    $stmt->execute();
    $pguValues = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Существующие записи в pgu_fullparam_values (row_num = 10):\n";
    echo str_repeat('-', 80) . "\n";
    printf("%-5s %-5s %-12s %-5s %-15s %-10s\n",
           'ID', 'ПГУ', 'Дата', 'Смена', 'Значение', 'Ячейка');
    echo str_repeat('-', 80) . "\n";
    
    foreach ($pguValues as $value) {
        printf("%-5s %-5s %-12s %-5s %-15s %-10s\n",
               $value['id'],
               $value['pgu_id'],
               $value['date'],
               $value['shift_id'],
               $value['value'],
               $value['cell']);
    }
    
    echo str_repeat('-', 80) . "\n";
    
} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
} 