<?php
require "../config/db.php";

$stmt = $pdo->query("SELECT * FROM parking WHERE Status='available'");
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($slots);
?>