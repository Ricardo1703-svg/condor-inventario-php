<?php
// Sonda de salud para Kubernetes: responde 200 solo si la base de datos contesta.
declare(strict_types=1);
require __DIR__ . '/db.php';

header('Content-Type: application/json');
try {
    db()->query('SELECT 1');
    echo json_encode(['status' => 'ok', 'db' => 'up', 'pod' => gethostname()]);
} catch (Throwable $ex) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'db' => 'down']);
}
