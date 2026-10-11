<?php
#php 8.x

declare(strict_types=1);

session_start();

const MAX_UPLOAD_SIZE = 50 * 1024 * 1024;
const SESSION_TTL = 3600;

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

if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0700, true);
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

// Legge e valida l'area di estrazione (in punti PDF) dai
// parametri POST. Restituisce null se non è valida.
function readAreaFromPost(): ?array
{
    $area = [];

    foreach (['x', 'y', 'w', 'h'] as $key) {
        $value = filter_var(
            $_POST[$key] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($value === false || $value === null) {
            return null;
        }

        $area[$key] = $value;
    }

    // Limiti prudenziali, da adeguare alle esigenze applicative.
    if (
        $area['x'] < 0 || $area['y'] < 0
        || $area['w'] <= 0 || $area['h'] <= 0
        || $area['x'] > 20000 || $area['y'] > 20000
        || $area['w'] > 20000 || $area['h'] > 20000
    ) {
        return null;
    }

    return $area;
}

// Estrae il testo di una pagina nell'area indicata.
function extractAreaText(string $pdfPath, int $page, array $area): array
{
    return runCommand([
        PDFTOTEXT_BIN,
        '-f', (string) $page,
        '-l', (string) $page,
        '-x', (string) $area['x'],
        '-y', (string) $area['y'],
        '-W', (string) $area['w'],
        '-H', (string) $area['h'],
        $pdfPath,
        '-',
    ]);
}

// Elimina ricorsivamente una cartella e il suo contenuto.
function deleteDirectory(string $dir): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $dir,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($dir);
}

// Elimina le sessioni scadute per evitare che i PDF
// restino su disco senza limite.
foreach (glob(BASE_TMP . '/*', GLOB_ONLYDIR) ?: [] as $oldSessionDir) {
    if (!preg_match(
        '/^[a-f0-9]{32}$/',
        basename($oldSessionDir)
    )) {
        continue;
    }

    if (time() - filemtime($oldSessionDir) < SESSION_TTL) {
        continue;
    }

    deleteDirectory($oldSessionDir);
}

// Tiene viva la sessione corrente finché è in uso.
if (is_dir($sessionDir)) {
    touch($sessionDir);
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

    $area = readAreaFromPost();

    if (
        $page === false || $page === null
        || $page < 1 || $page > $totalPages
        || $area === null
    ) {
        jsonResponse([
            'error' => 'Coordinate o numero di pagina non validi.'
        ], 400);
    }

    $result = extractAreaText($pdfPath, $page, $area);

    if ($result['code'] !== 0) {
        jsonResponse([
            'error' => 'Errore durante l’estrazione del testo.'
        ], 500);
    }

    jsonResponse([
        'success' => true,
        'page' => $page,
        'area' => $area,
        'text' => $result['output'],
    ]);
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'extract_bulk'
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

    $area = readAreaFromPost();

    if ($totalPages < 1 || $area === null) {
        jsonResponse([
            'error' => 'Coordinate non valide.'
        ], 400);
    }

    // Intervallo pagine opzionale, per l'estrazione a chunk.
    $from = filter_var(
        $_POST['from'] ?? 1,
        FILTER_VALIDATE_INT
    );

    $to = filter_var(
        $_POST['to'] ?? $totalPages,
        FILTER_VALIDATE_INT
    );

    if ($from === false || $from === null || $from < 1) {
        $from = 1;
    }

    if ($to === false || $to === null || $to > $totalPages) {
        $to = $totalPages;
    }

    if ($from > $to) {
        jsonResponse([
            'error' => 'Intervallo di pagine non valido.'
        ], 400);
    }

    $pages = [];

    for ($page = $from; $page <= $to; $page++) {
        $result = extractAreaText($pdfPath, $page, $area);

        if ($result['code'] !== 0) {
            jsonResponse([
                'error' => "Errore durante l’estrazione della pagina {$page}."
            ], 500);
        }

        $pages[] = [
            'page' => $page,
            'text' => $result['output'],
        ];
    }

    jsonResponse([
        'success' => true,
        'from' => $from,
        'to' => $to,
        'area' => $area,
        'pages' => $pages,
    ]);
}