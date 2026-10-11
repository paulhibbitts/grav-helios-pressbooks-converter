<?php
require_once __DIR__ . '/vendor/autoload.php';

use PB\Parser;
use PB\ZipBuilder;

set_time_limit(120);
ini_set('memory_limit', '256M');

// Only handle POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

// Validate file upload
$uploadErrors = [
    UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit. Add a .htaccess file with php_value upload_max_filesize 50M.',
    UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded — please try again.',
    UPLOAD_ERR_NO_FILE    => 'No file was selected.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary directory.',
    UPLOAD_ERR_CANT_WRITE => 'Server failed to write the upload to disk.',
];

$fileError = $_FILES['xhtml']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($fileError !== UPLOAD_ERR_OK) {
    showError($uploadErrors[$fileError] ?? 'Upload error (code ' . $fileError . ').');
}

// Explicit size guard (belt-and-suspenders over .htaccess)
$maxBytes = 50 * 1024 * 1024;
if ($_FILES['xhtml']['size'] > $maxBytes) {
    showError('File exceeds the 50 MB limit.');
}

$ext = strtolower(pathinfo($_FILES['xhtml']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['html', 'xhtml'], true)) {
    showError('Please upload a .html or .xhtml file exported from Pressbooks.');
}

// MIME type check on actual file content (not the browser-supplied header)
if (function_exists('finfo_open')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['xhtml']['tmp_name']);
    $allowedMimes = ['text/html', 'application/xhtml+xml', 'text/xml', 'application/xml', 'text/plain'];
    if (!in_array($mime, $allowedMimes, true)) {
        showError('File does not appear to be an HTML/XHTML file (detected type: ' . htmlspecialchars($mime) . ').');
    }
}

$html       = file_get_contents($_FILES['xhtml']['tmp_name']);
$skipImages       = !empty($_POST['skip_images']);
$figureHtml       = !empty($_POST['figure_html']);
$embedH5p         = !empty($_POST['embed_h5p']);
$portableMarkdown = !empty($_POST['portable_markdown']);

// Where the book will go: Grav Helios Open Reader shows a book's sections at the top of the
// site, while Grav Open Publishing Space (and any Grav site, with Standard Markdown) keeps
// the book's folder in its page addresses. With no destination sent (an older copy of the
// form), the addresses stay as they were before, for Helios Open Reader
$destination = '';
if (isset($_POST['destination'])) {
    $destination = $_POST['destination'];
}
$keepBookFolderInAddresses = false;
if ($destination === 'open-publishing') {
    $keepBookFolderInAddresses = true;
}
if ($portableMarkdown) {
    $keepBookFolderInAddresses = true;
}

// Parse structure
try {
    $parser = new Parser($html);
} catch (\Exception $e) {
    showError('Failed to parse file: ' . $e->getMessage());
}

if (!$parser->isValid()) {
    showError(
        'No Pressbooks structure found in the uploaded file. ' .
        'Make sure you exported your book from Pressbooks as XHTML/HTML ' .
        '(Export → Web → XHTML).'
    );
}

// Optional cover image upload
$coverImageData     = null;
$coverImageFilename = null;
if (!empty($_FILES['cover_image']['tmp_name']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
    $allowedImageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (function_exists('finfo_open')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['cover_image']['tmp_name']);
        if (in_array($mime, $allowedImageMimes, true)) {
            $coverImageData     = file_get_contents($_FILES['cover_image']['tmp_name']);
            $coverImageFilename = preg_replace('/[^a-z0-9._-]/i', '-', basename($_FILES['cover_image']['name']));
        }
    }
}

// Convert and build zip
try {
    $builder = new ZipBuilder($parser, $skipImages, $figureHtml, $embedH5p, $portableMarkdown, $keepBookFolderInAddresses);
    if ($coverImageData !== null) {
        $builder->setCoverImage($coverImageData, $coverImageFilename);
    }
    $zipPath = $builder->build();
} catch (\Exception $e) {
    showError('Conversion failed: ' . $e->getMessage());
}

// Serve the zip
$filename = 'open-reader-' . $parser->bookSlug . '.zip';
setcookie('download_ready', '1', time() + 60, '/');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');
readfile($zipPath);
unlink($zipPath);
exit;

// ── Error page ────────────────────────────────────────────────────────────────

function showError(string $msg): void
{
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversion error</title>
    <style>
      body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
             background: #f5f5f4; display: flex; align-items: center;
             justify-content: center; min-height: 100vh; margin: 0; padding: 1rem; }
      .box { background: #fff; border: 1px solid #e7e5e4; border-radius: 12px;
             padding: 2rem; max-width: 520px; width: 100%; }
      h2 { font-size: 16px; margin: 0 0 8px; color: #991b1b; }
      p  { font-size: 14px; color: #44403c; margin: 0 0 1.5rem; line-height: 1.6; }
      a  { display: inline-block; font-size: 14px; color: #1d4ed8;
           text-decoration: none; padding: 8px 16px; border: 1px solid #bfdbfe;
           border-radius: 8px; background: #eff6ff; }
      a:hover { background: #dbeafe; }
    </style>
    </head>
    <body>
    <div class="box">
      <h2>⚠ Conversion error</h2>
      <p>$safe</p>
      <a href="index.html">← Back to converter</a>
    </div>
    </body>
    </html>
    HTML;
    exit;
}
