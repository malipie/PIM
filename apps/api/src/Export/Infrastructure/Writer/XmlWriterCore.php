<?php

declare(strict_types=1);

namespace App\Export\Infrastructure\Writer;

use LogicException;
use RuntimeException;
use XMLWriter;

/**
 * Thin, memory-bounded wrapper over the native {@see XMLWriter} (ADR-0023,
 * XMLF-P0-03). This is the ONLY class in the Export BC that touches XMLWriter.
 *
 * Responsibilities:
 *   - UTF-8 XML declaration + streaming element/attribute/text emission;
 *   - auto-escaping of `& < > " '` (delegated to XMLWriter::text/writeAttribute);
 *   - sanitisation of characters illegal in XML 1.0 (control chars, invalid
 *     UTF-8 byte sequences) that XMLWriter would otherwise emit raw and break
 *     well-formedness;
 *   - CDATA with `]]>` neutralisation so section content can never terminate
 *     the CDATA block early;
 *   - memory-bounded operation — never builds a DOM tree; in URI mode the
 *     internal buffer is flushed periodically to the target stream.
 *
 * The always-well-formed guarantee (XMLF-P0-01/P6-01) covers VALUES only.
 * Element/attribute NAMES are the caller's contract (descriptor / column keys)
 * and are validated one layer up (FeedDescriptor VO — XMLF-P2-01, GenericXml
 * key sanitisation — XMLF-P0-04).
 */
final class XmlWriterCore
{
    /**
     * Flush the internal buffer to the target every N written elements when in
     * URI (streaming) mode, so a 50k-SKU feed never accumulates the whole
     * document in memory (CLAUDE.md §3.10, XMLF-P6-03).
     */
    private const int FLUSH_EVERY = 256;

    private int $sinceFlush = 0;
    private bool $closed = false;

    private function __construct(
        private readonly XMLWriter $writer,
        private readonly bool $memoryMode,
    ) {
    }

    /**
     * In-memory mode — the whole document is held in the XMLWriter buffer and
     * returned by {@see outputMemory()}. Use for preview (XMLF-P4-04) and unit
     * tests, never for full-catalog feeds.
     */
    public static function toMemory(): self
    {
        $writer = new XMLWriter();
        $writer->openMemory();

        return new self($writer, true);
    }

    /**
     * Streaming mode — writes to a URI (e.g. a file path or `php://temp`). The
     * buffer is flushed to the target periodically, keeping peak memory flat.
     */
    public static function toUri(string $uri): self
    {
        $writer = new XMLWriter();
        if (!$writer->openUri($uri)) {
            throw new RuntimeException(sprintf('Unable to open XML target "%s" for writing.', $uri));
        }

        return new self($writer, false);
    }

    public function startDocument(bool $indent = false): self
    {
        $this->writer->startDocument('1.0', 'UTF-8');
        if ($indent) {
            $this->writer->setIndent(true);
            $this->writer->setIndentString('  ');
        }

        return $this;
    }

    public function startElement(string $name): self
    {
        $this->writer->startElement($name);
        $this->tick();

        return $this;
    }

    /**
     * Declare an `xmlns:<prefix>` namespace on the current element.
     */
    public function namespaceAttribute(string $prefix, string $uri): self
    {
        $this->writer->writeAttribute('xmlns:'.$prefix, self::sanitizeText($uri));

        return $this;
    }

    public function attribute(string $name, string $value): self
    {
        // XMLWriter::writeAttribute auto-escapes `& < > " '`; we only strip
        // characters that are illegal in XML 1.0 regardless of escaping.
        $this->writer->writeAttribute($name, self::sanitizeText($value));

        return $this;
    }

    public function text(string $value): self
    {
        $this->writer->text(self::sanitizeText($value));

        return $this;
    }

    public function cdata(string $value): self
    {
        $this->writer->writeCdata(self::neutraliseCdata($value));

        return $this;
    }

    public function endElement(): self
    {
        $this->writer->endElement();

        return $this;
    }

    /**
     * Convenience: `<name>text</name>` with escaped text.
     */
    public function element(string $name, string $value): self
    {
        return $this->startElement($name)->text($value)->endElement();
    }

    /**
     * Convenience: `<name><![CDATA[...]]></name>` with neutralised CDATA.
     */
    public function elementCdata(string $name, string $value): self
    {
        return $this->startElement($name)->cdata($value)->endElement();
    }

    public function endDocument(): self
    {
        $this->writer->endDocument();
        // In URI mode, flush the remaining buffer to the target. In memory
        // mode we must NOT flush here — flush() empties the memory buffer that
        // outputMemory() still needs to return.
        if (!$this->memoryMode) {
            $this->writer->flush(true);
        }
        $this->closed = true;

        return $this;
    }

    /**
     * Return the buffered document (memory mode only). Does not require
     * {@see endDocument()} but callers normally end the document first.
     */
    public function outputMemory(): string
    {
        if (!$this->memoryMode) {
            throw new LogicException('outputMemory() is only available in memory mode; use toUri() for streaming.');
        }

        return $this->writer->outputMemory();
    }

    /**
     * Strip characters illegal in XML 1.0 and scrub invalid UTF-8.
     *
     * Allowed: #x9, #xA, #xD, #x20-#xD7FF, #xE000-#xFFFD, #x10000-#x10FFFF.
     * This is what makes the feed well-formed even for garbage product data
     * (a name containing a vertical tab \x0B, a NUL, or a broken UTF-8 byte).
     */
    public static function sanitizeText(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        // Drop invalid UTF-8 byte sequences first so the /u regex cannot fail
        // on a malformed string (e.g. a stray 0xFF byte from a bad import).
        $scrubbed = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        $clean = preg_replace(
            '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $scrubbed,
        );

        // preg_replace returns null only on a PCRE error; fall back to the
        // scrubbed string so we never propagate null into the writer.
        return $clean ?? $scrubbed;
    }

    /**
     * Neutralise `]]>` inside CDATA content by closing and reopening the
     * section around the sequence — the standard, loss-free technique.
     */
    public static function neutraliseCdata(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', self::sanitizeText($value));
    }

    private function tick(): void
    {
        if ($this->memoryMode || $this->closed) {
            return;
        }

        if (++$this->sinceFlush >= self::FLUSH_EVERY) {
            // flush(empty: true) writes the buffer to the URI target AND clears
            // it — this is what keeps peak memory flat for a 50k-SKU feed.
            $this->writer->flush(true);
            $this->sinceFlush = 0;
        }
    }
}
