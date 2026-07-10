<?php

namespace pixelwerft\quickpoll\services;

use Craft;
use craft\base\Element;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use pixelwerft\quickpoll\elements\Poll;
use pixelwerft\quickpoll\QuickPoll;
use yii\base\Component;
use yii\web\Cookie;
use yii\web\ForbiddenHttpException;

/**
 * Casting and dedup logic. The only writer to quickpoll_votes.
 */
class VoteService extends Component
{
    public const TABLE = '{{%quickpoll_votes}}';
    private const COOKIE_NAME = 'qp_vid';

    /** Poll types whose submission is at most one option. */
    private const SINGLE = ['rating', 'mood'];

    /**
     * Persist a ballot. Re-voting replaces the voter's previous ballot for this
     * poll/target (friendly engagement behaviour). Returns the resolved voterHash.
     *
     * @param array $ballot list of ['optionKey' => string, 'value' => ?string]
     * @param int $targetId 0 = poll-level; else the element the poll is attached to
     * @throws ForbiddenHttpException when a members-only poll is voted anonymously
     * @throws \InvalidArgumentException on a closed poll or invalid option
     */
    public function cast(Poll $poll, array $ballot, int $targetId = 0): string
    {
        if (!$this->isOpen($poll)) {
            throw new \InvalidArgumentException('This poll is closed.');
        }

        $voterHash = $this->resolveVoterHash($poll);
        $ballot = $this->sanitizeBallot($poll, $ballot);
        if ($ballot === []) {
            throw new \InvalidArgumentException('No valid option submitted.');
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $now = Db::prepareDateForDb(new DateTime());
        $db = Craft::$app->getDb();

        $transaction = $db->beginTransaction();
        try {
            // Replace-on-revote: clear the voter's prior ballot for this poll+target.
            $db->createCommand()
                ->delete(self::TABLE, [
                    'pollId'    => $poll->id,
                    'targetId'  => $targetId,
                    'voterHash' => $voterHash,
                ])
                ->execute();

            foreach ($ballot as $row) {
                $db->createCommand()->insert(self::TABLE, [
                    'pollId'      => $poll->id,
                    'targetId'    => $targetId,
                    'siteId'      => $siteId,
                    'optionKey'   => $row['optionKey'],
                    'value'       => $row['value'],
                    'voterHash'   => $voterHash,
                    'dateCreated' => $now,
                    'uid'         => StringHelper::UUID(),
                ])->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $voterHash;
    }

    /**
     * Whether the current voter has already cast a ballot for this poll/target.
     */
    public function hasVoted(Poll $poll, int $targetId = 0): bool
    {
        $hash = $this->peekVoterHash($poll);
        if ($hash === null) {
            return false;
        }
        return (new \craft\db\Query())
            ->from(self::TABLE)
            ->where(['pollId' => $poll->id, 'targetId' => $targetId, 'voterHash' => $hash])
            ->exists();
    }

    /**
     * The current visitor's existing ballot for this poll/target, if any.
     * Read-only — never mints a cookie, never throws. Used to pre-select the
     * re-vote form. Returns raw rows.
     *
     * @return array<int,array{optionKey:string,value:?string}>
     */
    public function currentBallot(Poll $poll, int $targetId = 0): array
    {
        $hash = $this->peekVoterHash($poll);
        if ($hash === null) {
            return [];
        }
        return (new \craft\db\Query())
            ->select(['optionKey', 'value'])
            ->from(self::TABLE)
            ->where(['pollId' => $poll->id, 'targetId' => $targetId, 'voterHash' => $hash])
            ->all();
    }

    public function isOpen(Poll $poll): bool
    {
        if ($poll->getStatus() !== Element::STATUS_ENABLED) {
            return false;
        }
        $until = $poll->openUntil ?? null;
        if ($until instanceof DateTime && $until < new DateTime()) {
            return false;
        }
        return true;
    }

    /* --------------------------------------------------------------------- */

    /**
     * Resolve the identity used for dedup. Throws for members-only polls voted
     * by a guest. For public polls, mints/reads a stable per-browser cookie id.
     */
    private function resolveVoterHash(Poll $poll): string
    {
        if ((((string) $poll->pollAccess) ?: 'public') === 'members') {
            $user = Craft::$app->getUser()->getIdentity();
            if ($user === null) {
                throw new ForbiddenHttpException('This poll is for members only.');
            }
            return 'u:' . $user->id;
        }

        return $this->anonymousHash($this->mintCookieId());
    }

    /**
     * Like resolveVoterHash but never mints a cookie and never throws — used by
     * read-only "have I voted?" checks.
     */
    private function peekVoterHash(Poll $poll): ?string
    {
        if ((((string) $poll->pollAccess) ?: 'public') === 'members') {
            $user = Craft::$app->getUser()->getIdentity();
            return $user ? 'u:' . $user->id : null;
        }
        $cookieId = Craft::$app->getRequest()->getCookies()->getValue(self::COOKIE_NAME);
        return $cookieId ? $this->anonymousHash($cookieId) : null;
    }

    private function anonymousHash(string $cookieId): string
    {
        $pepper = QuickPoll::getInstance()->getSettings()->voterPepper
            ?: Craft::$app->getConfig()->getGeneral()->securityKey;
        $ip = Craft::$app->getRequest()->getUserIP() ?? '';
        return hash('sha256', $ip . '|' . $cookieId . '|' . $pepper);
    }

    private function mintCookieId(): string
    {
        $request = Craft::$app->getRequest();
        $existing = $request->getCookies()->getValue(self::COOKIE_NAME);
        if ($existing) {
            return $existing;
        }
        $id = StringHelper::UUID();
        $cookie = new Cookie(Craft::cookieConfig([
            'name'     => self::COOKIE_NAME,
            'value'    => $id,
            'expire'   => time() + 60 * 60 * 24 * 365,
            'httpOnly' => true,
            'sameSite' => Cookie::SAME_SITE_LAX,
        ]));
        Craft::$app->getResponse()->getCookies()->add($cookie);
        return $id;
    }

    /**
     * Validate & normalise the submitted ballot against the poll definition.
     */
    private function sanitizeBallot(Poll $poll, array $ballot): array
    {
        // Dropdown fields return SingleOptionFieldData — cast before comparing.
        $type = ((string) $poll->pollType) ?: 'choice';
        $out = [];

        if ($type === 'rating') {
            $key = (string) ($ballot[0]['optionKey'] ?? '');
            if (in_array($key, ['1', '2', '3', '4', '5'], true)) {
                $out[] = ['optionKey' => $key, 'value' => null];
            }
            return $out;
        }

        $optionCount = $this->optionCount($poll);

        if ($type === 'grid') {
            foreach ($ballot as $row) {
                $key = (string) ($row['optionKey'] ?? '');
                $val = (string) ($row['value'] ?? '');
                if (ctype_digit($key) && (int) $key < $optionCount
                    && in_array($val, ['yes', 'maybe', 'no'], true)) {
                    $out[] = ['optionKey' => $key, 'value' => $val];
                }
            }
            return $out;
        }

        // choice / mood
        $multi = $type === 'choice' && (bool) $poll->multiSelect;
        foreach ($ballot as $row) {
            $key = (string) ($row['optionKey'] ?? '');
            if (ctype_digit($key) && (int) $key < $optionCount) {
                $out[] = ['optionKey' => $key, 'value' => null];
                if (!$multi) {
                    break; // single-choice: keep only the first valid pick
                }
            }
        }
        return $out;
    }

    private function optionCount(Poll $poll): int
    {
        return count($poll->getOptions());
    }
}
