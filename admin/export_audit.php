<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();
$stmt = $pdo->query('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.performed_at DESC LIMIT 1000');

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="lexshield_audit_logs.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['performed_at', 'user', 'action', 'target_table', 'target_id', 'ip_address', 'user_agent']);
while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['performed_at'],
        $row['full_name'] ?? 'System',
        $row['action'],
        $row['target_table'],
        $row['target_id'],
        $row['ip_address'],
        $row['user_agent'],
    ]);
}
fclose($out);
exit;

