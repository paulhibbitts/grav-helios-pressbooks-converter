<?php
namespace PB;

class ZipBuilder
{
    private Parser           $parser;
    private ContentConverter $converter;
    private bool             $skipImages;
    private bool             $embedH5p;
    private bool             $portableMarkdown;

    public array $warnings       = [];
    public array $errors         = [];
    public array $imageFailures  = [];
    public int   $fileCount      = 0;
    public array $sectionLabels  = [];

    private array $allH5pEmbeds = [];

    private array   $zipFiles = []; // zipPath => string content
    private array   $zipBin   = []; // zipPath => binary content (images)

    private ?string $coverImageData     = null;
    private ?string $coverImageFilename = null;

    private bool   $sslHintShown  = false;
    private string $sectionLabel  = 'Section';
    private string $bookBase;

    // The start of every page address in the book: '' when the book's sections are
    // at the top of the site (Grav Helios Open Reader), or '/book-slug' when they
    // stay inside the book's folder (Grav Open Publishing Space and plain Grav)
    private string $bookRoute = '';

    public function setCoverImage(string $data, string $filename): void
    {
        $this->coverImageData     = $data;
        $this->coverImageFilename = $filename;
    }

    // Exposes the in-memory Markdown files build() already assembled — used by the
    // preview endpoint, which needs page content without writing an actual zip.
    public function getZipFiles(): array
    {
        return $this->zipFiles;
    }

    // [secNum, secTitle] pairs — used by the preview endpoint to identify and
    // exclude the Front Matter / Back Matter sections from sampled pages.
    public function getSectionLabels(): array
    {
        return $this->sectionLabels;
    }

    // zipPath => binary image data. Only populated when a remote image was successfully
    // downloaded (skip_images off) — used by the preview endpoint to embed real images
    // instead of a placeholder for the cases where the Markdown no longer has a usable URL.
    public function getZipBin(): array
    {
        return $this->zipBin;
    }

    public function __construct(Parser $parser, bool $skipImages = true, bool $figureHtml = true, bool $embedH5p = false, bool $portableMarkdown = false, bool $keepBookFolderInAddresses = false)
    {
        $this->parser           = $parser;
        $this->skipImages       = $skipImages;
        $this->embedH5p         = $embedH5p;
        $this->portableMarkdown = $portableMarkdown;
        $this->bookBase         = 'pages/10.' . $parser->bookSlug;

        // Grav Helios Open Reader shows a book's sections at the top of the site
        // (/section-1/...), but other Grav sites keep the book's folder in the
        // address (/book-slug/section-1/...), so links and redirects need it too
        if ($keepBookFolderInAddresses) {
            $this->bookRoute = '/' . $parser->bookSlug;
        }

        // Links between chapters use the same addresses
        $linkMap = [];
        foreach ($parser->linkMap as $id => $route) {
            $linkMap[$id] = $this->bookRoute . $route;
        }
        $this->converter = new ContentConverter($linkMap, $figureHtml, $embedH5p, $portableMarkdown);
    }

    // Build the zip and return the path to the temp file
    public function build(): string
    {
        $this->warnings      = array_merge($this->warnings, $this->parser->warnings);
        $this->sectionLabel  = $this->detectSectionLabel();

        $this->buildSectionList();
        $this->buildFrontMatter();
        $this->buildParts();
        $this->buildBackMatter();
        $this->buildConversionNotes();

        $this->warnings = array_merge($this->warnings, $this->converter->warnings);

        return $this->createZip();
    }

    // ── Section builders ──────────────────────────────────────────────────────

    private function buildSectionList(): void
    {
        $p = $this->parser;

        // Portable mode never writes a cover_image: frontmatter field to reference it (that
        // field's sibling cover_image_layout is Helios-specific), so skip fetching/bundling
        // the file entirely rather than shipping an orphaned, unreferenced image.
        $coverFilename = '';
        if (!$this->portableMarkdown) {
            if ($this->coverImageData !== null) {
                $coverFilename = $this->coverImageFilename;
                $this->zipBin[$this->bookBase . '/' . $coverFilename] = $this->coverImageData;
            } elseif ($p->bookCoverUrl) {
                $data = $this->downloadFile($p->bookCoverUrl);
                if ($data !== null) {
                    $coverFilename = basename(parse_url($p->bookCoverUrl, PHP_URL_PATH));
                    $this->zipBin[$this->bookBase . '/' . $coverFilename] = $data;
                }
            }
        }

        $authorsStr = $this->formatAuthors($p->bookAuthors);

        if ($this->portableMarkdown) {
            $lines = ['---', 'title: ' . Helpers::yamlStr($p->bookTitle), '---'];
        } else {
            $lines = ['---', 'title: ' . Helpers::yamlStr($p->bookTitle)];
            if ($p->bookSubtitle) {
                $lines[] = 'subtitle: ' . Helpers::yamlStr($p->bookSubtitle);
            }
            if ($coverFilename) {
                $lines[] = 'cover_image: ' . $coverFilename;
                $lines[] = 'cover_image_layout: sidebar';
            }
            if ($authorsStr) {
                $lines[] = 'authors: ' . Helpers::yamlStr($authorsStr);
                $lines[] = 'show_oer_attribution: true';
            }
            if ($p->bookLicense) {
                $lines[] = 'license: ' . Helpers::yamlStr($p->bookLicense);
            }
            if ($p->bookLicenseUrl) {
                $lines[] = 'license_url: ' . Helpers::yamlStr($p->bookLicenseUrl);
            }
            if ($this->sectionLabel !== 'Section') {
                $lines[] = 'section_label: ' . $this->sectionLabel;
                $lines[] = 'show_section_label: false';
            }
            if ($p->bookLicense && $authorsStr) {
                $parts = [$p->bookTitle . ' by ' . $authorsStr];
                if ($p->bookYear) {
                    $parts[] = '© ' . $p->bookYear;
                }
                $parts[] = 'is licensed under <a href="' . $p->bookLicenseUrl . '">' . $p->bookLicense . '</a>.';
                $lines[] = 'attribution_text: ' . Helpers::yamlStr(implode(' ', $parts));
            }
            $lines[] = '---';
        }

        // Build announcement block with book metadata and source link
        $metaParts = [];
        if ($authorsStr) {
            $metaParts[] = '**Authors:** ' . $authorsStr;
        }
        if ($p->bookLicense) {
            // In portable mode there's no frontmatter license_url field for a theme to read,
            // so the license link has to live in the body text itself to survive the export.
            $licenseText = ($this->portableMarkdown && $p->bookLicenseUrl)
                ? '[' . $p->bookLicense . '](' . $p->bookLicenseUrl . ')'
                : $p->bookLicense;
            $metaParts[] = '**License:** ' . $licenseText;
        }
        $pageCount = count($p->frontMatters)
                   + array_sum(array_map(fn($part) => count($part['chapters']), $p->parts))
                   + count($p->backMatters);
        if ($pageCount > 0) {
            $metaParts[] = '**' . $pageCount . ' pages**';
        }

        $announcementBody = '';
        if ($metaParts) {
            $announcementBody .= implode(' | ', $metaParts) . "\n\n";
        }
        if ($p->bookUrl) {
            $announcementBody .= '[View original on Pressbooks](' . $p->bookUrl . ")\n\n";
        }
        if ($this->portableMarkdown) {
            // Same attribution sentence normally carried in the (dropped) attribution_text
            // frontmatter field — kept in the body here so OER attribution survives the export.
            if ($p->bookLicense && $authorsStr) {
                $attrParts = [$p->bookTitle . ' by ' . $authorsStr];
                if ($p->bookYear) {
                    $attrParts[] = '© ' . $p->bookYear;
                }
                $licenseLink = $p->bookLicenseUrl ? '[' . $p->bookLicense . '](' . $p->bookLicenseUrl . ')' : $p->bookLicense;
                $attrParts[] = 'is licensed under ' . $licenseLink . '.';
                $announcementBody .= implode(' ', $attrParts) . "\n\n";
            }
        } else {
            $mediaItems = $this->embedH5p ? 'Video and audio' : 'Video, audio, and H5P content';
            $announcementBody .= "*[Grav Helios Open Reader](https://www.hibbittsdesign.org/Grav-Helios-Open-Reader-3560615470e080a79958c9c7dcd5d9a1) supports embedded video, audio, and H5P activities. {$mediaItems} in this converted book may appear as links rather than embedded content.*\n\n";
        }

        $annoTitle = $p->bookTitle . ' — Imported from Pressbooks';
        if ($this->portableMarkdown) {
            $body = "> [!IMPORTANT]\n> **" . $annoTitle . "**\n"
                  . $this->quoteBlock($announcementBody)
                  . "\n";
        } else {
            $annoTitleEsc = str_replace('"', '&quot;', $annoTitle);
            $body = "[announcement title=\"{$annoTitleEsc}\"]\n\n{$announcementBody}[/announcement]\n";
        }
        if ($p->bookAbout) {
            $body .= "\n" . trim($p->bookAbout) . "\n";
        }

        $content = implode("\n", $lines) . "\n\n" . $body;
        $this->addFile($this->bookBase . '/section-list.md', $content);
    }

    private function buildFrontMatter(): void
    {
        $pages = [];
        foreach ($this->parser->frontMatters as $fm) {
            try {
                $content = $this->converter->convert($fm['html']);
            } catch (\Exception $e) {
                $this->errors[] = "failed to convert front-matter page \"{$fm['title']}\": " . $e->getMessage();
                $content = '> **Conversion error:** ' . $e->getMessage() . "\n";
            }
            $this->harvestH5pEmbeds($fm['title']);
            $pages[] = [$fm['title'], $content];
        }

        $this->writeSection(
            1, 'front-matter', 'Front Matter',
            'Accessibility, about, preface, and acknowledgements.',
            null, null, $pages,
            $this->sectionLabel !== 'Section' ? '' : null
        );
    }

    private function buildParts(): void
    {
        $secNum = 1;
        foreach ($this->parser->parts as $part) {
            $secNum++;

            $partBody = '';
            if ($part['html']) {
                try {
                    $partBody = $this->converter->convert($part['html']);
                } catch (\Exception $e) {
                    $this->errors[] = "failed to convert part intro \"{$part['title']}\": " . $e->getMessage();
                }
            }

            $objectivesText = null;
            // Only extract from the first chapter when the part body doesn't already
            // contain a [objectives] block (avoids duplicating objectives on the section page)
            if (!empty($part['chapters']) && strpos($partBody ?? '', '[objectives]') === false) {
                $objectivesText = $this->converter->extractObjectives($part['chapters'][0]['html']);
            }

            $pages = [];
            foreach ($part['chapters'] as $ch) {
                try {
                    $content = $this->converter->convert($ch['html']);
                } catch (\Exception $e) {
                    $this->errors[] = "failed to convert chapter \"{$ch['title']}\": " . $e->getMessage();
                    $content = '> **Conversion error:** ' . $e->getMessage() . "\n";
                }
                $this->harvestH5pEmbeds($ch['title']);
                $pages[] = [$ch['title'], $content];
            }

            $slugTitle     = $part['title'];
            $labelOverride = null;
            if (preg_match('/^(?:Module|Chapter|Part|Unit)\s+\d+[.:]?\s*/i', $part['title'], $m)) {
                $slugTitle = trim(substr($part['title'], strlen($m[0])));
            } elseif ($this->sectionLabel !== 'Section' && preg_match('/^(\d+)[.:]\s+/i', $part['title'], $m)) {
                $slugTitle = trim(substr($part['title'], strlen($m[0])));
            } elseif (preg_match('/^(Appendix\s+\w+)(?:[.:]\s*|\s+)/i', $part['title'], $m)) {
                $labelOverride = '';
                $slugTitle     = trim(substr($part['title'], strlen($m[0])));
            }

            $this->writeSection(
                $secNum, Helpers::slugify($slugTitle ?: $part['title']), $part['title'],
                null, $objectivesText, $partBody, $pages,
                $labelOverride
            );
        }
    }

    private function buildBackMatter(): void
    {
        if (!$this->parser->backMatters) {
            return;
        }

        $secNum = 1 + count($this->parser->parts) + 1;
        $pages  = [];

        foreach ($this->parser->backMatters as $bm) {
            try {
                $content = $this->converter->convert($bm['html']);
            } catch (\Exception $e) {
                $this->errors[] = "failed to convert back-matter page \"{$bm['title']}\": " . $e->getMessage();
                $content = '> **Conversion error:** ' . $e->getMessage() . "\n";
            }
            $this->harvestH5pEmbeds($bm['title']);
            $pages[] = [$bm['title'], $content];
        }

        $this->writeSection(
            $secNum, 'back-matter', 'Back Matter',
            'Appendices, bibliography, and versioning history.',
            null, null, $pages,
            $this->sectionLabel !== 'Section' ? '' : null
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    // Prefixes every line of $text with "> " so it renders as a Markdown blockquote (blank
    // lines become a bare ">" so the blockquote doesn't visually break). Whitespace-only
    // lines count as blank too, and consecutive blank lines collapse to a single ">" —
    // otherwise "&nbsp;" spacer content (common in Pressbooks/WordPress prose) survives as
    // a run of stray bare ">" lines.
    private function quoteBlock(string $text): string
    {
        $lines  = explode("\n", trim($text));
        $quoted = [];
        $prevBlank = false;
        foreach ($lines as $line) {
            $isBlank = trim($line) === '';
            if ($isBlank && $prevBlank) continue;
            $quoted[] = $isBlank ? '>' : '> ' . $line;
            $prevBlank = $isBlank;
        }
        return implode("\n", $quoted);
    }

    private function detectSectionLabel(): string
    {
        foreach ($this->parser->parts as $part) {
            if (preg_match('/^(Module|Chapter|Part|Unit)\s+\d+[.:]?\s*/i', $part['title'], $m)) {
                return ucfirst(strtolower($m[1]));
            }
        }
        return 'Section';
    }

    // ── Core write helper ─────────────────────────────────────────────────────

    private function writeSection(
        int     $secNum,
        string  $secSlug,
        string  $secTitle,
        ?string $secDesc,
        ?string $objectivesText,
        ?string $secBody,
        array   $pages,
        ?string $sectionLabelOverride = null
    ): void {
        $secFolder = sprintf('%s/%02d.section-%d', $this->bookBase, $secNum, $secNum);

        $fm = ['---', 'title: ' . Helpers::yamlStr($secTitle)];
        if (!$this->portableMarkdown) {
            if ($sectionLabelOverride !== null) {
                $fm[] = 'section_label: ' . Helpers::yamlStr($sectionLabelOverride);
            }
            if ($secDesc) {
                $fm[] = 'description: ' . Helpers::yamlStr($secDesc);
            }
            if ($objectivesText) {
                $fm[] = 'learning_objectives: ' . Helpers::yamlStr($objectivesText);
            }
        }
        // redirect: is core Grav functionality (not Helios-specific) — a body-less section
        // with no redirect would otherwise render blank in any Grav site, portable or not.
        if (!$secBody && $pages) {
            $firstSlug = Helpers::slugify($pages[0][0]);
            $fm[] = 'redirect: ' . $this->bookRoute . '/section-' . $secNum . '/' . $firstSlug;
        }
        $fm[] = '---';

        $bodyContent = $secBody ? $this->processImages($secBody, $secFolder) : '';
        $this->addFile($secFolder . '/section.md', implode("\n", $fm) . "\n\n" . trim($bodyContent) . "\n");

        $this->sectionLabels[] = [$secNum, $secTitle];

        $usedSlugs = [];
        foreach ($pages as $pageNum => [$pageTitle, $pageContent]) {
            $pageNum++;
            $pageSlug    = Helpers::uniqueSlug($pageTitle, $usedSlugs);
            $pageFolder  = sprintf('%s/%02d.%s', $secFolder, $pageNum, $pageSlug);
            $pageContent = $this->processImages($pageContent, $pageFolder);
            $pageFm      = ['---', 'title: ' . Helpers::yamlStr($pageTitle), '---'];
            $this->addFile($pageFolder . '/section-page.md', implode("\n", $pageFm) . "\n\n" . $pageContent . "\n");
        }
    }

    // ── Image handling ────────────────────────────────────────────────────────

    private function processImages(string $markdown, string $zipFolder): string
    {
        if ($this->skipImages) {
            return $markdown;
        }
        return preg_replace_callback(
            '/!\[([^\]]*)\]\((https?:\/\/(?:[^()]+|\((?:[^()]+|\([^()]*\))*\))+)\)/',
            function ($m) use ($zipFolder) {
                $alt      = $m[1];
                $url      = $m[2];
                $filename = basename(parse_url($url, PHP_URL_PATH));
                if (!$filename) {
                    return $m[0];
                }
                $zipPath = $zipFolder . '/' . $filename;
                if (!isset($this->zipBin[$zipPath])) {
                    $data = $this->downloadFile($url);
                    if ($data !== null) {
                        $this->zipBin[$zipPath] = $data;
                    }
                }
                return isset($this->zipBin[$zipPath])
                    ? "![$alt]($filename)"
                    : $m[0];
            },
            $markdown
        );
    }

    private function downloadFile(string $url): ?string
    {
        // SSRF guard: reject private/loopback/link-local addresses
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            $this->imageFailures[] = $url;
            return null;
        }
        $ip = gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->warnings[]      = "Skipped image from private/internal address: $host";
            $this->imageFailures[] = $url;
            return null;
        }

        $opts = [
            'http' => [
                'method'          => 'GET',
                'header'          => 'User-Agent: Mozilla/5.0',
                'timeout'         => 15,
                'follow_location' => 1,
                'max_length'      => 20 * 1024 * 1024, // 20 MB cap per image
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ];

        // Try with SSL verification, fall back without
        foreach ([true, false] as $verify) {
            $opts['ssl']['verify_peer']      = $verify;
            $opts['ssl']['verify_peer_name'] = $verify;
            $ctx  = stream_context_create($opts);
            $data = @file_get_contents($url, false, $ctx);
            if ($data !== false) {
                return $data;
            }
            if ($verify && !$this->sslHintShown) {
                $this->sslHintShown = true;
                $this->warnings[]   = 'SSL certificate error — retrying without verification';
            }
        }
        $this->imageFailures[] = $url;
        return null;
    }

    private function buildConversionNotes(): void
    {
        $p = $this->parser;

        $chapterCount = array_sum(array_map(fn($part) => count($part['chapters']), $p->parts));
        $pageCount    = count($p->frontMatters) + $chapterCount + count($p->backMatters);

        $title = 'Pressbooks Conversion Notes';
        $lines = [
            $title,
            str_repeat('=', strlen($title)),
            'Generated: ' . date('Y-m-d'),
            '',
            'Book Metadata',
            str_repeat('-', strlen('Book Metadata')),
            '  Title:   ' . $p->bookTitle,
        ];
        if ($p->bookAuthors) {
            $lines[] = '  Authors: ' . $this->formatAuthors($p->bookAuthors);
        }
        if ($p->bookLicense) {
            $lines[] = '  License: ' . $p->bookLicense;
        }
        if ($p->bookUrl) {
            $lines[] = '  Source:  ' . $p->bookUrl;
        }
        $lines[] = '';
        $lines[] = 'Structure';
        $lines[] = '---------';
        $lines[] = '  Front matter:   ' . count($p->frontMatters) . ' page(s)';
        $lines[] = '  Parts/sections: ' . count($p->parts);
        $lines[] = '  Chapters:       ' . $chapterCount . ' page(s)';
        $lines[] = '  Back matter:    ' . count($p->backMatters) . ' page(s)';
        $lines[] = '  Total:          ' . $pageCount . ' page(s)';
        $lines[] = '';
        $lines[] = 'Next Steps';
        $lines[] = '----------';
        if ($this->portableMarkdown) {
            $lines[] = '  1. Copy the book folder from inside the extracted pages folder into';
            $lines[] = '     any Grav site\'s user/pages/ directory (no Helios plugin required),';
            $lines[] = '     or adapt it for another Markdown-based platform';
            $lines[] = '  2. Review this file for any warnings or manual fixes needed';
        } elseif ($this->bookRoute !== '') {
            $lines[] = '  1. Copy the book folder from inside the extracted pages folder into';
            $lines[] = '     your Grav Open Publishing Space site\'s user/pages/ directory';
            $lines[] = '  2. Review this file for any warnings or manual fixes needed';
        } else {
            $lines[] = '  1. Copy the book folder from inside the extracted pages folder into';
            $lines[] = '     your Grav Helios Open Reader installation\'s user/pages/ directory';
            $lines[] = '  2. Review this file for any warnings or manual fixes needed';
        }
        $lines[] = '';
        $lines[] = 'Conversion Settings';
        $lines[] = '-------------------';
        if ($this->portableMarkdown) {
            $formatText = 'Standard Markdown (no Grav-specific shortcodes)';
        } elseif ($this->bookRoute !== '') {
            $formatText = 'Grav Open Publishing Space (shortcodes + full frontmatter)';
        } else {
            $formatText = 'Grav Helios Open Reader (shortcodes + full frontmatter)';
        }
        $lines[] = '  Format:  ' . $formatText;
        $lines[] = '  Images:  ' . ($this->skipImages
            ? 'kept as remote URLs — may break if source site goes offline'
            : 'downloaded and bundled in ZIP');
        if ($this->embedH5p) {
            $h5pText = $this->portableMarkdown
                ? 'embedded via a plain <iframe> + H5P\'s own resizer script'
                : 'embedded via [h5p] shortcode';
            if ($this->allH5pEmbeds) {
                $h5pText .= ' (see H5P Embeds section below)';
            }
        } else {
            $h5pText = 'linked to original source';
        }
        $lines[] = '  H5P:     ' . $h5pText;
        $lines[] = '  YouTube: ' . ($this->portableMarkdown
            ? 'embedded via a plain <iframe> (YouTube\'s own embed code)'
            : 'embedded via [youtube] shortcode');

        if ($this->warnings) {
            $lines[] = '';
            $lines[] = 'Warnings';
            $lines[] = '--------';
            foreach ($this->warnings as $w) {
                $lines[] = '  - ' . $w;
            }
        }
        if ($this->errors) {
            $lines[] = '';
            $lines[] = 'Errors';
            $lines[] = '------';
            foreach ($this->errors as $e) {
                $lines[] = '  - ' . $e;
            }
        }

        $lines[] = '';
        $lines[] = 'Known Limitations';
        $lines[] = '-----------------';
        $lines[] = '  - Footnotes/endnotes may appear as raw HTML — review in Grav admin';
        $lines[] = '  - Complex tables may need manual cleanup';
        $lines[] = '  - Math/LaTeX rendering depends on your Grav MathJax plugin configuration';
        $lines[] = '  - Other oEmbed content (Vimeo, Google Slides, etc.) links to the original Pressbooks page';
        if ($this->portableMarkdown) {
            $lines[] = '  - Internal cross-reference links use Grav-style absolute paths (e.g. /section-2/some-page)';
            $lines[] = '    and will not resolve outside a Grav site — check/update these manually if publishing';
            $lines[] = '    to another platform';
        }
        $lines[] = '';
        $lines[] = 'Media Support';
        $lines[] = '-------------';
        if ($this->portableMarkdown) {
            $lines[] = '  YouTube videos are embedded as real <iframe> elements (YouTube\'s own embed';
            $lines[] = '  code). H5P activities are embedded the same way when "Embed H5P activities"';
            $lines[] = '  is enabled; otherwise they are left as links to the original source.';
        } else {
            $lines[] = '  Helios Open Reader supports embedded video, audio, and H5P activities,';
            $lines[] = '  but converted Pressbooks content may contain links to these items rather';
            $lines[] = '  than actual embeds. Review each page and replace links with the appropriate';
            $lines[] = '  shortcode where needed (e.g. [h5p url="..."] for H5P activities).';
        }

        if ($this->allH5pEmbeds) {
            $lines[] = '';
            $lines[] = 'H5P Embeds';
            $lines[] = '----------';
            $lines[] = 'Open each embed URL in a browser to verify it shows the activity before installing.';
            $currentPage = null;
            foreach ($this->allH5pEmbeds as $entry) {
                if ($entry['page'] !== $currentPage) {
                    $lines[]     = '';
                    $lines[]     = $entry['page'];
                    $currentPage = $entry['page'];
                }
                $lines[] = '  Embed:  ' . $entry['embed'];
                $lines[] = '  Source: ' . $entry['source'];
            }
        }

        $this->addFile('conversion-notes.txt', implode("\n", $lines) . "\n");
    }

    // ── Zip assembly ──────────────────────────────────────────────────────────

    private function addFile(string $path, string $content): void
    {
        $this->zipFiles[$path] = $content;
        $this->fileCount++;
    }

    private function createZip(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension is not available on this server.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pb_convert_');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create temporary zip file.');
        }

        foreach ($this->zipFiles as $path => $content) {
            $zip->addFromString($path, $content);
        }
        foreach ($this->zipBin as $path => $data) {
            $zip->addFromString($path, $data);
        }

        // Add failed image URLs as a text file if any
        if ($this->imageFailures) {
            $zip->addFromString($this->bookBase . '/images-not-downloaded.txt', implode("\n", $this->imageFailures) . "\n");
        }

        $zip->close();
        return $tmp;
    }

    // ── Misc helpers ──────────────────────────────────────────────────────────

    private function harvestH5pEmbeds(string $pageTitle): void
    {
        foreach ($this->converter->h5pEmbeds as $embed) {
            $this->allH5pEmbeds[] = ['page' => $pageTitle, 'source' => $embed['source'], 'embed' => $embed['embed']];
        }
    }

    private function formatAuthors(array $authors): string
    {
        if (count($authors) > 1) {
            return implode(', ', array_slice($authors, 0, -1)) . ' and ' . end($authors);
        }
        return $authors[0] ?? '';
    }
}
