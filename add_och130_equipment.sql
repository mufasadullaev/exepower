-- Добавление оборудования ОЧ-130 в таблицу equipment
INSERT INTO `equipment` (`id`, `name`, `type_id`, `description`) VALUES
(130, 'ОЧ-130', 1, 'Общестанционная часть 130 ата')
ON DUPLICATE KEY UPDATE 
  `name` = VALUES(`name`),
  `type_id` = VALUES(`type_id`),
  `description` = VALUES(`description`);

-- Обновление AUTO_INCREMENT для equipment
ALTER TABLE `equipment` AUTO_INCREMENT=131; 