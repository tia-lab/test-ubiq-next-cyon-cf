<?php

namespace modules\headless\cache_revalidation;

use Craft;
use craft\helpers\App;
use Throwable;

class NextRevalidator
{
    /**
     * @param string[] $tags
     */
    public static function send(array $tags): void
    {
        $url = App::env('CRAFT_REVALIDATE_URL');
        $secret = App::env('REVALIDATE_SECRET');

        if (!$url || !$secret) {
            Craft::info('Headless cache revalidation skipped; URL or secret is missing.', __METHOD__);
            return;
        }

        $tags = array_values(array_unique(array_filter($tags)));
        if ($tags === []) {
            return;
        }

        try {
            $response = Craft::createGuzzleClient([
                'connect_timeout' => 1.0,
                'timeout' => 2.0,
            ])->post($url, [
                'json' => [
                    'secret' => $secret,
                    'tags' => $tags,
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                Craft::warning(
                    sprintf('Headless cache revalidation failed with HTTP %s.', $response->getStatusCode()),
                    __METHOD__,
                );
                return;
            }

            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                Craft::info('Headless cache revalidation sent: ' . implode(', ', $tags), __METHOD__);
            }
        } catch (Throwable $exception) {
            Craft::warning('Headless cache revalidation failed: ' . $exception->getMessage(), __METHOD__);
        }
    }
}
