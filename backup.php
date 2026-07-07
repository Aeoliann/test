<?php
// Настройки подключения
$host = 'localhost';
$user = 'root';
$pass = '';
$name = 'crm_db';

// Настройка папки бэкапа
$backupDir = __DIR__ . '/backups/';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}
$backupFile = $backupDir . $name . '_' . date('Y-m-d_H-i-s') . '.sql';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $sql = "-- Бэкап базы данных: $name\n-- Дата: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    // Получаем список всех таблиц
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // Получаем структуру таблицы
        $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $sql .= "\n\n" . $createTable['Create Table'] . ";\n\n";

        // Получаем данные таблицы
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll();
        foreach ($rows as $row) {
            $sql .= "INSERT INTO `$table` VALUES(";
            $values = [];
            foreach ($row as $value) {
                if (is_null($value)) {
                    $values[] = "NULL";
                } else {
                    $values[] = $pdo->quote($value);
                }
            }
            $sql .= implode(', ', $values) . ");\n";
        }
    }
    
    $sql .= "\n\nSET FOREIGN_KEY_CHECKS=1;\n";

    // Сохраняем в файл
    file_put_contents($backupFile, $sql);
    echo "Бэкап успешно создан средствами PHP: " . basename($backupFile);

} catch (Exception $e) {
    echo "Ошибка при создании бэкапа: " . $e->getMessage();
}
?>