<?php
require_once __DIR__ . '/vendor/autoload.php';

use PB\Parser;
use PB\ZipBuilder;

const MAX_UPLOAD_BYTES        = 50 * 1024 * 1024;
const MIN_PREVIEW_PAGE_LENGTH = 500;
const MAX_PREVIEW_PAGES       = 5;
const MAX_PREVIEW_IMAGE_BYTES = 2 * 1024 * 1024;

header('Content-Type: application/json');

function sendError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode(['error' => $message]);
    exit;
}

$fileError = $_FILES['xhtml']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($fileError !== UPLOAD_ERR_OK) {
    sendError(400, 'No file was uploaded.');
}

if ($_FILES['xhtml']['size'] > MAX_UPLOAD_BYTES) {
    sendError(400, 'File exceeds the 50 MB limit.');
}

$html       = file_get_contents($_FILES['xhtml']['tmp_name']);
$skipImages       = !empty($_POST['skip_images']);
$figureHtml       = !empty($_POST['figure_html']);
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

try {
    $parser = new Parser($html);
    if (!$parser->isValid()) {
        throw new \Exception('No Pressbooks structure found in the uploaded file.');
    }

    $builder = new ZipBuilder($parser, $skipImages, $figureHtml, false, $portableMarkdown, $keepBookFolderInAddresses);
    $zipPath = $builder->build();
    @unlink($zipPath); // preview only needs the in-memory pages, not the zip file itself
} catch (\Throwable $e) {
    sendError(422, $e->getMessage());
}

$zipBin = $builder->getZipBin();
$pages  = pickPreviewPages($builder->getZipFiles(), $builder->getSectionLabels(), $zipBin);
if (!$pages) {
    sendError(404, 'No substantial content page was found to preview.');
}

echo json_encode(['pages' => array_map(fn($page) => formatPreviewPage($page, $zipBin), $pages)]);
exit;

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Samples up to MAX_PREVIEW_PAGES content pages, one per top-level section folder —
 * gives a representative spread across the book rather than several consecutive
 * pages from the same chapter. Front Matter and Back Matter sections (accessibility
 * statements, prefaces, bibliographies, etc.) are excluded.
 */
function pickPreviewPages(array $zipFiles, array $sectionLabels, array $zipBin): array
{
    $skipSections = frontAndBackMatterFolders($sectionLabels);

    $candidatesBySection = [];
    foreach ($zipFiles as $path => $content) {
        if (!str_ends_with($path, 'section-page.md')) {
            continue;
        }

        $section = sectionFolderFromPath($path);
        if (isset($skipSections[$section])) {
            continue;
        }

        $body = stripFrontmatter($content);
        if (strlen($body) < MIN_PREVIEW_PAGE_LENGTH) {
            continue; // skip thin/stub pages
        }

        $candidatesBySection[$section][] = ['path' => $path, 'content' => $content, 'body' => $body];
    }

    $selected = array_map(
        fn($candidates) => pickBestCandidate($candidates, $zipBin),
        $candidatesBySection
    );

    return array_slice(array_values($selected), 0, MAX_PREVIEW_PAGES);
}

// Prefers the first candidate in a section that actually shows an image — either a full
// remote URL (displays directly, most images by default since skip_images is on) or a local
// filename with a matching downloaded binary — so a preview reader is more likely to see real
// book content, not just text. Falls back to the first substantial candidate if none do.
function pickBestCandidate(array $candidates, array $zipBin): array
{
    foreach ($candidates as $candidate) {
        if (pageHasDisplayableImage($candidate['path'], $candidate['body'], $zipBin)) {
            return $candidate;
        }
    }
    return $candidates[0];
}

function pageHasDisplayableImage(string $pagePath, string $markdown, array $zipBin): bool
{
    foreach (extractImageRefs($markdown) as $ref) {
        if (preg_match('#^https?://#i', $ref)) {
            return true; // a full URL image — the browser loads it directly, no embedding needed
        }
    }
    return matchedImageFilenames($pagePath, $markdown, $zipBin) !== [];
}

function formatPreviewPage(array $page, array $zipBin): array
{
    preg_match('/title:\s*"(.+?)"/', $page['content'], $titleMatch);
    $markdown = stripFrontmatter($page['content']);
    return [
        'path'     => $page['path'],
        'title'    => $titleMatch[1] ?? 'Untitled',
        'markdown' => $markdown,
        'images'   => embeddedImagesForPage($page['path'], $markdown, $zipBin),
    ];
}

// Image sources referenced in $markdown, from either Markdown syntax (![alt](path)) or the
// raw HTML <figure><img src="..."> blocks the converter emits when figure_html is on
// (its default) — a Markdown-only regex misses that second, actually more common form.
function extractImageRefs(string $markdown): array
{
    $refs = [];
    if (preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', $markdown, $m)) {
        $refs = array_merge($refs, $m[1]);
    }
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $markdown, $m)) {
        $refs = array_merge($refs, $m[1]);
    }
    return array_unique($refs);
}

// Local (non-URL) image filenames referenced in $markdown that have matching binary data
// in $zipBin, small enough to embed inline. Shared by pickBestCandidate() (just needs to
// know if any exist) and embeddedImagesForPage() (needs the actual bytes).
function matchedImageFilenames(string $pagePath, string $markdown, array $zipBin): array
{
    $pageFolder = dirname($pagePath);

    $matched = [];
    foreach (extractImageRefs($markdown) as $filename) {
        if (preg_match('#^https?://#i', $filename)) {
            continue; // already a full URL — the browser can load it directly
        }
        $zipPath = $pageFolder . '/' . $filename;
        if (!isset($zipBin[$zipPath])) {
            continue; // no matching downloaded binary — left for the placeholder to handle
        }
        if (strlen($zipBin[$zipPath]) > MAX_PREVIEW_IMAGE_BYTES) {
            continue; // too large to embed inline
        }
        $matched[] = $filename;
    }
    return $matched;
}

// filename => "data:image/...;base64,..." for every image matchedImageFilenames() found —
// lets the preview show real images instead of a placeholder, no extra network request needed.
function embeddedImagesForPage(string $pagePath, string $markdown, array $zipBin): array
{
    $pageFolder = dirname($pagePath);
    $images = [];
    foreach (matchedImageFilenames($pagePath, $markdown, $zipBin) as $filename) {
        $bytes = $zipBin[$pageFolder . '/' . $filename];
        $images[$filename] = 'data:' . imageMimeType($filename) . ';base64,' . base64_encode($bytes);
    }
    return $images;
}

function imageMimeType(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'svg'         => 'image/svg+xml',
        'bmp'         => 'image/bmp',
        default       => 'application/octet-stream',
    };
}

// Removes the leading "--- ... ---" YAML block every page starts with.
function stripFrontmatter(string $pageContent): string
{
    return preg_replace('/^\s*---.*?---\s*/s', '', $pageContent);
}

// e.g. "pages/book-slug/02.section-2/01.some-page/section-page.md" -> "02.section-2"
function sectionFolderFromPath(string $path): string
{
    preg_match('#^pages/[^/]+/([^/]+)/#', $path, $match);
    return $match[1] ?? '';
}

// section-folder-name => true, for every section titled "Front Matter" or "Back Matter".
function frontAndBackMatterFolders(array $sectionLabels): array
{
    $folders = [];
    foreach ($sectionLabels as [$sectionNumber, $sectionTitle]) {
        if (in_array($sectionTitle, ['Front Matter', 'Back Matter'], true)) {
            $folders[sprintf('%02d.section-%d', $sectionNumber, $sectionNumber)] = true;
        }
    }
    return $folders;
}
