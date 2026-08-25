<?php

namespace modules\headless;

use Craft;
use craft\events\ElementEvent;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use modules\headless\cache_revalidation\CacheTagBuilder;
use modules\headless\cache_revalidation\NextRevalidator;
use yii\base\Module;

class HeadlessModule extends Module
{
    public function init(): void
    {
        Craft::setAlias('@modules/headless', __DIR__);

        parent::init();

        $this->registerCacheRevalidation();
    }

    private function registerCacheRevalidation(): void
    {
        $elements = Craft::$app->getElements();

        $elements->on(
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            fn(ElementEvent $event) => $this->handleElementEvent($event),
        );

        $elements->on(
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            fn(ElementEvent $event) => $this->handleElementEvent($event),
        );
    }

    private function handleElementEvent(ElementEvent $event): void
    {
        $element = $event->element;

        if (ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        $tags = CacheTagBuilder::forElement($element);

        if ($tags === []) {
            return;
        }

        NextRevalidator::send($tags);
    }
}
