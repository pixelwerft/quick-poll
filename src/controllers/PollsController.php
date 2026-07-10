<?php

namespace pixelwerft\quickpoll\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\web\Controller;
use pixelwerft\quickpoll\elements\Poll;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP CRUD for Poll elements. Plugin-managed edit screens (no field layout),
 * with a per-site editor so the question + options can be translated.
 */
class PollsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-quick-poll');
        return true;
    }

    public function actionEdit(?int $pollId = null, ?string $site = null): Response
    {
        $sitesService = Craft::$app->getSites();
        $siteModel = $site ? $sitesService->getSiteByHandle($site) : $sitesService->getCurrentSite();
        if (!$siteModel) {
            throw new NotFoundHttpException('Site not found.');
        }

        if ($pollId) {
            $poll = Poll::find()->id($pollId)->siteId($siteModel->id)->status(null)->one();
            if (!$poll) {
                throw new NotFoundHttpException('Poll not found.');
            }
        } else {
            $poll = new Poll();
            $poll->siteId = $siteModel->id;
        }

        return $this->renderTemplate('quick-poll/polls/_edit', [
            'poll' => $poll,
            'site' => $siteModel,
            'isNew' => !$pollId,
            'title' => $pollId ? $poll->title : Craft::t('quick-poll', 'New poll'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $pollId = $request->getBodyParam('pollId');
        $siteId = (int) $request->getBodyParam('siteId', Craft::$app->getSites()->getCurrentSite()->id);

        if ($pollId) {
            $poll = Poll::find()->id($pollId)->siteId($siteId)->status(null)->one();
            if (!$poll) {
                throw new NotFoundHttpException('Poll not found.');
            }
        } else {
            $poll = new Poll();
            $poll->siteId = $siteId;
        }

        $poll->title = $request->getBodyParam('title');
        $poll->pollType = $request->getBodyParam('pollType', 'choice');
        $poll->pollAccess = $request->getBodyParam('pollAccess', 'public');
        $poll->multiSelect = (bool) $request->getBodyParam('multiSelect', false);
        $poll->resultsVisibility = $request->getBodyParam('resultsVisibility', 'afterVote');
        $poll->showShare = (bool) $request->getBodyParam('showShare', false);
        $poll->hideAfterClose = (bool) $request->getBodyParam('hideAfterClose', false);
        $poll->allowRevote = (bool) $request->getBodyParam('allowRevote', false);
        $poll->resultText = $request->getBodyParam('resultText') ?: null;
        $poll->setCategoryIds($request->getBodyParam('categoryIds', []));

        $openUntil = $request->getBodyParam('openUntil');
        $poll->openUntil = $openUntil ? DateTimeHelper::toDateTime($openUntil) ?: null : null;

        // Options come in as a textarea, one per line.
        $raw = (string) $request->getBodyParam('options', '');
        $options = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $raw))), fn($l) => $l !== ''));
        $poll->setOptions($options);

        if (!Craft::$app->getElements()->saveElement($poll)) {
            Craft::$app->getSession()->setError(Craft::t('quick-poll', 'Couldn’t save poll.'));
            Craft::$app->getUrlManager()->setRouteParams(['poll' => $poll]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('quick-poll', 'Poll saved.'));
        return $this->redirectToPostedUrl($poll);
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();
        $pollId = (int) Craft::$app->getRequest()->getRequiredBodyParam('pollId');

        $poll = Poll::find()->id($pollId)->status(null)->one();
        if (!$poll) {
            throw new NotFoundHttpException('Poll not found.');
        }

        // Native Craft duplication: clones the element + per-site titles, runs
        // afterSave (which writes our settings row + seeds site content).
        $new = Craft::$app->getElements()->duplicateElement($poll);

        // Craft's duplicator doesn't know our per-site tables, so the seeded
        // options/result text are the source site's for every language. Copy the
        // original's real per-site content over so each language is preserved.
        $now = Db::prepareDateForDb(new \DateTime());
        $rows = (new Query())
            ->select(['siteId', 'options', 'resultText'])
            ->from('{{%quickpoll_polls_sites}}')
            ->where(['pollId' => $poll->id])
            ->all();
        foreach ($rows as $row) {
            Db::update('{{%quickpoll_polls_sites}}', [
                'options' => $row['options'],
                'resultText' => $row['resultText'],
                'dateUpdated' => $now,
            ], ['pollId' => $new->id, 'siteId' => $row['siteId']]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('quick-poll', 'Poll copied.'));
        return $this->redirect('quick-poll/polls/' . $new->id);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $pollId = (int) Craft::$app->getRequest()->getRequiredBodyParam('pollId');
        $poll = Poll::find()->id($pollId)->status(null)->one();
        if ($poll) {
            Craft::$app->getElements()->deleteElement($poll);
            Craft::$app->getSession()->setNotice(Craft::t('quick-poll', 'Poll deleted.'));
        }
        return $this->redirect('quick-poll');
    }
}
