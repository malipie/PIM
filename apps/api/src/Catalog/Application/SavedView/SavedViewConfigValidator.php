<?php

declare(strict_types=1);

namespace App\Catalog\Application\SavedView;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * GRID-P4-01 (#2394) — validates the structured `SavedView.config`
 * envelope the grid persists (filters + columns + sort + density +
 * variants mode + page size).
 *
 * `config` used to be an unvalidated JSONB blob ("operator
 * responsibility"); once the grid round-trips columns/sort/density
 * through it (GRID-P4-02), a malformed entry would break the list
 * render for the whole view. This validator is the guard: unknown
 * top-level keys and wrong value types fail with RFC 7807 Problem
 * Details BEFORE the row is written.
 *
 * Backward compatible: every key is optional, so legacy configs
 * (`{filters, variants_mode, page_size}`) validate unchanged. The
 * canonical shape is documented in docs/api/jsonb-schemas.md.
 */
final class SavedViewConfigValidator
{
    private const array ALLOWED_KEYS = [
        'filters',
        'sort',
        'columns',
        'density',
        'variants_mode',
        'page_size',
    ];

    private const array ALLOWED_DENSITY = ['compact', 'normal'];
    private const array ALLOWED_VARIANTS_MODE = ['tree', 'flat'];
    private const array ALLOWED_SORT_DIR = ['asc', 'desc'];

    /**
     * @param array<string, mixed> $config
     *
     * @throws BadRequestHttpException on any unknown key or type mismatch
     */
    public function validate(array $config): void
    {
        foreach (array_keys($config) as $key) {
            if (!\in_array($key, self::ALLOWED_KEYS, true)) {
                throw new BadRequestHttpException(\sprintf('Unknown config key "%s".', $key));
            }
        }

        if (\array_key_exists('filters', $config) && !\is_array($config['filters'])) {
            throw new BadRequestHttpException('config.filters must be an object or array.');
        }

        if (\array_key_exists('sort', $config)) {
            $this->validateSort($config['sort']);
        }

        if (\array_key_exists('columns', $config)) {
            $this->validateColumns($config['columns']);
        }

        if (\array_key_exists('density', $config)
            && !\in_array($config['density'], self::ALLOWED_DENSITY, true)) {
            throw new BadRequestHttpException('config.density must be "compact" or "normal".');
        }

        if (\array_key_exists('variants_mode', $config)
            && !\in_array($config['variants_mode'], self::ALLOWED_VARIANTS_MODE, true)) {
            throw new BadRequestHttpException('config.variants_mode must be "tree" or "flat".');
        }

        if (\array_key_exists('page_size', $config)
            && (!\is_int($config['page_size']) || $config['page_size'] < 1)) {
            throw new BadRequestHttpException('config.page_size must be a positive integer.');
        }
    }

    private function validateSort(mixed $sort): void
    {
        if (!\is_array($sort)) {
            throw new BadRequestHttpException('config.sort must be an object.');
        }
        if (!isset($sort['key']) || !\is_string($sort['key']) || '' === $sort['key']) {
            throw new BadRequestHttpException('config.sort.key must be a non-empty string.');
        }
        if (!isset($sort['dir']) || !\in_array($sort['dir'], self::ALLOWED_SORT_DIR, true)) {
            throw new BadRequestHttpException('config.sort.dir must be "asc" or "desc".');
        }
    }

    private function validateColumns(mixed $columns): void
    {
        if (!\is_array($columns)) {
            throw new BadRequestHttpException('config.columns must be an array.');
        }
        foreach ($columns as $column) {
            if (!\is_array($column) || !isset($column['key']) || !\is_string($column['key']) || '' === $column['key']) {
                throw new BadRequestHttpException('Each config.columns entry needs a non-empty string "key".');
            }
            if (\array_key_exists('width', $column)
                && (!\is_int($column['width']) || $column['width'] < 1)) {
                throw new BadRequestHttpException('config.columns[].width must be a positive integer.');
            }
            if (\array_key_exists('hidden', $column) && !\is_bool($column['hidden'])) {
                throw new BadRequestHttpException('config.columns[].hidden must be a boolean.');
            }
            if (\array_key_exists('pinned', $column) && !\is_bool($column['pinned'])) {
                throw new BadRequestHttpException('config.columns[].pinned must be a boolean.');
            }
        }
    }
}
