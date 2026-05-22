<?php
require 'db.php';

// Генерируем свежий хэш для пароля "1"
$new_hash = password_hash('1', PASSWORD_DEFAULT);

try {
    $pdo->prepare("SET FOREIGN_KEY_CHECKS = 0")->execute();
    $pdo->prepare("TRUNCATE TABLE users")->execute();
    
    $stmt = $pdo->prepare("INSERT INTO users (id, login, password, role) VALUES (1, 'admin', ?, 'admin')");
    $stmt->execute([$new_hash]);
    
    $pdo->prepare("SET FOREIGN_KEY_CHECKS = 1")->execute();
    
    echo "<h1>Готово!</h1>";
    echo "<p>Пользователь <b>admin</b> создан с паролем <b>1</b></p>";
    echo "<p>Теперь попробуйте войти: <a href='auth.html'>на страницу входа</a></p>";
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage();
}
?>