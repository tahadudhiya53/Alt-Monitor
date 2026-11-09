<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\elements\User;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Reading AltMonitor's controller input strictly: malformed values are refused, never coerced.
 */
trait RequestInput
{
    /**
     * @throws ForbiddenHttpException when nobody is signed in.
     */
    protected function user(): User
    {
        return static::currentUser() ?? throw new ForbiddenHttpException();
    }

    /**
     * Keeps a bulk action's failures for the page it redirects to.
     *
     * @param list<array{key: string, reason: string}> $failures
     */
    protected function keepFailures(array $failures): void
    {
        if (!$this->request->getAcceptsJson() && Craft::$app->has('session')) {
            Craft::$app->getSession()->setFlash('altmonitor-failures', $failures);
        }
    }

    /**
     * @return list<array{key: string, reason: string}>
     */
    protected function failures(): array
    {
        return Craft::$app->has('session') ? (array)Craft::$app->getSession()->getFlash('altmonitor-failures', []) : [];
    }

    /**
     * @throws BadRequestHttpException
     */
    protected function positiveInt(string $name): int
    {
        $value = $this->request->getBodyParam($name);

        if (!is_scalar($value) || !preg_match('/^[1-9]\d{0,9}$/', (string)$value)) {
            throw new BadRequestHttpException("The “{$name}” parameter must be a positive whole number.");
        }

        return (int)$value;
    }

    /**
     * @throws BadRequestHttpException
     */
    protected function text(string $name): string
    {
        $value = $this->request->getBodyParam($name, '');

        if (!is_string($value)) {
            throw new BadRequestHttpException("The “{$name}” parameter must be text.");
        }

        return $value;
    }
}
