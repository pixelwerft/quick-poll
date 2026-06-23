<?php

namespace pixelwerft\quickpoll\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\quickpoll\elements\Poll;
use pixelwerft\quickpoll\QuickPoll;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * POST actions/quick-poll/vote/submit
 *
 * Body:
 *   pollId   int
 *   targetId int                   (optional; 0 = poll-level, else attached element)
 *   options  string[]              (option keys; "1".."5" for rating)
 *   values   array<string,string>  (optional; for grid polls: key => yes|maybe|no)
 */
class VoteController extends Controller
{
    protected array|bool|int $allowAnonymous = ['submit'];

    public function actionSubmit(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $pollId = (int) $request->getRequiredBodyParam('pollId');
        $targetId = (int) $request->getBodyParam('targetId', 0);
        $poll = $this->findPoll($pollId);

        $optionKeys = (array) $request->getBodyParam('options', []);
        $values = (array) $request->getBodyParam('values', []);

        // Ballot = union of explicit options[] and the keys of the values map.
        // Grid polls submit only `values` (key => yes|maybe|no), so deriving the
        // option keys from there keeps the no-JS form fallback working too.
        $keys = [];
        foreach ($optionKeys as $k) {
            $keys[(string) $k] = true;
        }
        foreach (array_keys($values) as $k) {
            $keys[(string) $k] = true;
        }

        $ballot = [];
        foreach (array_keys($keys) as $key) {
            $ballot[] = [
                'optionKey' => $key,
                'value'     => isset($values[$key]) ? (string) $values[$key] : null,
            ];
        }

        try {
            QuickPoll::getInstance()->votes->cast($poll, $ballot, $targetId);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $results = QuickPoll::getInstance()->results;
        $payload = [
            'success'  => true,
            'results'  => $results->forPoll($poll, null, $targetId),
            'canSee'   => $results->canSee($poll, $targetId),
            'hasVoted' => true,
        ];

        if ($request->getAcceptsJson()) {
            // Voting must never be cached by upstream proxies (Hostpoint caches
            // aggressively, including error responses).
            Craft::$app->getResponse()->getHeaders()->set('Cache-Control', 'no-store, private');
            return $this->asJson($payload);
        }

        Craft::$app->getSession()->setNotice(Craft::t('quick-poll', 'Thanks for voting!'));
        return $this->redirectToPostedUrl();
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
