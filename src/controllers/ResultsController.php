<?php

namespace pixelwerft\quickpoll\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\quickpoll\elements\Poll;
use pixelwerft\quickpoll\QuickPoll;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * GET actions/quick-poll/results/show?pollId=123[&targetId=456]
 *
 * Returns aggregated results as JSON. Honours the poll's result visibility:
 * when the viewer is not yet allowed to see results, `canSee` is false and the
 * counts are withheld.
 */
class ResultsController extends Controller
{
    protected array|bool|int $allowAnonymous = ['show'];

    public function actionShow(): Response
    {
        $request = Craft::$app->getRequest();
        $pollId = (int) $request->getRequiredQueryParam('pollId');
        $targetId = (int) $request->getQueryParam('targetId', 0);
        $poll = $this->findPoll($pollId);

        $results = QuickPoll::getInstance()->results;
        $canSee = $results->canSee($poll, $targetId);

        $headers = Craft::$app->getResponse()->getHeaders();
        $cache = QuickPoll::getInstance()->getSettings()->resultsCacheDuration;
        $headers->set('Cache-Control', $cache > 0 ? "public, max-age={$cache}" : 'no-store, private');

        return $this->asJson([
            'success'  => true,
            'canSee'   => $canSee,
            'open'     => $results->isOpen($poll),
            'hasVoted' => $results->hasVoted($poll, $targetId),
            'results'  => $canSee ? $results->forPoll($poll, null, $targetId) : null,
        ]);
    }

    private function findPoll(int $id): Poll
    {
        $poll = Poll::find()->id($id)->status(null)->one();
        if (!$poll) {
            throw new NotFoundHttpException('Poll not found.');
        }
        return $poll;
    }
}
