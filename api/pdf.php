<?php
#php 8.x

declare(strict_types=1);

session_start();

const MAX_CACHE_PAGES = 5;
const MAX_UPLOAD_SIZE = 50 * 1024 * 1024;

const PDFTK_BIN = '/usr/bin/pdftk';
const PDFINFO_BIN = '/usr/bin/pdfinfo';
const PDFTOTEXT_BIN = '/usr/bin/pdftotext';

const BASE_TMP = __DIR__ . '/../tmp/sessions';

header('X-Content-Type-Options: nosniff');

if (!is_dir(BASE_TMP)) {
    mkdir(BASE_TMP, 0700, true);
}

if (empty($_SESSION['pdf_id'])) {
    $_SESSION['pdf_id'] = bin2hex(random_bytes(16));
}

$sessionDir = BASE_TMP . '/' . $_SESSION['pdf_id'];
$cacheDir = $sessionDir . '/cache';

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0700, true);
}

$pdfPath = $sessionDir . '/document.pdf';
$metadataPath = $sessionDir . '/metadata.json';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function runCommand(array $args): array
{
    $command = implode(
        ' ',
        array_map('escapeshellarg', $args)
    );

    $output = [];
    $exitCode = 0;

    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'code' => $exitCode,
        'output' => implode("\n", $output),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'upload') {

    if (
        !isset($_FILES['pdf'])
        || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK
    ) {
        jsonResponse([
            'error' => 'Errore durante il caricamento del PDF.'
        ], 400);
    }

    $file = $_FILES['pdf'];

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        jsonResponse([
            'error' => 'Il PDF supera la dimensione massima consentita.'
        ], 413);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if ($mime !== 'application/pdf') {
        jsonResponse([
            'error' => 'Il file caricato non è un PDF valido.'
        ], 400);
    }

    // Verifica che il file sia leggibile come PDF.
    $info = runCommand([
        PDFINFO_BIN,
        $file['tmp_name']
    ]);

    if ($info['code'] !== 0) {
        jsonResponse([
            'error' => 'Impossibile leggere il documento PDF.'
        ], 400);
    }

    // Estrazione del numero di pagine.
    if (!preg_match(
        '/^Pages:\s+(\d+)\s*$/mi',
        $info['output'],
        $matches
    )) {
        jsonResponse([
            'error' => 'Numero di pagine non disponibile.'
        ], 400);
    }

    $pages = (int) $matches[1];

    if ($pages < 1) {
        jsonResponse([
            'error' => 'Il PDF non contiene pagine.'
        ], 400);
    }

    // Sostituisce il documento della sessione.
    if (!move_uploaded_file(
        $file['tmp_name'],
        $pdfPath
    )) {
        jsonResponse([
            'error' => 'Impossibile salvare il PDF.'
        ], 500);
    }

    // Elimina le vecchie pagine in cache.
    foreach (glob($cacheDir . '/*.pdf') ?: [] as $cachedFile) {
        if (is_file($cachedFile)) {
            unlink($cachedFile);
        }
    }

    $metadata = [
        'pages' => $pages,
        'filename' => basename($file['name']),
        'uploaded_at' => date(DATE_ATOM),
    ];

    file_put_contents(
        $metadataPath,
        json_encode($metadata, JSON_PRETTY_PRINT),
        LOCK_EX
    );

    jsonResponse([
        'success' => true,
        'pages' => $pages,
        'filename' => $metadata['filename'],
    ]);
}

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['action'] ?? '') === 'page'
) {
    if (!is_file($pdfPath) || !is_file($metadataPath)) {
        jsonResponse([
            'error' => 'Nessun PDF caricato.'
        ], 400);
    }

    $metadata = json_decode(
        file_get_contents($metadataPath),
        true
    );

    $totalPages = (int) ($metadata['pages'] ?? 0);
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);

    if (
        $page === false
        || $page === null
        || $page < 1
        || $page > $totalPages
    ) {
        jsonResponse([
            'error' => 'Numero di pagina non valido.'
        ], 400);
    }

    $pageFile = $cacheDir . '/page_' . $page . '.pdf';

    if (!is_file($pageFile)) {

        $result = runCommand([
            PDFTK_BIN,
            $pdfPath,
            'cat',
            (string) $page,
            'output',
            $pageFile,
        ]);

        if ($result['code'] !== 0 || !is_file($pageFile)) {
            jsonResponse([
                'error' => 'Impossibile estrarre la pagina.'
            ], 500);
        }

        // Mantiene in cache al massimo MAX_CACHE_PAGES file.
        $cachedFiles = glob($cacheDir . '/page_*.pdf') ?: [];

        usort($cachedFiles, static function ($a, $b) {
            return filemtime($a) <=> filemtime($b);
        });

        while (count($cachedFiles) > MAX_CACHE_PAGES) {
            $oldest = array_shift($cachedFiles);

            if ($oldest !== $pageFile && is_file($oldest)) {
                unlink($oldest);
            }
        }
    }

    touch($pageFile);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="page.pdf"');
    header('Cache-Control: private, no-store');

    header('Content-Length: ' . filesize($pageFile));

    readfile($pageFile);

    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'extract'
) {
    if (!is_file($pdfPath) || !is_file($metadataPath)) {
        jsonResponse([
            'error' => 'Nessun PDF caricato.'
        ], 400);
    }

    $metadata = json_decode(
        file_get_contents($metadataPath),
        true
    );

    $totalPages = (int) ($metadata['pages'] ?? 0);

    $page = filter_var(
        $_POST['page'] ?? null,
        FILTER_VALIDATE_INT
    );

    $x = filter_var(
        $_POST['x'] ?? null,
        FILTER_VALIDATE_INT
    );

    $y = filter_var(
        $_POST['y'] ?? null,
        FILTER_VALIDATE_INT
    );

    $w = filter_var(
        $_POST['w'] ?? null,
        FILTER_VALIDATE_INT
    );

    $h = filter_var(
        $_POST['h'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $page === false || $page === null
        || $page < 1 || $page > $totalPages
        || $x === false || $x === null
        || $y === false || $y === null
        || $w === false || $w === null
        || $h === false || $h === null
        || $x < 0 || $y < 0
        || $w <= 0 || $h <= 0
    ) {
        jsonResponse([
            'error' => 'Coordinate o numero di pagina non validi.'
        ], 400);
    }

    // Limiti prudenziali, da adeguare alle esigenze applicative.
    if (
        $x > 20000 || $y > 20000
        || $w > 20000 || $h > 20000
    ) {
        jsonResponse([
            'error' => 'Area di estrazione troppo grande.'
        ], 400);
    }

    $result = runCommand([
        PDFTOTEXT_BIN,
        '-f', (string) $page,
        '-l', (string) $page,
        '-x', (string) $x,
        '-y', (string) $y,
        '-W', (string) $w,
        '-H', (string) $h,
        $pdfPath,
        '-',
    ]);

    if ($result['code'] !== 0) {
        jsonResponse([
            'error' => 'Errore durante l’estrazione del testo.'
        ], 500);
    }

    jsonResponse([
        'success' => true,
        'page' => $page,
        'area' => [
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
        ],
        'text' => $result['output'],
    ]);
}