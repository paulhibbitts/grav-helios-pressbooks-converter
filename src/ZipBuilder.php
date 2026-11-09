<?php
namespace PB;

class ZipBuilder
{
    private Parser           $parser;
    private ContentConverter $converter;
    private bool             $skipImages;
    private bool             $embedH5p;
    private bool             $portableMarkdown;
    private bool             $captionedImagesAsMarkdown = false;

    public array $warnings       = [];
    public array $errors         = [];
    public array $imageFailures  = [];
    public int   $fileCount      = 0;
    public array $sectionLabels  = [];

    private array $allH5pEmbeds = [];

    // Pages with a table that wasn't in the Pressbooks export, only its WordPress shortcode
    // (such as [table id=5] from the TablePress plugin): page title => number of tables
    private array $tablePluginPages = [];

    // The book's glossary (Grav destinations), from its Glossary back matter page, which becomes an unnumbered
    // Glossary section: ['index' => back matter index, 'id', 'title', 'html' => its other content,
    // 'entries' => [[term, definition HTML], ...]] (definitions become Markdown in writeGlossarySection())
    private ?array $glossary = null;

    private array   $zipFiles = []; // zipPath => string content
    private array   $zipBin   = []; // zipPath => binary content (images)

    private ?string $coverImageData     = null;
    private ?string $coverImageFilename = null;

    private bool   $sslHintShown  = false;

    // How many part intro pages came from the book on Pressbooks, as the export left them out
    private int    $partIntrosFetched = 0;

    // Why the last web request failed, for the conversion notes
    private string $lastFetchError = '';
    private string $sectionLabel  = 'Section';
    private string $bookBase;

    // The start of every page address in the book: '' when the book's sections are
    // at the top of the site (Grav Helios Open Reader), or '/book-slug' when they
    // stay inside the book's folder (Grav Open Publishing Space and plain Grav)
    private string $bookRoute = '';

    // A shorter label for the site's menu when the book's title is long (Grav Open
    // Publishing Space only), or '' to let the menu show the full title
    private string $menuLabel = '';

    // Titles longer than this get a shorter menu label, when one can be found
    private const MENU_LABEL_MAX_LENGTH = 35;

    // A label still over the limit is used only when it's at least this much shorter
    private const MENU_LABEL_MIN_SAVING = 10;

    // punctuation trimmed between a glossary term and its definition ("Schema: …", "Schema – …")
    private const TERM_PUNCTUATION = ' :–—-';

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

        // In Grav Open Publishing Space the book is one item in the site's menu,
        // so a long title gets a shorter menu label (the page keeps its full title)
        if ($keepBookFolderInAddresses && !$portableMarkdown) {
            $this->menuLabel = $this->shortMenuLabel($parser->bookTitle);
        }

        // Links between chapters use the same addresses
        $linkMap = [];
        foreach ($parser->linkMap as $id => $route) {
            $linkMap[$id] = $this->bookRoute . $route;
        }
        // Captioned images written as HTML <figure> keep their remote address: the image download
        // step only finds Markdown images, and Grav doesn't adjust image addresses in raw HTML (a
        // downloaded image there would need its full address). So when images are downloaded,
        // captioned images use the Markdown form instead - the image, then its caption in italics -
        // and are downloaded like any other image
        if ($figureHtml && !$skipImages) {
            $figureHtml = false;
            $this->captionedImagesAsMarkdown = true;
        }
        if (!$portableMarkdown) {
            $this->glossary = $this->findGlossary();
            if ($this->glossary && $this->glossary['id']) {
                $linkMap[$this->glossary['id']] = $this->bookRoute . '/section-' . $this->glossarySectionNumber();
            }
        }

        $this->converter = new ContentConverter($linkMap, $figureHtml, $embedH5p, $portableMarkdown);
        if ($this->glossary) {
            $this->converter->setGlossaryTerms(array_column($this->glossary['entries'], 0));
        }
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
            // Grav's menu shows menu: in place of title: when it's set
            if ($this->menuLabel !== '') {
                $lines[] = 'menu: ' . Helpers::yamlStr($this->menuLabel);
            }
            if ($p->bookSubtitle) {
                $lines[] = 'subtitle: ' . Helpers::yamlStr($p->bookSubtitle);
            }
            if ($coverFilename) {
                $lines[] = 'cover_image: ' . $coverFilename;
                // Helios Open Reader shows the cover in its sidebar; Open Publishing Space (which keeps the
                // book's folder in its addresses) shows a small cover beside the book's details
                $coverLayout = 'sidebar';
                if ($this->bookRoute !== '') {
                    $coverLayout = 'small';
                }
                $lines[] = 'cover_image_layout: ' . $coverLayout;
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
            // names the site the book is going into (Open Publishing Space or Helios Open Reader)
            $mediaItems = $this->embedH5p ? 'Video and audio' : 'Video, audio, and H5P content';
            $destinationLink = '[' . $this->destinationName() . '](' . $this->destinationUrl() . ')';
            $announcementBody .= "*{$destinationLink} supports embedded video, audio, and H5P activities. {$mediaItems} in this converted book may appear as links rather than embedded content.*\n\n";
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
            $this->noteTablePluginShortcodes($fm['title'], $content);
            $pages[] = [$fm['title'], $content];
        }

        $this->writeSection(
            1, 'front-matter', 'Front Matter',
            'Accessibility, about, preface, and acknowledgements.',
            null, null, $pages,
            $this->sectionLabel !== 'Section' ? '' : null,
            false
        );
    }

    private function buildParts(): void
    {
        $missingIntros = $this->fetchMissingPartIntros();

        $secNum = 1;
        foreach ($this->parser->parts as $part) {
            $secNum++;

            if (!$part['html'] && isset($missingIntros[$part['id']])) {
                $part['html'] = $missingIntros[$part['id']];
            }

            $partBody = '';
            if ($part['html']) {
                try {
                    $partBody = $this->converter->convert($part['html']);
                } catch (\Exception $e) {
                    $this->errors[] = "failed to convert part intro \"{$part['title']}\": " . $e->getMessage();
                }
                $this->harvestH5pEmbeds($part['title']);
                $this->noteTablePluginShortcodes($part['title'], $partBody);
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
                $this->noteTablePluginShortcodes($ch['title'], $content);
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
                $labelOverride,
                $labelOverride !== '' // an appendix ($labelOverride '') isn't numbered
            );
        }
    }

    // Some Pressbooks exports leave out every part's own page (its intro text and boxes), keeping
    // only the part's chapters. The book on Pressbooks still has them, so get them from the
    // book's REST API (wp-json/pressbooks/v2/parts). Returns part id => part HTML, for the parts
    // with no intro in the export; a part that isn't found keeps its redirect to its first chapter
    private function fetchMissingPartIntros(): array
    {
        $bookUrl = $this->parser->bookUrl;
        $missingIds = [];
        foreach ($this->parser->parts as $part) {
            if (!$part['html'] && $part['chapters']) {
                $missingIds[] = $part['id'];
            }
        }
        if (!$missingIds || !$bookUrl) {
            return [];
        }

        $this->lastFetchError = '';
        $json = $this->fetchText($bookUrl . 'wp-json/pressbooks/v2/parts?per_page=100');
        $partsFromApi = $json !== null ? json_decode($json, true) : null;
        if (!is_array($partsFromApi)) {
            if ($json === null) {
                $reason = $this->lastFetchError ?: 'no response';
            } else {
                $reason = 'the response wasn\'t a list of parts (starts: ' . mb_substr(trim(strip_tags($json)), 0, 80) . ')';
            }
            $this->warnings[] = 'the Pressbooks export has no intro pages for its parts, and they couldn\'t be'
                              . ' fetched from ' . $bookUrl . ' (' . $reason . ') – each section opens its first page instead';
            return [];
        }

        // part slug => its page's HTML
        $htmlBySlug = [];
        foreach ($partsFromApi as $apiPart) {
            $html = trim($apiPart['content']['rendered'] ?? '');
            if (isset($apiPart['slug']) && $html !== '') {
                $postUrl = $bookUrl . '?p=' . ($apiPart['id'] ?? '');
                $html = $this->h5pAsExportPlaceholders($html, $postUrl);
                $htmlBySlug[$apiPart['slug']] = $this->bookLinksAsExportLinks($html, $bookUrl);
            }
        }

        $intros = [];
        foreach ($missingIds as $id) {
            // part ids are "part-{slug}", or "part-{slug}-wrapper" in exports without part pages
            $slug = preg_replace('/^part-|-wrapper$/', '', $id);
            if (isset($htmlBySlug[$slug])) {
                $intros[$id] = $htmlBySlug[$slug];
            }
        }
        $this->partIntrosFetched = count($intros);
        return $intros;
    }

    // On the web, an H5P activity is an empty iframe that H5P's script fills in, which would be
    // lost in conversion. Swap each one for the link box the export uses instead, such as
    // <a href="https://host/book/?p=17#h5p-1">, so it's converted the same way as in chapters
    private function h5pAsExportPlaceholders(string $html, string $postUrl): string
    {
        return preg_replace_callback(
            '/<div id="h5p-(\d+)"[^>]*>.*?<\/iframe>\s*<\/div>\s*<\/div>/s',
            function ($m) use ($postUrl) {
                $title = preg_match('/<iframe[^>]*\btitle="([^"]*)"/', $m[0], $t) ? $t[1] : 'Interactive Activity';
                $url   = $postUrl . '#h5p-' . $m[1];
                return '<div class="textbox interactive-content"><p><a href="' . $url . '" title="' . $title . '">'
                     . $url . '</a></p></div>';
            },
            $html
        );
    }

    // On the web, links to the book's own pages are full addresses, such as
    // https://host/book/chapter/udl-guidelines/, where the export uses the page's id
    // (#chapter-udl-guidelines). Swap each one for its id, so it's converted to the address
    // of the converted page, like links in chapters. Links to a page not in the book stay as they are
    private function bookLinksAsExportLinks(string $html, string $bookUrl): string
    {
        // the book's address with or without https, then the page type and its slug
        $bookAddress = preg_quote(preg_replace('#^https?://#', '', $bookUrl), '#');
        $pattern = '#href="(?:https?:)?//' . $bookAddress
                 . '(chapter|front-matter|back-matter|part)/([^/"\#?]+)/?(?:\#[^"]*)?"#';
        return preg_replace_callback(
            $pattern,
            function ($m) {
                $id = $m[1] . '-' . $m[2];
                // parts in exports without part pages have "-wrapper" on the end of their id
                foreach ([$id, $id . '-wrapper'] as $candidate) {
                    if (isset($this->parser->linkMap[$candidate])) {
                        return 'href="#' . $candidate . '"';
                    }
                }
                return $m[0];
            },
            $html
        );
    }

    // Get a web page or API response as text, or null if it can't be fetched
    private function fetchText(string $url): ?string
    {
        if (!$this->isPublicAddress($url)) {
            return null;
        }
        return $this->httpGet($url, 5 * 1024 * 1024);
    }

    private function buildBackMatter(): void
    {
        $backMatters = $this->parser->backMatters;
        if ($this->glossary) {
            unset($backMatters[$this->glossary['index']]); // it becomes its own Glossary section
        }
        if ($backMatters) {
            $this->buildBackMatterSection($backMatters);
        }
        if ($this->glossary) {
            $this->writeGlossarySection();
        }
    }

    private function buildBackMatterSection(array $backMatters): void
    {
        $secNum = 1 + count($this->parser->parts) + 1;
        $pages  = [];

        foreach ($backMatters as $bm) {
            try {
                $content = $this->converter->convert($bm['html']);
            } catch (\Exception $e) {
                $this->errors[] = "failed to convert back-matter page \"{$bm['title']}\": " . $e->getMessage();
                $content = '> **Conversion error:** ' . $e->getMessage() . "\n";
            }
            $this->harvestH5pEmbeds($bm['title']);
            $this->noteTablePluginShortcodes($bm['title'], $content);
            $pages[] = [$bm['title'], $content];
        }

        $this->writeSection(
            $secNum, 'back-matter', 'Back Matter',
            'Appendices, bibliography, and versioning history.',
            null, null, $pages,
            $this->sectionLabel !== 'Section' ? '' : null,
            false
        );
    }

    // ── Glossary ──────────────────────────────────────────────────────────────

    // The book's Glossary back matter page, with its terms read from it, or null when there isn't one
    private function findGlossary(): ?array
    {
        foreach ($this->parser->backMatters as $index => $bm) {
            if (!preg_match('/^\s*glossary\b/i', $bm['title'])) {
                continue;
            }
            [$entries, $otherHtml] = $this->glossaryEntries($bm['html']);
            if (!$entries) {
                $this->warnings[] = "a Glossary page (\"{$bm['title']}\") was found, but no terms could be read from it, so it's kept as a back matter page";
                continue; // a later page may hold the terms
            }

            return [
                'index'   => $index,
                'id'      => $bm['id'],
                'title'   => $bm['title'],
                'html'    => $otherHtml,
                'entries' => $entries,
            ];
        }

        return null;
    }

    // Terms and definitions from a glossary page: paragraphs that start with the term in bold
    // (<p><strong>Term</strong> Definition</p>), or a definition list (<dl>).
    // Returns [entries ([term, definition HTML]), the page's other HTML].
    private function glossaryEntries(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');
        libxml_clear_errors();

        $entries = [];
        $other   = '';
        foreach (iterator_to_array($this->pageContent($dom)->childNodes) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if ($node->nodeName === 'dl') {
                $entries = array_merge($entries, $this->definitionListEntries($node));
                continue;
            }
            $entry = $node->nodeName === 'p' ? $this->paragraphEntry($node) : null;
            if ($entry) {
                $entries[] = $entry;
                continue;
            }
            // alphabet headings (A, B, C…) aren't needed: the Glossary is listed in order
            if (preg_match('/^h[1-6]$/', $node->nodeName) && mb_strlen(trim($node->textContent)) <= 2) {
                continue;
            }
            $other .= $dom->saveHTML($node);
        }

        return [$entries, $other];
    }

    // the element holding the page's content: <body>, or a single plain wrapper inside it (<div>, <section>…)
    private function pageContent(\DOMDocument $dom): \DOMElement
    {
        $content = $dom->getElementsByTagName('body')->item(0);
        while (($only = $this->onlyChildElement($content)) && in_array($only->nodeName, ['div', 'section', 'article', 'main'], true)) {
            $content = $only;
        }

        return $content;
    }

    // [term, definition HTML] pairs from a <dl>: each <dt> and the <dd> after it
    private function definitionListEntries(\DOMElement $list): array
    {
        $entries = [];
        $term    = null;
        foreach ($list->childNodes as $item) {
            if ($item->nodeName === 'dt') {
                $term = ContentConverter::normalizeText($item->textContent);
            } elseif ($item->nodeName === 'dd' && $term) {
                $entries[] = [$term, $this->innerHtml($this->withoutFootnotes($item->cloneNode(true)))];
                $term = null;
            }
        }

        return $entries;
    }

    // [term, definition HTML] from a paragraph that starts with the term in bold, or null. It works on a copy,
    // so a paragraph that isn't an entry (such as a bold heading line) stays whole.
    private function paragraphEntry(\DOMElement $paragraph): ?array
    {
        $copy = $paragraph->cloneNode(true);
        $bold = $this->leadingBold($copy);
        if (!$bold) {
            return null;
        }
        $term = $this->boldText($bold);
        $copy->removeChild($bold);

        // a term with another name: "<strong>Actor-observer bias</strong> or <strong>difference</strong>"
        while (($otherName = $this->takeOtherName($copy)) !== null) {
            $term .= $otherName;
        }

        $definitionText = ltrim(ContentConverter::normalizeText($copy->textContent), self::TERM_PUNCTUATION);
        if ($term === '' || $definitionText === '') {
            return null;
        }

        return [$term, $this->innerHtml($this->withoutFootnotes($copy))];
    }

    // When a paragraph (with its term already taken off) starts with " or <strong>Other name</strong>", removes
    // both and returns " or Other name"; otherwise null
    private function takeOtherName(\DOMElement $paragraph): ?string
    {
        $joiner = $paragraph->firstChild;
        $next   = $joiner ? $joiner->nextSibling : null;
        if (!$joiner || $joiner->nodeType !== XML_TEXT_NODE || !$next instanceof \DOMElement
            || !in_array($next->nodeName, ['strong', 'b'], true)
            || !preg_match('/^(or|and|,)$/u', ContentConverter::normalizeText($joiner->nodeValue), $word)) {
            return null;
        }
        $paragraph->removeChild($joiner);
        $paragraph->removeChild($next);

        return ($word[1] === ',' ? ', ' : ' ' . $word[1] . ' ') . $this->boldText($next);
    }

    private function boldText(\DOMElement $bold): string
    {
        return rtrim(ContentConverter::normalizeText($bold->textContent), self::TERM_PUNCTUATION);
    }

    // a definition's footnotes are left out: a definition is one line, so they couldn't work there
    private function withoutFootnotes(\DOMNode $node): \DOMNode
    {
        $xpath = new \DOMXPath($node->ownerDocument);
        $footnote = "contains(concat(' ', normalize-space(@class), ' '), ' footnote ')";
        foreach (iterator_to_array($xpath->query(".//*[$footnote]", $node)) as $element) {
            $element->parentNode->removeChild($element);
        }

        return $node;
    }

    private function innerHtml(\DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    // A definition as Markdown, keeping its links, formatting, paragraphs and lists
    private function glossaryDefinition(string $html): string
    {
        // terms marked inside a definition stay plain text: a [term] can't work inside a definition
        $html = str_replace('glossary-term', '', $html);
        try {
            $markdown = $this->converter->convert($html);
        } catch (\Exception $e) {
            $markdown = ContentConverter::normalizeText(strip_tags($html));
        }

        return ltrim(trim(preg_replace('/\n{3,}/', "\n\n", $markdown)), self::TERM_PUNCTUATION);
    }

    // the <strong> or <b> a paragraph starts with, if any
    private function leadingBold(\DOMElement $paragraph): ?\DOMElement
    {
        foreach ($paragraph->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE && ContentConverter::normalizeText($child->nodeValue) === '') {
                continue; // spaces before it
            }
            return ($child instanceof \DOMElement && in_array($child->nodeName, ['strong', 'b'], true)) ? $child : null;
        }

        return null;
    }

    private function onlyChildElement(\DOMNode $node): ?\DOMElement
    {
        $found = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                if ($found) {
                    return null;
                }
                $found = $child;
            } elseif (trim($child->textContent) !== '') {
                return null;
            }
        }

        return $found;
    }

    // after Back Matter, or in its place when the Glossary was its only page
    private function glossarySectionNumber(): int
    {
        $backMatterNumber = 1 + count($this->parser->parts) + 1;

        return count($this->parser->backMatters) > 1 ? $backMatterNumber + 1 : $backMatterNumber;
    }

    // An unnumbered Glossary section, its terms in the section's Glossary setting: Open Publishing Space and
    // Helios Open Reader list them on the page, and show a definition when a [term] is hovered over or tapped
    private function writeGlossarySection(): void
    {
        $secNum    = $this->glossarySectionNumber();
        $secFolder = sprintf('%s/%02d.section-%d', $this->bookBase, $secNum, $secNum);

        $fm = [
            '---',
            'title: ' . Helpers::yamlStr($this->glossary['title']),
            'numbered: false',
            'description: ' . Helpers::yamlStr('Terms used in this book.'),
            'glossary:',
        ];
        foreach ($this->glossary['entries'] as [$term, $definition]) {
            $fm[] = '  - term: ' . Helpers::yamlStr($term);
            $fm[] = '    definition: ' . Helpers::yamlStr($this->glossaryDefinition($definition));
        }
        $fm[] = '---';

        // any other text on the glossary page, such as an introduction
        $body = '';
        if (trim(strip_tags($this->glossary['html'])) !== '') {
            try {
                $body = $this->converter->convert($this->glossary['html']);
            } catch (\Exception $e) {
                $this->errors[] = "failed to convert the glossary page's text: " . $e->getMessage();
            }
            $this->harvestH5pEmbeds($this->glossary['title']);
            $this->noteTablePluginShortcodes($this->glossary['title'], $body);
        }

        $this->addFile($secFolder . '/section.md', implode("\n", $fm) . "\n\n" . trim($body) . "\n");
        $this->sectionLabels[] = [$secNum, $this->glossary['title']];
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
        ?string $sectionLabelOverride = null,
        bool    $numbered = true
    ): void {
        $secFolder = sprintf('%s/%02d.section-%d', $this->bookBase, $secNum, $secNum);

        $fm = ['---', 'title: ' . Helpers::yamlStr($secTitle)];
        if (!$this->portableMarkdown) {
            if ($sectionLabelOverride !== null) {
                $fm[] = 'section_label: ' . Helpers::yamlStr($sectionLabelOverride);
            }
            // front matter, back matter and appendices don't take a number, so the chapters count from 1
            if (!$numbered) {
                $fm[] = 'numbered: false';
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
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            $this->imageFailures[] = $url;
            return null;
        }
        if (!$this->isPublicAddress($url)) {
            $this->warnings[]      = "Skipped image from private/internal address: $host";
            $this->imageFailures[] = $url;
            return null;
        }

        $data = $this->httpGet($url, 20 * 1024 * 1024); // 20 MB cap per image
        if ($data === null) {
            $this->imageFailures[] = $url;
        }
        return $data;
    }

    // SSRF guard: reject private/loopback/link-local addresses
    private function isPublicAddress(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $ip = gethostbyname($host);
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function httpGet(string $url, int $maxLength): ?string
    {
        $opts = [
            'http' => [
                'method'          => 'GET',
                // a descriptive name, as many Pressbooks sites refuse the bare "Mozilla/5.0" as a bot
                'header'          => 'User-Agent: Mozilla/5.0 (compatible; PressbooksConverter/1.0; +https://pressbooks-converter.hibbittsdesign.org/)',
                'timeout'         => 15,
                'follow_location' => 1,
                'max_length'      => $maxLength,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ];

        // Try with SSL verification, fall back without
        foreach ([true, false] as $verify) {
            $opts['ssl']['verify_peer']      = $verify;
            $opts['ssl']['verify_peer_name'] = $verify;
            $ctx  = stream_context_create($opts);
            $http_response_header = [];
            $data = @file_get_contents($url, false, $ctx);
            if ($data !== false) {
                return $data;
            }
            // When the site answered with an error (such as "HTTP/1.1 403 Forbidden"), keep its
            // status line: trying again without verification wouldn't change the answer
            if ($http_response_header) {
                $this->lastFetchError = $http_response_header[0];
                break;
            }
            $this->lastFetchError = 'no response: ' . preg_replace('/^.*?\):\s*/', '', error_get_last()['message'] ?? '');
            if ($verify && !$this->sslHintShown) {
                $this->sslHintShown = true;
                $this->warnings[]   = 'SSL certificate error — retrying without verification';
            }
        }
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
        if ($this->menuLabel !== '') {
            $lines[] = '  Menu:    ' . $this->menuLabel . ' (shorter label for the site\'s menu)';
        }
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
            // Standard Markdown has no cover image setting, so a cover (uploaded or from
            // Pressbooks) isn't included: say so, as one may have been uploaded on the form
            if ($this->coverImageData !== null || $p->bookCoverUrl) {
                $lines[] = '  2. The book\'s cover image isn\'t included, as Standard Markdown has no';
                $lines[] = '     cover setting: to show it, add the image to the book\'s page by hand';
                $lines[] = '  3. Review this file for any warnings or manual fixes needed';
            } else {
                $lines[] = '  2. Review this file for any warnings or manual fixes needed';
            }
        } elseif ($this->bookRoute !== '') {
            $lines[] = '  1. Copy the book folder from inside the extracted pages folder into';
            $lines[] = '     your Grav Open Publishing Space site\'s user/pages/ directory';
            // the menu label step (when there is one) comes before Review, which is always last
            $reviewNumber = 2;
            if ($this->menuLabel !== '') {
                $lines[] = '  2. To change the book\'s menu label, edit menu: in the book\'s page';
                $lines[] = '     (in Admin: the book\'s page, Advanced tab, Menu)';
                $reviewNumber = 3;
            } elseif (mb_strlen($p->bookTitle) > self::MENU_LABEL_MAX_LENGTH) {
                $lines[] = '  2. The book\'s title is long for the site\'s menu: to shorten it there,';
                $lines[] = '     add menu: "Short Title" to the book\'s page (in Admin: Advanced tab, Menu)';
                $reviewNumber = 3;
            }
            $lines[] = '  ' . $reviewNumber . '. Review this file for any warnings or manual fixes needed';
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
            : 'downloaded and bundled in ZIP')
            . ($this->captionedImagesAsMarkdown
                ? ' (captioned images as a Markdown image with an italic caption, so they are downloaded too)'
                : '');
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

        $this->addGlossaryAndCaptionNotes($lines);

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
        // Tables the Pressbooks export left out (see noteTablePluginShortcodes())
        if ($this->tablePluginPages) {
            $lines[] = '';
            $lines[] = 'Tables Not in the Export';
            $lines[] = '------------------------';
            $lines[] = '  These pages use a WordPress table plugin (TablePress), whose tables the';
            $lines[] = '  Pressbooks export leaves out, keeping only a shortcode such as [table id=5].';
            $lines[] = '  Copy each table from the book on Pressbooks into the page by hand:';
            foreach ($this->tablePluginPages as $pageTitle => $count) {
                $tableWord = $count === 1 ? '1 table' : $count . ' tables';
                $lines[] = '  - ' . $pageTitle . ' (' . $tableWord . ')';
            }
        }

        // Part intro pages the Pressbooks export left out (see fetchMissingPartIntros())
        if ($this->partIntrosFetched) {
            $lines[] = '';
            $lines[] = 'Part Intros Not in the Export';
            $lines[] = '-----------------------------';
            $lines[] = '  The Pressbooks export left out the intro page of ' . $this->partIntrosFetched . ' part(s), so their';
            $lines[] = '  text was taken from the book on Pressbooks (' . $p->bookUrl . ').';
            $lines[] = '  Links to the book\'s own pages point to the converted pages; review each';
            $lines[] = '  section\'s own page, as other content may differ from the export.';
        }

        $lines[] = '';
        $lines[] = 'Media Support';
        $lines[] = '-------------';
        if ($this->portableMarkdown) {
            $lines[] = '  YouTube videos are embedded as real <iframe> elements (YouTube\'s own embed';
            $lines[] = '  code). H5P activities are embedded the same way when "Embed H5P activities"';
            $lines[] = '  is enabled; otherwise they are left as links to the original source.';
        } else {
            $lines[] = '  ' . $this->destinationName() . ' supports embedded video, audio, and H5P activities,';
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

    // Glossary, table captions and figure numbers (Grav destinations): what was converted, and what to review
    private function addGlossaryAndCaptionNotes(array &$lines): void
    {
        $c = $this->converter;
        if ($this->portableMarkdown || (!$this->glossary && !$c->tableCaptions && !$c->figureCaptions)) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Glossary and Captions';
        $lines[] = '---------------------';
        if ($this->glossary) {
            $lines[] = '  Glossary: ' . count($this->glossary['entries']) . ' terms, in the Glossary section (section '
                . $this->glossarySectionNumber() . '), listed in alphabetical order';
            $lines[] = '  Glossary terms in the text: ' . $c->glossaryTermsLinked . ' show their definition when hovered over or tapped';
            if ($c->similarGlossaryTerms) {
                $lines[] = '  These were linked to a similar Glossary term (such as a plural): check they mean the same:';
                foreach ($c->similarGlossaryTerms as $words => $term) {
                    $lines[] = '    - ' . $words . ' → ' . $term;
                }
            }
            if ($c->glossaryTermsLeftPlain) {
                $lines[] = '  ' . $c->glossaryTermsLeftPlain . ' glossary term(s) inside a link, heading or textbox title were left as plain text';
            }
            if ($c->unmatchedGlossaryTerms) {
                $lines[] = '  These terms marked in the book aren\'t in its Glossary, so they are plain text (add them';
                $lines[] = '  to the Glossary, or wrap them in [term name="Glossary term"]…[/term]):';
                foreach ($c->unmatchedGlossaryTerms as $term => $count) {
                    $lines[] = '    - ' . $term . ($count > 1 ? " ($count times)" : '');
                }
            }
        }
        if ($c->tableCaptions) {
            $lines[] = '  Table captions: ' . $c->tableCaptions . ' (as "Table: …" lines, which become the tables\' captions)';
        }
        if ($c->figureCaptions) {
            $lines[] = '  Figure captions: ' . $c->figureCaptions . ', ' . $c->numberedFigureCaptions . ' already numbered in the book (such as "Figure 3.2")';
            if ($c->numberedFigureCaptions === 0) {
                $lines[] = '  To number figures and tables by section (Figure 3.2), turn on Number Figures and Tables';
                $lines[] = '  on the book\'s home page';
            }
        }
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

    // The site the book is going into, for the import notice and conversion notes. Grav Open
    // Publishing Space keeps the book's folder in page addresses; Helios Open Reader doesn't
    // (Standard Markdown also keeps the folder, but has no site of its own to name)
    private function destinationName(): string
    {
        if ($this->bookRoute !== '' && !$this->portableMarkdown) {
            return 'Grav Open Publishing Space';
        }
        return 'Grav Helios Open Reader';
    }

    private function destinationUrl(): string
    {
        if ($this->bookRoute !== '' && !$this->portableMarkdown) {
            return 'https://www.hibbittsdesign.org/grav-open-publishing-space.html';
        }
        return 'https://www.hibbittsdesign.org/Grav-Helios-Open-Reader-3560615470e080a79958c9c7dcd5d9a1';
    }

    // Notes a page whose table wasn't in the Pressbooks export: Pressbooks books can use a
    // WordPress table plugin (TablePress), whose tables the export leaves out, keeping only
    // its shortcode, such as [table id=5]. The converter can't recover the table, so the
    // conversion notes name the page, for the table to be added by hand
    private function noteTablePluginShortcodes(string $pageTitle, string $content): void
    {
        $count = preg_match_all('/\[table\s+id=["\']?\d+["\']?[^\]]*\]/i', $content);
        if ($count > 0) {
            $this->tablePluginPages[$pageTitle] = ($this->tablePluginPages[$pageTitle] ?? 0) + $count;
        }
    }

    private function harvestH5pEmbeds(string $pageTitle): void
    {
        foreach ($this->converter->h5pEmbeds as $embed) {
            $this->allH5pEmbeds[] = ['page' => $pageTitle, 'source' => $embed['source'], 'embed' => $embed['embed']];
        }
    }

    // Find a shorter menu label for a long book title, using the title's natural
    // break points in turn, and stopping as soon as the label is short enough:
    //   1. the part before a subtitle separator (": ", " – ", " - ")
    //   2. without edition wording ("2nd Edition", "Third Edition", "Version 2")
    //   3. up to and including the first acronym in brackets ("... (UDL)")
    // If no break point makes it short enough, the shortest label found is still used
    // when it's clearly shorter than the title. Returns '' when the title is already
    // short, or when no break point helps (cutting a title mid-phrase reads worse
    // than a long label).
    private function shortMenuLabel(string $title): string
    {
        $title = trim($title);
        if (mb_strlen($title) <= self::MENU_LABEL_MAX_LENGTH) {
            return '';
        }
        $label = $title;

        // 1. Subtitle separator: keep the part before it, if it's at least two words
        $parts = preg_split('/\s*:\s+|\s+[–—-]\s+/u', $label, 2);
        if (count($parts) === 2 && str_word_count($parts[0]) >= 2) {
            $label = trim($parts[0]);
        }
        if (mb_strlen($label) <= self::MENU_LABEL_MAX_LENGTH) {
            return $label;
        }

        // 2. Edition wording at the end, with an optional comma or brackets before it,
        //    for example ", 2nd Edition", "1st International H5P Edition", "(Version 2)"
        $withoutEdition = preg_replace(
            '/[\s,]*\(?\b(\d+(st|nd|rd|th)|first|second|third|fourth|fifth|sixth|revised|new)\b[\w\s]*\bedition\)?$/iu',
            '',
            $label
        );
        $withoutEdition = preg_replace('/[\s,]*\(?\bversion\s+[\d.]+\)?$/iu', '', $withoutEdition);
        if (str_word_count($withoutEdition) >= 2) {
            $label = trim($withoutEdition);
        }
        if (mb_strlen($label) <= self::MENU_LABEL_MAX_LENGTH) {
            return $label;
        }

        // 3. The first acronym in brackets (two or more capital letters, no spaces):
        //    keep the words before it and the acronym itself
        if (preg_match('/^(.+?\(\s*[A-Z][A-Z0-9&.\-]+\s*\))/u', $label, $match)) {
            $beforeAcronym = $match[1];
            if (str_word_count($beforeAcronym) >= 3) {
                $label = trim($beforeAcronym);
            }
        }
        if (mb_strlen($label) <= self::MENU_LABEL_MAX_LENGTH) {
            return $label;
        }

        // Still over the limit: use the label anyway if it's clearly shorter than the
        // title, otherwise the menu shows the full title
        if (mb_strlen($title) - mb_strlen($label) >= self::MENU_LABEL_MIN_SAVING) {
            return $label;
        }
        return '';
    }

    private function formatAuthors(array $authors): string
    {
        if (count($authors) > 1) {
            return implode(', ', array_slice($authors, 0, -1)) . ' and ' . end($authors);
        }
        return $authors[0] ?? '';
    }
}
