<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Template;

use App\Export\Feed\Domain\Enum\FeedTemplateKind;

/**
 * The built-in feed templates (ADR-0023 §6.5, XMLF-P2-02): Google Shopping,
 * Ceneo, Meta/Facebook Catalog, and a blank Custom starter. Descriptors mirror
 * the design's TEMPLATE_SLOTS / DEFAULT_MAPPINGS and are validated on parse by
 * {@see \App\Export\Feed\Domain\Descriptor\FeedDescriptor}.
 */
final class FeedTemplateCatalog
{
    public function get(FeedTemplateKind $kind): FeedTemplate
    {
        return match ($kind) {
            FeedTemplateKind::GoogleShopping => $this->google(),
            FeedTemplateKind::Ceneo => $this->ceneo(),
            FeedTemplateKind::Meta => $this->meta(),
            FeedTemplateKind::Custom => $this->custom(),
        };
    }

    /**
     * @return list<FeedTemplate>
     */
    public function all(): array
    {
        return [$this->google(), $this->ceneo(), $this->meta(), $this->custom()];
    }

    private function google(): FeedTemplate
    {
        $slots = [
            ['slot' => 'g:id', 'node' => 'element', 'required' => true, 'maxLength' => 50, 'fmt' => 'text'],
            ['slot' => 'g:title', 'node' => 'element', 'required' => true, 'maxLength' => 150, 'fmt' => 'text'],
            ['slot' => 'g:description', 'node' => 'element', 'html' => 'cdata', 'maxLength' => 5000, 'fmt' => 'html'],
            ['slot' => 'g:link', 'node' => 'element', 'required' => true, 'fmt' => 'url'],
            ['slot' => 'g:image_link', 'node' => 'element', 'required' => true, 'fmt' => 'url'],
            ['slot' => 'g:availability', 'node' => 'element', 'required' => true, 'fmt' => 'enum', 'enums' => ['in_stock', 'out_of_stock', 'preorder', 'backorder']],
            ['slot' => 'g:price', 'node' => 'element', 'required' => true, 'fmt' => 'price'],
            ['slot' => 'g:brand', 'node' => 'element', 'fmt' => 'text'],
            ['slot' => 'g:gtin', 'node' => 'element', 'requiredOneOf' => ['g:gtin', 'g:mpn'], 'fmt' => 'text'],
            ['slot' => 'g:mpn', 'node' => 'element', 'requiredOneOf' => ['g:gtin', 'g:mpn'], 'fmt' => 'text'],
            ['slot' => 'g:condition', 'node' => 'element', 'fmt' => 'enum', 'enums' => ['new', 'refurbished', 'used']],
            ['slot' => 'g:google_product_category', 'node' => 'element', 'fmt' => 'category'],
            ['slot' => 'g:item_group_id', 'node' => 'element', 'fmt' => 'text'],
        ];

        $descriptor = [
            'root' => ['element' => 'rss', 'attributes' => ['version' => '2.0'], 'namespaces' => ['g' => 'http://base.google.com/ns/1.0']],
            'channel' => [
                'element' => 'channel',
                'header' => [
                    ['element' => 'title', 'source' => ['kind' => 'static', 'value' => '{feed_name}']],
                    ['element' => 'link', 'source' => ['kind' => 'static', 'value' => '{store_url}']],
                    ['element' => 'description', 'source' => ['kind' => 'static', 'value' => 'Product feed']],
                ],
                'item' => ['element' => 'item', 'slots' => $slots],
            ],
        ];

        $mappings = [
            ['slot' => 'g:id', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'g:title', 'source' => ['kind' => 'attribute', 'ref' => 'name'], 'transform' => ['kind' => 'truncate', 'to' => 150]],
            ['slot' => 'g:description', 'source' => ['kind' => 'attribute', 'ref' => 'description'], 'transform' => ['kind' => 'strip_html']],
            ['slot' => 'g:link', 'source' => ['kind' => 'template', 'value' => '{store_url}/p/{url_slug}']],
            ['slot' => 'g:image_link', 'source' => ['kind' => 'attribute', 'ref' => 'main_image']],
            ['slot' => 'g:availability', 'source' => ['kind' => 'attribute', 'ref' => 'stock_qty'], 'transform' => ['kind' => 'enum_map', 'rule' => 'stock']],
            ['slot' => 'g:price', 'source' => ['kind' => 'attribute', 'ref' => 'price_gross'], 'transform' => ['kind' => 'price']],
            ['slot' => 'g:brand', 'source' => ['kind' => 'attribute', 'ref' => 'brand']],
            ['slot' => 'g:gtin', 'source' => ['kind' => 'attribute', 'ref' => 'ean']],
            ['slot' => 'g:mpn', 'source' => ['kind' => 'attribute', 'ref' => 'mpn']],
            ['slot' => 'g:condition', 'source' => ['kind' => 'static', 'value' => 'new']],
            ['slot' => 'g:google_product_category', 'source' => ['kind' => 'attribute', 'ref' => 'google_category']],
        ];

        return new FeedTemplate(FeedTemplateKind::GoogleShopping, $descriptor, $mappings);
    }

    private function ceneo(): FeedTemplate
    {
        $slots = [
            ['target' => 'id', 'node' => 'attribute', 'parent' => 'o', 'element' => 'id', 'required' => true, 'fmt' => 'text'],
            ['target' => 'url', 'node' => 'attribute', 'parent' => 'o', 'element' => 'url', 'required' => true, 'fmt' => 'url'],
            ['target' => 'price', 'node' => 'attribute', 'parent' => 'o', 'element' => 'price', 'required' => true, 'fmt' => 'number'],
            ['target' => 'avail', 'node' => 'attribute', 'parent' => 'o', 'element' => 'avail', 'required' => true, 'fmt' => 'enum', 'enums' => ['0', '1', '2', '3', '7', '14']],
            ['target' => 'stock', 'node' => 'attribute', 'parent' => 'o', 'element' => 'stock', 'fmt' => 'number'],
            ['target' => 'name', 'node' => 'element', 'element' => 'name', 'required' => true, 'maxLength' => 200, 'fmt' => 'text'],
            ['target' => 'cat', 'node' => 'element', 'element' => 'cat', 'required' => true, 'html' => 'cdata', 'fmt' => 'category'],
            ['target' => 'desc', 'node' => 'element', 'element' => 'desc', 'html' => 'cdata', 'fmt' => 'html'],
            ['target' => 'imgs.main', 'node' => 'element', 'element' => 'main', 'wrapIn' => 'imgs', 'required' => true, 'fmt' => 'url'],
            ['target' => 'imgs.i', 'node' => 'repeatable', 'element' => 'i', 'wrapIn' => 'imgs', 'fmt' => 'url'],
            ['target' => 'attrs.a', 'node' => 'keyvalue', 'element' => 'a', 'wrapIn' => 'attrs', 'fmt' => 'text'],
        ];

        $descriptor = [
            'root' => ['element' => 'offers', 'attributes' => ['version' => '1']],
            'item' => ['element' => 'o', 'slots' => $slots],
        ];

        $mappings = [
            ['slot' => 'id', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'url', 'source' => ['kind' => 'template', 'value' => '{store_url}/p/{url_slug}']],
            ['slot' => 'price', 'source' => ['kind' => 'attribute', 'ref' => 'price_gross'], 'transform' => ['kind' => 'number', 'precision' => 2]],
            ['slot' => 'avail', 'source' => ['kind' => 'attribute', 'ref' => 'stock_qty'], 'transform' => ['kind' => 'enum_map', 'rule' => 'ceneo_avail']],
            ['slot' => 'stock', 'source' => ['kind' => 'attribute', 'ref' => 'stock_qty']],
            ['slot' => 'name', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
            ['slot' => 'cat', 'source' => ['kind' => 'attribute', 'ref' => 'category_path']],
            ['slot' => 'desc', 'source' => ['kind' => 'attribute', 'ref' => 'description']],
            ['slot' => 'imgs.main', 'source' => ['kind' => 'attribute', 'ref' => 'main_image']],
            ['slot' => 'imgs.i', 'source' => ['kind' => 'attribute', 'ref' => 'gallery']],
            ['slot' => 'attrs.a', 'source' => ['kind' => 'attribute', 'ref' => 'brand']],
        ];

        return new FeedTemplate(FeedTemplateKind::Ceneo, $descriptor, $mappings);
    }

    private function meta(): FeedTemplate
    {
        $slots = [
            ['slot' => 'g:id', 'node' => 'element', 'required' => true, 'maxLength' => 100, 'fmt' => 'text'],
            ['slot' => 'g:title', 'node' => 'element', 'required' => true, 'maxLength' => 200, 'fmt' => 'text'],
            ['slot' => 'g:description', 'node' => 'element', 'required' => true, 'html' => 'cdata', 'maxLength' => 9999, 'fmt' => 'html'],
            ['slot' => 'g:link', 'node' => 'element', 'required' => true, 'fmt' => 'url'],
            ['slot' => 'g:image_link', 'node' => 'element', 'required' => true, 'fmt' => 'url'],
            ['slot' => 'g:additional_image_link', 'node' => 'repeatable', 'element' => 'g:additional_image_link', 'fmt' => 'url'],
            ['slot' => 'g:availability', 'node' => 'element', 'required' => true, 'fmt' => 'enum', 'enums' => ['in stock', 'out of stock', 'preorder']],
            ['slot' => 'g:price', 'node' => 'element', 'required' => true, 'fmt' => 'price'],
            ['slot' => 'g:brand', 'node' => 'element', 'required' => true, 'fmt' => 'text'],
            ['slot' => 'g:condition', 'node' => 'element', 'required' => true, 'fmt' => 'enum', 'enums' => ['new', 'refurbished', 'used']],
            ['slot' => 'g:google_product_category', 'node' => 'element', 'fmt' => 'category'],
        ];

        $descriptor = [
            'root' => ['element' => 'rss', 'attributes' => ['version' => '2.0'], 'namespaces' => ['g' => 'http://base.google.com/ns/1.0']],
            'channel' => [
                'element' => 'channel',
                'header' => [['element' => 'title', 'source' => ['kind' => 'static', 'value' => '{feed_name}']]],
                'item' => ['element' => 'item', 'slots' => $slots],
            ],
        ];

        $mappings = [
            ['slot' => 'g:id', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'g:title', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
            ['slot' => 'g:description', 'source' => ['kind' => 'attribute', 'ref' => 'description'], 'transform' => ['kind' => 'strip_html']],
            ['slot' => 'g:link', 'source' => ['kind' => 'template', 'value' => '{store_url}/p/{url_slug}']],
            ['slot' => 'g:image_link', 'source' => ['kind' => 'attribute', 'ref' => 'main_image']],
            ['slot' => 'g:additional_image_link', 'source' => ['kind' => 'attribute', 'ref' => 'gallery']],
            ['slot' => 'g:availability', 'source' => ['kind' => 'attribute', 'ref' => 'stock_qty'], 'transform' => ['kind' => 'enum_map', 'rule' => 'meta_stock']],
            ['slot' => 'g:price', 'source' => ['kind' => 'attribute', 'ref' => 'price_gross'], 'transform' => ['kind' => 'price']],
            ['slot' => 'g:brand', 'source' => ['kind' => 'attribute', 'ref' => 'brand']],
            ['slot' => 'g:condition', 'source' => ['kind' => 'static', 'value' => 'new']],
            ['slot' => 'g:google_product_category', 'source' => ['kind' => 'attribute', 'ref' => 'google_category']],
        ];

        return new FeedTemplate(FeedTemplateKind::Meta, $descriptor, $mappings);
    }

    private function custom(): FeedTemplate
    {
        // Blank starter — a minimal well-formed shape the structure editor
        // (XMLF-P2-07) grows from.
        $slots = [
            ['target' => 'sku', 'node' => 'element', 'element' => 'sku', 'required' => true, 'fmt' => 'text'],
            ['target' => 'name', 'node' => 'element', 'element' => 'name', 'required' => true, 'maxLength' => 255, 'fmt' => 'text'],
        ];

        $descriptor = [
            'root' => ['element' => 'products'],
            'item' => ['element' => 'product', 'slots' => $slots],
        ];

        $mappings = [
            ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'name', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
        ];

        return new FeedTemplate(FeedTemplateKind::Custom, $descriptor, $mappings);
    }
}
