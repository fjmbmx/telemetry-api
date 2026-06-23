<?php
/**
 * Receptor de despliegue por HTTPS (reemplaza el FTP, que Hostinger bloquea
 * desde las IPs de GitHub).
 *
 * GitHub hace: POST multipart con campos token, dir y file=@deploy.zip
 * Este script: valida token -> guarda el zip -> backup + extrae + preserva .env.
 *
 * El token va inyectado en base64 (__DEPLOY_TOKEN_B64__) por el workflow.
 * Vive en public_html (fuera de la carpeta que se reemplaza). Protegido por token.
 */

set_time_limit(600);
ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');

// --- 1) Autenticacion ---
$EXPECTED = base64_decode('__DEPLOY_TOKEN_B64__', true);
$token    = $_POST['token'] ?? $_GET['token'] ?? '';
$EXPECTED = is_string($EXPECTED) ? trim($EXPECTED) : '';
$token    = trim((string) $token);

if ($EXPECTED === '' || !hash_equals($EXPECTED, $token)) {
    http_response_code(403);
    exit("403 forbidden\n");
}

$base = __DIR__;                 // public_html
$zip  = $base . '/deploy.zip';

// --- 2) Recibir el zip subido por HTTPS (campo multipart "file") ---
if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('400 upload fallo (error=' . ($_FILES['file']['error'] ?? 'sin archivo') . ")\n");
}
if (!move_uploaded_file($_FILES['file']['tmp_name'], $zip)) {
    http_response_code(500);
    exit("500 no se pudo guardar el zip\n");
}

// --- 3) Carpeta destino (sanitizada) ---
$dir = $_POST['dir'] ?? $_GET['dir'] ?? 'dashboard';
$dir = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $dir);
if ($dir === '') {
    $dir = 'dashboard';
}
$target = $base . '/' . $dir;

// --- 4) Backup de la carpeta existente ---
$backupDir = null;
$backupMsg = "backup: (no existia carpeta {$dir})\n";
if (is_dir($target)) {
    $backup = $base . '/' . $dir . '_backup_' . date('Ymd_Hi');
    if (is_dir($backup)) {
        $backup .= '_' . substr((string) time(), -3);
    }
    if (!rename($target, $backup)) {
        http_response_code(500);
        exit("500 no se pudo crear el backup\n");
    }
    $backupDir = $backup;
    $backupMsg = 'backup: ' . basename($backup) . "\n";
}

// --- 5) Crear carpeta destino y extraer ---
if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
    http_response_code(500);
    exit("500 no se pudo crear la carpeta {$dir}\n");
}
$za = new ZipArchive();
if ($za->open($zip) !== true) {
    http_response_code(500);
    exit("500 no se pudo abrir el zip\n");
}
if (!$za->extractTo($target)) {
    $za->close();
    http_response_code(500);
    exit("500 fallo la extraccion\n");
}
$count = $za->numFiles;
$za->close();

// --- 6) Preservar configs del backup (no vienen en el zip) ---
$preserved = [];
if ($backupDir !== null) {
    $defaults = '.env,php/mqtt/config.php,php/rabbitmq/config.php';
    $list = $_POST['preserve'] ?? $_GET['preserve'] ?? $defaults;
    foreach (explode(',', (string) $list) as $rel) {
        $rel = trim($rel);
        if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) {
            continue;
        }
        $src = $backupDir . '/' . $rel;
        $dst = $target . '/' . $rel;
        if (is_file($src)) {
            if (!is_dir(dirname($dst))) {
                @mkdir(dirname($dst), 0755, true);
            }
            if (@copy($src, $dst)) {
                $preserved[] = $rel;
            }
        }
    }
}

// --- 7) Limpieza ---
unlink($zip);

echo "OK deploy " . date('c') . "\n";
echo "destino: {$dir}/\n";
echo $backupMsg;
echo 'preservados: ' . ($preserved ? implode(', ', $preserved) : '(ninguno)') . "\n";
echo "archivos extraidos: {$count}\n";
