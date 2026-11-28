<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use InvalidArgumentException;
use Tahadudhiya\AltMonitor\models\ListCriteria;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Reading AltMonitor's controller input strictly: malformed values are refused, never coerced.
 */
trait RequestInput
{
    /** A positive whole number that fits a database integer column. */
    private const POSITIVE_INT = '/^[1-9]\d{0,9}$/';

    /**
     * @throws ForbiddenHttpException when nobody is signed in.
     */
    protected function user(): User
    {
        return static::currentUser() ?? throw new ForbiddenHttpException();
    }

    /**
     * A bulk action's failures as people read them: each with its image's file name.
     *
     * @param array<int|string, string> $failures Why each failed, by row key.
     * @param array<int|string, int> $assetIds The image of each row key.
     * @return list<array{key: string, name: string, reason: string}>
     */
    protected function failureList(array $failures, array $assetIds): array
    {
        $names = $assetIds === [] ? [] : (new Query())
            ->select(['filename', 'id'])
            ->from(Table::ASSETS)
            ->where(['id' => array_values(array_unique($assetIds))])
            ->indexBy('id')
            ->column();
        $list = [];

        foreach ($failures as $key => $reason) {
            $list[] = ['key' => (string)$key, 'name' => (string)($names[$assetIds[$key] ?? 0] ?? $key), 'reason' => $reason];
        }

        return $list;
    }

    /**
     * Keeps a bulk action's failures for the page it redirects to.
     *
     * @param list<array{key: string, name: string, reason: string}> $failures
     */
    protected function keepFailures(array $failures): void
    {
        if (!$this->request->getAcceptsJson() && Craft::$app->has('session')) {
            Craft::$app->getSession()->setFlash('altmonitor-failures', $failures);
        }
    }

    /**
     * @return list<array{key: string, name?: string, reason: string}>
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

        if (!is_scalar($value) || !preg_match(self::POSITIVE_INT, (string)$value)) {
            throw new BadRequestHttpException("The “{$name}” parameter must be a positive whole number.");
        }

        return (int)$value;
    }

    /**
     * A positive whole number from the query string.
     *
     * @throws BadRequestHttpException
     */
    protected function queryInt(string $name): int
    {
        $value = $this->request->getQueryParam($name);

        if (!is_string($value) || !preg_match(self::POSITIVE_INT, $value)) {
            throw new BadRequestHttpException("The “{$name}” parameter must be a positive whole number.");
        }

        return (int)$value;
    }

    /**
     * A list's filters from the query string.
     *
     * @throws BadRequestHttpException when one is malformed.
     */
    protected function criteria(): ListCriteria
    {
        try {
            return ListCriteria::fromParams($this->request->getQueryParams());
        } catch (InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }
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
