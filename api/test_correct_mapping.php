<?php
/**
 * Тестовый скрипт для проверки правильного маппинга ГТ/ПТ
 */

require_once 'config.php';
require_once 'helpers/db.php';
require_once 'controllers/counters_controller.php';

echo "=== Тест правильного маппинга ГТ/ПТ ===\n\n";

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
    
    echo "Маппинг счетчиков выработки:\n";
    echo str_repeat('-', 120) . "\n";
    printf("%-5s %-25s %-20s %-10s %-15s %-15s %-15s\n", 
           'ID', 'Название счетчика', 'Оборудование', 'ПГУ', 'Тип', 'Row_num', 'Колонка');
    echo str_repeat('-', 120) . "\n";
    
    foreach ($meters as $meter) {
        $pguId = getPguIdFromEquipment($meter['equipment_id']);
        $pguText = $pguId ? "ПГУ $pguId" : "не ПГУ";
        
        // Определяем тип оборудования и row_num
        $isGT = strpos($meter['equipment_name'], 'ГТ') !== false;
        $equipmentType = $isGT ? 'ГТ' : 'ПТ';
        $rowNum = $isGT ? 10 : 11;
        $columnLetter = $pguId == 1 ? 'F' : 'G';
        
        if ($pguId) {
            $mapping = "row_num=$rowNum, $columnLetter";
        } else {
            $mapping = "не синхронизируется";
        }
        
        printf("%-5s %-25s %-20s %-10s %-15s %-15s %-15s\n",
               $meter['meter_id'],
               $meter['meter_name'],
               $meter['equipment_name'],
               $pguText,
               $equipmentType,
               $rowNum,
               $columnLetter);
    }
    
    echo str_repeat('-', 120) . "\n\n";
    
    // Проверяем параметры в pgu_fullparams
    $stmt = $db->prepare('SELECT * FROM pgu_fullparams WHERE row_num IN (10, 11) ORDER BY row_num');
    $stmt->execute();
    $params = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Параметры ПГУ для синхронизации:\n";
    echo str_repeat('-', 80) . "\n";
    printf("%-5s %-10s %-50s\n", 'ID', 'Row_num', 'Название параметра');
    echo str_repeat('-', 80) . "\n";
    
    foreach ($params as $param) {
        printf("%-5s %-10s %-50s\n",
               $param['id'],
               $param['row_num'],
               $param['title']);
    }
    
    echo str_repeat('-', 80) . "\n\n";
    
    // Показываем примеры ячеек
    echo "Примеры ячеек для разных смен:\n";
    echo str_repeat('-', 60) . "\n";
    printf("%-15s %-15s %-15s %-15s\n", 'ПГУ', 'Смена 1', 'Смена 2', 'Смена 3');
    echo str_repeat('-', 60) . "\n";
    
    // ПГУ 1, ГТ (row_num = 10)
    printf("%-15s %-15s %-15s %-15s\n", 'ПГУ 1, ГТ', 'F10', 'F10', 'F10');
    
    // ПГУ 1, ПТ (row_num = 11)
    printf("%-15s %-15s %-15s %-15s\n", 'ПГУ 1, ПТ', 'F11', 'F11', 'F11');
    
    // ПГУ 2, ГТ (row_num = 10)
    printf("%-15s %-15s %-15s %-15s\n", 'ПГУ 2, ГТ', 'G10', 'G10', 'G10');
    
    // ПГУ 2, ПТ (row_num = 11)
    printf("%-15s %-15s %-15s %-15s\n", 'ПГУ 2, ПТ', 'G11', 'G11', 'G11');
    
    echo str_repeat('-', 60) . "\n";
    
} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
} 