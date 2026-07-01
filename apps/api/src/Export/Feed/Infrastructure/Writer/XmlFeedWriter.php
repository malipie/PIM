<?php

declare(strict_types=1);

namespace App\Export\Feed\Infrastructure\Writer;

use App\Export\Contracts\ItemWriter;
use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Descriptor\FeedSlot;
use App\Export\Feed\Domain\Descriptor\HtmlPolicy;
use App\Export\Feed\Domain\Descriptor\SlotNodeKind;
use App\Export\Infrastructure\Writer\XmlWriterCore;

/**
 * Descriptor-driven feed serializer (ADR-0023 §6.3, XMLF-P2-05). Implements the
 * associative {@see ItemWriter} contract: for each item it walks the
 * {@see FeedDescriptor} slots and assembles the XML through {@see XmlWriterCore}
 * (the only class touching XMLWriter — escaping, CDATA, control-char sanitising,
 * always well-formed). The generator (XMLF-P2-04) feeds it an item map keyed by
 * slot target, already transformed and validated.
 *
 * Node kinds: attribute nodes are written first (XMLWriter requires attributes
 * right after the element opens); element / repeatable / keyvalue nodes follow,
 * grouped under an optional `wrapIn` element (Ceneo `<imgs>` around `<i>`).
 */
final class XmlFeedWriter implements ItemWriter
{
    private const string MULTI_GLUE = '|';

    /**
     * @param array<string, string> $context feed-level tokens for header/static interpolation
     *                                       (e.g. feed_name, store_url)
     */
    public function __construct(
        private readonly XmlWriterCore $xml,
        private readonly FeedDescriptor $descriptor,
        private readonly array $context = [],
    ) {
    }

    public function begin(): void
    {
        $this->xml->startDocument()->startElement($this->descriptor->rootElement);
        foreach ($this->descriptor->rootAttributes as $name => $value) {
            $this->xml->attribute($name, $value);
        }
        foreach ($this->descriptor->namespaces as $prefix => $uri) {
            $this->xml->namespaceAttribute($prefix, $uri);
        }

        if (null !== $this->descriptor->channelElement) {
            $this->xml->startElement($this->descriptor->channelElement);
            foreach ($this->descriptor->header as $node) {
                $element = \is_string($node['element'] ?? null) ? $node['element'] : null;
                if (null === $element) {
                    continue;
                }
                $this->xml->element($element, $this->headerValue($node));
            }
        }
    }

    public function writeItem(array $item): void
    {
        $this->xml->startElement($this->descriptor->itemElement);

        // 1) Attribute nodes on the item element — must precede any children.
        foreach ($this->descriptor->slots as $slot) {
            if (SlotNodeKind::Attribute === $slot->node) {
                $this->xml->attribute($slot->element, $item[$slot->target] ?? '');
            }
        }

        // 2) Element / repeatable / keyvalue children, grouped by wrapIn.
        $openWrap = null;
        foreach ($this->descriptor->slots as $slot) {
            if (SlotNodeKind::Attribute === $slot->node) {
                continue;
            }
            if ($slot->wrapIn !== $openWrap) {
                if (null !== $openWrap) {
                    $this->xml->endElement();
                }
                $openWrap = $slot->wrapIn;
                if (null !== $openWrap) {
                    $this->xml->startElement($openWrap);
                }
            }
            $this->writeSlot($slot, $item[$slot->target] ?? '');
        }
        if (null !== $openWrap) {
            $this->xml->endElement();
        }

        $this->xml->endElement();
    }

    public function finish(): void
    {
        if (null !== $this->descriptor->channelElement) {
            $this->xml->endElement();
        }
        $this->xml->endElement()->endDocument();
    }

    private function writeSlot(FeedSlot $slot, string $value): void
    {
        if (SlotNodeKind::Repeatable === $slot->node) {
            if ('' === $value) {
                return;
            }
            foreach (explode(self::MULTI_GLUE, $value) as $single) {
                $this->writeValueElement($slot, $single);
            }

            return;
        }

        $this->writeValueElement($slot, $value);
    }

    private function writeValueElement(FeedSlot $slot, string $value): void
    {
        $this->xml->startElement($slot->element);
        match ($slot->rule->html) {
            HtmlPolicy::Cdata => $this->xml->cdata($value),
            HtmlPolicy::Strip => $this->xml->text(strip_tags($value)),
            HtmlPolicy::Escape => $this->xml->text($value),
        };
        $this->xml->endElement();
    }

    /**
     * @param array<string, mixed> $node
     */
    private function headerValue(array $node): string
    {
        $source = $node['source'] ?? null;
        if (!\is_array($source)) {
            return '';
        }
        $value = \is_string($source['value'] ?? null) ? $source['value'] : '';

        return $this->interpolate($value);
    }

    private function interpolate(string $template): string
    {
        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            fn (array $m): string => $this->context[$m[1]] ?? $m[0],
            $template,
        ) ?? $template;
    }
}
