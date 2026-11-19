<?php
// Converts one Pressbooks export from the command line, with the web form's settings, and unzips the result:
//   php cli.php <book.html> <output folder> [options]
// The book file is the Pressbooks export (Export → Web → XHTML).
// Options (each changes one of the web form's defaults):
//   cover=cover.jpg     the book's cover image
//   helios              for Grav Helios Open Reader (default: Grav Open Publishing Space)
//   standard-markdown   Standard Markdown, without Grav shortcodes
//   with-images         download the book's images (default: they stay on Pressbooks)
//   no-figure-html      captioned images as Markdown instead of HTML figure/figcaption
//   embed-h5p           embed H5P activities
// The output folder then has pages/ (and conversion-notes.txt), as in the downloaded zip.
// Only from the command line: on the web server it does nothing.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

use PB\Parser;
use PB\ZipBuilder;

if (count($argv) < 3) {
    fwrite(STDERR, "Usage: php cli.php <book.html> <output folder> [cover=cover.jpg] [helios] [standard-markdown] [with-images] [no-figure-html] [embed-h5p]\n");
    exit(1);
}
$book = $argv[1];
$out = rtrim($argv[2], '/');
if (!is_file($book)) {
    fwrite(STDERR, "No such file: $book\n");
    exit(1);
}
$options = array_slice($argv, 3);

// the web form's defaults, then any changes asked for
$forHelios = in_array('helios', $options, true);
$portableMarkdown = in_array('standard-markdown', $options, true);
$skipImages = !in_array('with-images', $options, true);
$figureHtml = !in_array('no-figure-html', $options, true);
$embedH5p = in_array('embed-h5p', $options, true);
$cover = '';
foreach ($options as $option) {
    if (str_starts_with($option, 'cover=')) {
        $cover = substr($option, strlen('cover='));
    }
}
// as in convert.php: Open Publishing Space (and Standard Markdown) keep the book's folder in page addresses
$keepBookFolderInAddresses = !$forHelios || $portableMarkdown;

$parser = new Parser(file_get_contents($book));
if (!$parser->isValid()) {
    fwrite(STDERR, "No Pressbooks structure was found in $book (export it as Web → XHTML)\n");
    exit(1);
}
$builder = new ZipBuilder($parser, $skipImages, $figureHtml, $embedH5p, $portableMarkdown, $keepBookFolderInAddresses);
if ($cover !== '') {
    if (!is_file($cover)) {
        fwrite(STDERR, "No such cover image: $cover\n");
        exit(1);
    }
    $builder->setCoverImage(file_get_contents($cover), preg_replace('/[^a-z0-9._-]/i', '-', basename($cover)));
}
$zipPath = $builder->build();

// the result, unzipped into the output folder
$zip = new ZipArchive();
$zip->open($zipPath);
$zip->extractTo($out);
$zip->close();
unlink($zipPath);

echo "Converted \"{$parser->bookTitle}\" to $out/pages/\n";
