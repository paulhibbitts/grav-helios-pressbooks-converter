<?php
// Conversion tests: converts each sample book in tests/samples and compares the result with the saved one in
// tests/expected. Run from the converter's folder, before committing a change:  php tests/check.php
// Every check should say "passed"; anything else lists the files that came out differently.
// When the output changes on purpose, look at the differences, then save the new output:  php tests/check.php --update
//
// The tests work offline: images aren't downloaded, and the converter isn't allowed to fetch anything from the
// web (such as part intros from a book's site), so a website being slow or changed can't make a test fail.

if (PHP_SAPI !== 'cli') {
    exit; // command line only – nothing runs if this file is ever opened in a browser
}

// Run again without web access (allow_url_fopen can only be switched off when PHP starts)
if (ini_get('allow_url_fopen')) {
    $command = 'php -d allow_url_fopen=0 ' . escapeshellarg(__FILE__);
    if (in_array('--update', $argv, true)) {
        $command .= ' --update';
    }
    passthru($command, $status);
    exit($status);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use PB\Parser;
use PB\ZipBuilder;

// Each check: a sample book, an optional cover image (the form's cover upload), and the form's options.
// The first one uses the form's defaults; without a cover, the book's own cover can't be downloaded offline.
$checks = [
    'udl-open-publishing' => ['book' => 'udl-for-idea.html', 'cover' => 'udl-for-idea-cover.png', 'destination' => 'open-publishing', 'embedH5p' => false, 'portable' => false],
    'udl-helios' => ['book' => 'udl-for-idea.html', 'destination' => 'helios', 'embedH5p' => false, 'portable' => false],
    'udl-standard-markdown' => ['book' => 'udl-for-idea.html', 'destination' => 'open-publishing', 'embedH5p' => false, 'portable' => true],
    'social-psychology-h5p' => ['book' => 'principles-of-social-psychology.html', 'cover' => 'principles-of-social-psychology-cover.jpg', 'destination' => 'open-publishing', 'embedH5p' => true, 'portable' => false],
];

$update = in_array('--update', $argv, true);
$failed = 0;

foreach ($checks as $name => $check) {
    $files = convertBook(__DIR__ . '/samples/' . $check['book'], $check);
    $expectedFolder = __DIR__ . '/expected/' . $name;
    if ($update) {
        saveFiles($files, $expectedFolder);
        echo "saved   $name (" . count($files) . " files)\n";
        continue;
    }
    $differences = compareWithSaved($files, $expectedFolder);
    if (count($differences) === 0) {
        echo "passed  $name\n";
        continue;
    }
    $failed++;
    echo "FAILED  $name (" . count($differences) . " differences)\n";
    foreach (array_slice($differences, 0, 10) as $difference) {
        echo "          $difference\n";
    }
}

if ($update) {
    echo "\nSaved the new output in tests/expected – check its changes before committing.\n";
    exit(0);
}
if ($failed === 0) {
    echo "\nAll checks passed.\n";
    exit(0);
}
echo "\n$failed check(s) failed. If the change is intended, run: php tests/check.php --update\n";
exit(1);

// ---- helpers ----

// Converts one book with the same steps as convert.php, and returns its files (path in the zip => contents).
// Two things change from day to day or year to year, so they're replaced with a fixed word: the date in
// conversion-notes.txt, and the book's address name, which ends with the current year when the title has no number
// (e.g. universal-design-for-2026 → BOOK-SLUG).
function convertBook(string $bookFile, array $check): array
{
    $parser = new Parser(file_get_contents($bookFile));
    $keepBookFolder = $check['destination'] === 'open-publishing' || $check['portable'];
    $builder = new ZipBuilder($parser, true, true, $check['embedH5p'], $check['portable'], $keepBookFolder);
    if (isset($check['cover'])) {
        $builder->setCoverImage(file_get_contents(__DIR__ . '/samples/' . $check['cover']), $check['cover']);
    }
    unlink($builder->build());

    // the Markdown and text files, then any images (here, the uploaded cover)
    $files = [];
    foreach (array_merge($builder->getZipFiles(), $builder->getZipBin()) as $path => $text) {
        $text = preg_replace('/^Generated: \d{4}-\d{2}-\d{2}$/m', 'Generated: DATE', $text);
        $files[str_replace($parser->bookSlug, 'BOOK-SLUG', $path)] = str_replace($parser->bookSlug, 'BOOK-SLUG', $text);
    }
    ksort($files);
    return $files;
}

// The files that are missing, added or different, compared with the saved output
function compareWithSaved(array $files, string $folder): array
{
    if (!is_dir($folder)) {
        return ['no saved output yet – run: php tests/check.php --update'];
    }
    $saved = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        $saved[substr($item->getPathname(), strlen($folder) + 1)] = file_get_contents($item->getPathname());
    }
    $differences = [];
    foreach (array_diff_key($saved, $files) as $path => $text) {
        $differences[] = "missing: $path";
    }
    foreach (array_diff_key($files, $saved) as $path => $text) {
        $differences[] = "added: $path";
    }
    foreach (array_intersect_key($files, $saved) as $path => $text) {
        if ($text !== $saved[$path]) {
            $differences[] = "changed: $path";
        }
    }
    return $differences;
}

// Replaces a check's saved output with these files
function saveFiles(array $files, string $folder): void
{
    if (is_dir($folder)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
    }
    foreach ($files as $path => $text) {
        $target = $folder . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        file_put_contents($target, $text);
    }
}
