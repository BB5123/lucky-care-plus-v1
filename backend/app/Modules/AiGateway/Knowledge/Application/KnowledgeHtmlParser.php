<?php

namespace App\Modules\AiGateway\Knowledge\Application;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use InvalidArgumentException;

/**
 * Extracts the article body from a government health page (TASK G4-KB).
 *
 * The DDC disease pages keep the article in `#nav-tabContent`, with headings
 * expressed as `<p><strong>…</strong></p>` and the title in an adjacent
 * `<h2 class="mb-0">`. The parser walks the DOM instead of using
 * `textContent`, so block boundaries become newlines and headings are kept as
 * a small vocabulary for retrieval.
 *
 * It never follows links, executes scripts or fetches anything: the command
 * downloads one page, this class only turns the HTML into text.
 */
final class KnowledgeHtmlParser
{
    /**
     * @return array{title: string, text: string, headings: list<string>, published_at: ?string}
     */
    public function parse(string $html): array
    {
        if (trim($html) === '') {
            throw new InvalidArgumentException('Empty HTML document.');
        }

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $root = $this->contentRoot($xpath);

        if (! $root instanceof DOMElement) {
            throw new InvalidArgumentException('No article body found in the HTML document.');
        }

        $headings = [];
        $text = $this->normalise($this->walk($root, $headings));

        if (mb_strlen($text) < 200) {
            throw new InvalidArgumentException('The extracted article body is too short.');
        }

        return [
            'title' => $this->title($xpath),
            'text' => $text,
            'headings' => array_values(array_unique($headings)),
            'published_at' => null,
        ];
    }

    private function contentRoot(DOMXPath $xpath): ?DOMElement
    {
        $byId = $xpath->query('//*[@id="nav-tabContent"]');

        if ($byId !== false && $byId->length > 0 && $byId->item(0) instanceof DOMElement) {
            return $byId->item(0);
        }

        $candidates = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " content ")]');
        $best = null;
        $bestLength = 0;

        if ($candidates !== false) {
            foreach ($candidates as $candidate) {
                if (! $candidate instanceof DOMElement) {
                    continue;
                }

                $length = mb_strlen(trim((string) $candidate->textContent));

                if ($length > $bestLength) {
                    $best = $candidate;
                    $bestLength = $length;
                }
            }
        }

        if ($best instanceof DOMElement) {
            return $best;
        }

        foreach (['//article', '//main', '//body'] as $query) {
            $nodes = $xpath->query($query);

            if ($nodes !== false && $nodes->length > 0 && $nodes->item(0) instanceof DOMElement) {
                return $nodes->item(0);
            }
        }

        return null;
    }

    private function title(DOMXPath $xpath): string
    {
        $queries = [
            '//h2[contains(concat(" ", normalize-space(@class), " "), " mb-0 ")]',
            '//h1',
            '//title',
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $title = $this->clean((string) $nodes->item(0)->textContent);

            if ($title !== '') {
                return $title;
            }
        }

        return 'ไม่ระบุชื่อเรื่อง';
    }

    private function walk(DOMNode $node, array &$headings): string
    {
        $output = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $output .= $child->nodeValue;

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, ['script', 'style', 'noscript', 'iframe', 'svg', 'form'], true)) {
                continue;
            }

            if ($tag === 'br') {
                $output .= "\n";

                continue;
            }

            $heading = $this->headingText($child);

            if ($heading !== null) {
                $headings[] = $heading;
                $output .= "\n".$heading."\n";

                continue;
            }

            if (in_array($tag, ['p', 'div', 'li', 'tr', 'ul', 'ol', 'table', 'section', 'article'], true)) {
                $output .= "\n".$this->walk($child, $headings)."\n";

                continue;
            }

            $output .= $this->walk($child, $headings);
        }

        return $output;
    }

    /**
     * A heading is an `<h1>`–`<h6>`, or a paragraph whose only content is
     * emphasis around a short phrase (`<p><strong>อาการ</strong></p>`).
     */
    private function headingText(DOMElement $element): ?string
    {
        $tag = strtolower($element->nodeName);

        if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            return $this->clean((string) $element->textContent);
        }

        if (! in_array($tag, ['p', 'div'], true)) {
            return null;
        }

        $hasEmphasis = false;

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (trim($child->nodeValue) !== '') {
                    return null;
                }

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            if (! in_array(strtolower($child->nodeName), ['strong', 'b', 'u', 'em'], true)) {
                return null;
            }

            $hasEmphasis = true;
        }

        if (! $hasEmphasis) {
            return null;
        }

        $text = $this->clean((string) $element->textContent);

        if ($text === '' || mb_strlen($text) > 80) {
            return null;
        }

        return $text;
    }

    private function normalise(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = preg_split('/\R/u', $text) ?: [];
        $clean = [];

        foreach ($lines as $line) {
            $line = $this->clean($line);

            if ($line === '') {
                if ($clean !== [] && end($clean) !== '') {
                    $clean[] = '';
                }

                continue;
            }

            $clean[] = $line;
        }

        while ($clean !== [] && end($clean) === '') {
            array_pop($clean);
        }

        return trim(implode("\n", $clean));
    }

    private function clean(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
