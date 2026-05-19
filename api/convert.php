<?php
declare(strict_types=1);

/**
 * AJAX endpoint: POST a markdown file + render options, get back a PDF
 * binary on success or a JSON error on failure.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/converter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed.', 405);
    exit;
}

$jobDir = null;
try {
    $opts   = readAndValidateOptions($_POST);
    $upload = validateUpload($_FILES['markdown'] ?? null);

    if (!is_dir(WORK_DIR)) {
        mkdir(WORK_DIR, 0775, true);
    }

    $jobId  = bin2hex(random_bytes(8));
    $jobDir = WORK_DIR . '/job_' . $jobId;
    mkdir($jobDir, 0775, true);

    $mdPath   = $jobDir . '/input.md';
    $cssPath  = $jobDir . '/style.html';
    $htmlPath = $jobDir . '/output.html';
    $pdfPath  = $jobDir . '/output.pdf';

    move_uploaded_file($upload['tmp_name'], $mdPath);
    file_put_contents($cssPath, buildStyleHeader($opts));

    runPandoc($mdPath, $cssPath, $htmlPath, $upload['title']);
    runChrome($htmlPath, $pdfPath, $jobDir);

    $downloadName = pathinfo($upload['name'], PATHINFO_FILENAME) . '.pdf';
    streamPdf($pdfPath, $downloadName);
} catch (InvalidArgumentException $e) {
    respondError($e->getMessage(), 400);
} catch (Throwable $e) {
    respondError('Conversion failed: ' . $e->getMessage(), 500);
} finally {
    if ($jobDir !== null) {
        cleanup($jobDir);
    }
}

function respondError(string $msg, int $code = 400): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => $msg]);
}
