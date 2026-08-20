    <?php
session_start();
require 'db.php';
header('Content-Type: application/json');

// замените старое чтение php://input во всех обработчиках на эту строчку:
// ИСПРАВЛЕНО:
$data = !empty($_POST) ? $_POST : ($GLOBALS['__JSON_CACHE__'] ?? json_decode(file_get_contents('php://input'), true));
$id = (int)($data['id'] ?? 0);

if ($id > 0) {
    $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Некорректный ID']);
}
?>