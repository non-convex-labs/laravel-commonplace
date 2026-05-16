<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Services;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class FrontmatterParser
{
    public function parse(string $content): array
    {
        if (! preg_match('/\A---\s*\n(.*?)---\s*\n?(.*)\z/s', $content, $matches)) {
            return ['meta' => [], 'body' => $content];
        }

        try {
            $parsed = Yaml::parse($matches[1]);
        } catch (ParseException) {
            return ['meta' => [], 'body' => $content];
        }

        if (! is_array($parsed)) {
            return ['meta' => [], 'body' => $matches[2]];
        }

        $recognized = ['title', 'visibility', 'tags'];
        $meta = array_intersect_key($parsed, array_flip($recognized));

        if (isset($meta['tags']) && ! is_array($meta['tags'])) {
            $meta['tags'] = [$meta['tags']];
        }

        if (isset($meta['title'])) {
            $meta['title'] = (string) $meta['title'];
        }

        if (isset($meta['visibility'])) {
            $meta['visibility'] = (string) $meta['visibility'];
        }

        return ['meta' => $meta, 'body' => $matches[2]];
    }
}
