<?php



session_start();
require 'db.php';
header('Content-Type: application/json');

// Получаем данные из JS
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['project_id'], $data['ttn_number'], $data['ttn_date'])) {
    try {
        $sql = "INSERT INTO project_ttns (project_id, ttn_number, ttn_date, product_info) 
                VALUES (:pid, :num, :dt, :info)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':pid'  => (int)$data['project_id'],
            ':num'  => $data['ttn_number'],
            ':dt'   => $data['ttn_date'],
            ':info' => $data['product_info'] ?? ''
        ]);

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        // Если база ругнется, мы увидим причину
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Не все поля заполнены']);
}

?>