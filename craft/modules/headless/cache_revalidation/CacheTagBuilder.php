<?php

namespace modules\headless\cache_revalidation;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use Throwable;

class CacheTagBuilder
{
    /**
     * @return string[]
     */
    public static function forElement(ElementInterface $element): array
    {
        $tags = ['craft'];

        if ($element instanceof Entry) {
            self::addEntryTags($tags, $element);
        } elseif ($element instanceof GlobalSet) {
            self::addGlobalSetTags($tags, $element);
        } elseif ($element instanceof Asset) {
            self::addAssetTags($tags, $element);
        } else {
            return [];
        }

        return self::normalize($tags);
    }

    /**
     * @param string[] $tags
     */
    private static function addEntryTags(array &$tags, Entry $entry): void
    {
        $tags[] = 'craft:entries';

        if ($entry->id) {
            $tags[] = "craft:entry:$entry->id";
        }

        if ($entry->uri) {
            $tags[] = "craft:entry-uri:$entry->uri";
        }

        $sectionHandle = self::entrySectionHandle($entry);
        if ($sectionHandle) {
            $tags[] = "craft:section:$sectionHandle";
        }

        $typeHandle = self::entryTypeHandle($entry);
        if ($typeHandle) {
            $tags[] = "craft:entry-type:$typeHandle";
        }

        match ($sectionHandle) {
            'pages' => self::addPageTags($tags, $entry),
            'news' => self::addNewsTags($tags, $entry),
            'navigations' => self::addNavigationTags($tags, $entry),
            default => null,
        };

        if ($entry instanceof NestedElementInterface) {
            self::addNestedEntryTags($tags, $entry);
        }
    }

    /**
     * @param string[] $tags
     */
    private static function addPageTags(array &$tags, Entry $entry): void
    {
        $tags[] = 'craft:pages';

        if ($entry->id) {
            $tags[] = "craft:page:$entry->id";
        }

        if ($entry->uri) {
            $tags[] = "craft:page-uri:$entry->uri";
        }
    }

    /**
     * @param string[] $tags
     */
    private static function addNewsTags(array &$tags, Entry $entry): void
    {
        $tags[] = 'craft:news';

        if ($entry->id) {
            $tags[] = "craft:news:$entry->id";
        }

        if ($entry->uri) {
            $tags[] = "craft:news-uri:$entry->uri";
        }
    }

    /**
     * @param string[] $tags
     */
    private static function addNavigationTags(array &$tags, Entry $entry): void
    {
        $tags[] = 'craft:navigation';

        $handle = self::navigationHandle($entry);
        if ($handle) {
            $tags[] = "craft:navigation:$handle";
        }
    }

    /**
     * @param string[] $tags
     */
    private static function addNestedEntryTags(array &$tags, Entry&NestedElementInterface $entry): void
    {
        if (!$entry->getOwnerId() && !$entry->getPrimaryOwnerId()) {
            return;
        }

        $tags[] = 'craft:sections';

        if ($entry->id) {
            $tags[] = "craft:section-entry:$entry->id";
        }

        try {
            $owner = $entry->getPrimaryOwner() ?? $entry->getOwner();
        } catch (Throwable) {
            $owner = null;
        }

        if ($owner instanceof Entry) {
            self::addEntryTags($tags, $owner);
            return;
        }

        $tags[] = 'craft:pages';
    }

    /**
     * @param string[] $tags
     */
    private static function addGlobalSetTags(array &$tags, GlobalSet $globalSet): void
    {
        $tags[] = 'craft:globals';

        if ($globalSet->handle) {
            $tags[] = "craft:global:$globalSet->handle";
        }
    }

    /**
     * @param string[] $tags
     */
    private static function addAssetTags(array &$tags, Asset $asset): void
    {
        $tags[] = 'craft:assets';
        $tags[] = 'craft:entries';

        if ($asset->id) {
            $tags[] = "craft:asset:$asset->id";
        }

        try {
            $volume = $asset->getVolume();
        } catch (Throwable) {
            $volume = null;
        }

        if ($volume?->handle) {
            $tags[] = "craft:asset-volume:$volume->handle";
        }
    }

    private static function entrySectionHandle(Entry $entry): ?string
    {
        try {
            return $entry->getSection()?->handle;
        } catch (Throwable) {
            return null;
        }
    }

    private static function entryTypeHandle(Entry $entry): ?string
    {
        try {
            return $entry->getType()->handle;
        } catch (Throwable) {
            return null;
        }
    }

    private static function navigationHandle(Entry $entry): ?string
    {
        try {
            $value = $entry->getFieldValue('navigationHandle');
        } catch (Throwable) {
            $value = null;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $entry->slug ?: $entry->uri ?: $entry->title;
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    private static function normalize(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag));
            $tag = preg_replace('/\s+/', '-', $tag) ?? '';
            $tag = preg_replace('/[^a-z0-9:_-]/', '-', $tag) ?? '';
            $tag = preg_replace('/-+/', '-', $tag) ?? '';
            $tag = trim($tag, '-:_');

            if ($tag === '' || strlen($tag) > 256) {
                continue;
            }

            $normalized[] = $tag;
        }

        return array_slice(array_values(array_unique($normalized)), 0, 128);
    }
}
