<?php

namespace pixelwerft\quickpoll\services;

use Craft;
use craft\db\Query;
use craft\elements\Category;
use pixelwerft\quickpoll\elements\Poll;
use pixelwerft\quickpoll\QuickPoll;
use yii\base\Component;

/**
 * Read-only aggregation + visibility rules. Exposed to Twig as `craft.quickPoll`.
 *
 * All read methods accept an optional $targetId (0 = poll-level). This lets one
 * poll definition be attached to many entries with per-target results.
 */
class ResultsService extends Component
{
    /**
     * Resolve a poll element by id, for attaching to a target entry:
     *   {% include 'quick-poll/widget' with {
     *        poll: craft.quickPoll.poll(123), target: entry } %}
     */
    public function poll(int $id): ?Poll
    {
        return Poll::find()->id($id)->status(null)->one();
    }

    /**
     * Published URL of the base stylesheet — for templates that override the
     * widget and want to load the base CSS themselves:
     *   <link rel="stylesheet" href="{{ craft.quickPoll.baseCssUrl }}">
     */
    /**
     * Polls assigned to a category (by id or slug) — for front-end listing:
     *   {% for poll in craft.quickPoll.byCategory('blitz-umfragen') %}…{% endfor %}
     *
     * @return Poll[]
     */
    public function byCategory(int|string $category, ?int $siteId = null): array
    {
        $catId = is_numeric($category)
            ? (int) $category
            : (Category::find()->slug($category)->siteId($siteId)->ids()[0] ?? null);

        if (!$catId) {
            return [];
        }

        return Poll::find()
            ->category($catId)
            ->siteId($siteId)
            ->orderBy(['elements.dateCreated' => SORT_DESC])
            ->all();
    }

    public function baseCssUrl(): string
    {
        return Craft::$app->getAssetManager()->getPublishedUrl(
            '@pixelwerft/quickpoll/resources',
            true,
            'poll.css'
        );
    }

    /**
     * All polls (any status), newest first — for the CP overview.
     *
     * @return Poll[]
     */
    public function allPolls(?int $categoryId = null): array
    {
        $query = Poll::find()
            ->status(null)
            ->orderBy(['elements.dateCreated' => SORT_DESC]);

        if ($categoryId) {
            $query->category($categoryId);
        }

        return $query->all();
    }

    /**
     * Distinct voter count per poll (across all targets), for the CP overview.
     *
     * @return array<int,int> pollId => voters
     */
    public function votersByPoll(): array
    {
        $rows = (new Query())
            ->select(['pollId', 'c' => 'COUNT(DISTINCT voterHash)'])
            ->from(VoteService::TABLE)
            ->groupBy(['pollId'])
            ->all();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['pollId']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * Aggregated, template-ready results for a poll (+ optional target).
     *
     * Non-grid shape: {type, total, options:[{key,label,count,pct}], average}
     * Grid shape:     {type:'grid', total, options:[{key,label,yes,maybe,no,
     *                  responses,yesPct,maybePct,noPct,score}], best}
     */
    public function forPoll(Poll $poll, ?int $siteId = null, int $targetId = 0): array
    {
        // Dropdown fields return a SingleOptionFieldData object, not a string —
        // cast before comparing or strict checks silently fail.
        $type = ((string) $poll->pollType) ?: 'choice';

        if ($type === 'grid') {
            return $this->buildGrid($poll, $siteId, $targetId);
        }

        $counts = $this->counts($poll->id, $siteId, $targetId);   // optionKey => count
        $total = array_sum($counts);

        $options = [];
        foreach ($this->optionKeys($poll) as $key => $label) {
            $count = $counts[(string) $key] ?? 0;
            $options[] = [
                'key'   => (string) $key,
                'label' => $label,
                'count' => $count,
                'pct'   => $total > 0 ? round($count * 100 / $total, 1) : 0.0,
            ];
        }

        // Segmented-pill display is a good fit for choice/mood with a handful
        // of short options; everything else falls back to stacked bars.
        $display = (in_array($type, ['choice', 'mood'], true)
            && count($options) >= 2 && count($options) <= 4) ? 'pill' : 'list';

        $leader = null;
        $max = -1;
        foreach ($options as $o) {
            if ($o['count'] > $max) {
                $max = $o['count'];
                $leader = $o['key'];
            }
        }

        return [
            'type'    => $type,
            'display' => $display,
            'total'   => $total,
            'options' => $options,
            'leader'  => $leader,
            'average' => $type === 'rating' ? $this->average($counts) : null,
        ];
    }

    /**
     * Whether the current visitor may see results right now (+ optional target).
     */
    public function canSee(Poll $poll, int $targetId = 0): bool
    {
        $visibility = ((string) $poll->resultsVisibility)
            ?: QuickPoll::getInstance()->getSettings()->defaultResultsVisibility;
        $votes = QuickPoll::getInstance()->votes;

        return match ($visibility) {
            'always'     => true,
            'afterClose' => !$votes->isOpen($poll),
            default      => $votes->hasVoted($poll, $targetId) || !$votes->isOpen($poll), // afterVote
        };
    }

    public function hasVoted(Poll $poll, int $targetId = 0): bool
    {
        return QuickPoll::getInstance()->votes->hasVoted($poll, $targetId);
    }

    public function isOpen(Poll $poll): bool
    {
        return QuickPoll::getInstance()->votes->isOpen($poll);
    }

    /**
     * The current visitor's own ballot, shaped for pre-selecting the re-vote
     * form. `keys` holds the chosen optionKeys (for rating that's the 1–5 value;
     * for choice/mood/grid the option index); `grid` maps optionKey => value
     * (yes/maybe/no) for grid polls.
     *
     * @return array{keys:string[],grid:array<string,string>}
     */
    public function myBallot(Poll $poll, int $targetId = 0): array
    {
        $rows = QuickPoll::getInstance()->votes->currentBallot($poll, $targetId);
        $keys = [];
        $grid = [];
        foreach ($rows as $row) {
            $key = (string) $row['optionKey'];
            $keys[] = $key;
            if (($row['value'] ?? null) !== null && $row['value'] !== '') {
                $grid[$key] = (string) $row['value'];
            }
        }
        return ['keys' => $keys, 'grid' => $grid];
    }

    /**
     * Per-user breakdown for **members-only** polls (voterHash = "u:<id>").
     * Returns [] for public polls — those are anonymised by design and must not
     * be traced back to a person.
     *
     * @return array<int,array{userId:int,user:?\craft\elements\User,votes:string[]}>
     */
    public function voters(Poll $poll, int $targetId = 0): array
    {
        if ((((string) $poll->pollAccess) ?: 'public') !== 'members') {
            return [];
        }

        $rows = (new Query())
            ->select(['voterHash', 'optionKey', 'value'])
            ->from(VoteService::TABLE)
            ->where(['pollId' => $poll->id, 'targetId' => $targetId])
            ->andWhere(['like', 'voterHash', 'u:%', false])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->all();

        $labels = $this->optionKeys($poll);
        $isGrid = ((string) $poll->pollType) === 'grid';
        $users = Craft::$app->getUsers();

        $byUser = [];
        foreach ($rows as $row) {
            $uid = (int) substr((string) $row['voterHash'], 2);
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = ['userId' => $uid, 'user' => $users->getUserById($uid), 'votes' => []];
            }
            $label = (string) ($labels[$row['optionKey']] ?? $row['optionKey']);
            if ($isGrid && ($row['value'] ?? '') !== '') {
                $label .= ': ' . $row['value'];
            }
            $byUser[$uid]['votes'][] = $label;
        }

        return array_values($byUser);
    }

    /* --------------------------------------------------------------------- */

    /** @return array<string,int> optionKey => count */
    public function counts(int $pollId, ?int $siteId = null, int $targetId = 0): array
    {
        $q = (new Query())
            ->select(['optionKey', 'c' => 'COUNT(*)'])
            ->from(VoteService::TABLE)
            ->where(['pollId' => $pollId, 'targetId' => $targetId])
            ->groupBy(['optionKey']);

        if ($siteId !== null) {
            $q->andWhere(['siteId' => $siteId]);
        }

        $out = [];
        foreach ($q->all() as $row) {
            $out[(string) $row['optionKey']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Grid aggregation: per option, a yes/maybe/no breakdown.
     */
    private function buildGrid(Poll $poll, ?int $siteId, int $targetId): array
    {
        $q = (new Query())
            ->select(['optionKey', 'value', 'c' => 'COUNT(*)'])
            ->from(VoteService::TABLE)
            ->where(['pollId' => $poll->id, 'targetId' => $targetId])
            ->groupBy(['optionKey', 'value']);
        if ($siteId !== null) {
            $q->andWhere(['siteId' => $siteId]);
        }

        // optionKey => [yes=>n, maybe=>n, no=>n]
        $grid = [];
        foreach ($q->all() as $row) {
            $key = (string) $row['optionKey'];
            $val = (string) $row['value'];
            if (!isset($grid[$key])) {
                $grid[$key] = ['yes' => 0, 'maybe' => 0, 'no' => 0];
            }
            if (isset($grid[$key][$val])) {
                $grid[$key][$val] = (int) $row['c'];
            }
        }

        $voters = (int) (new Query())
            ->from(VoteService::TABLE)
            ->where(['pollId' => $poll->id, 'targetId' => $targetId])
            ->count('DISTINCT voterHash');

        $options = [];
        $best = null;
        $bestScore = -1.0;
        foreach ($this->optionKeys($poll) as $key => $label) {
            $cell = $grid[(string) $key] ?? ['yes' => 0, 'maybe' => 0, 'no' => 0];
            $resp = $cell['yes'] + $cell['maybe'] + $cell['no'];
            $score = $cell['yes'] + $cell['maybe'] * 0.5;   // weighted preference
            if ($resp > 0 && $score > $bestScore) {
                $bestScore = $score;
                $best = (string) $key;
            }
            $options[] = [
                'key'       => (string) $key,
                'label'     => $label,
                'yes'       => $cell['yes'],
                'maybe'     => $cell['maybe'],
                'no'        => $cell['no'],
                'responses' => $resp,
                'yesPct'    => $resp > 0 ? round($cell['yes'] * 100 / $resp, 1) : 0.0,
                'maybePct'  => $resp > 0 ? round($cell['maybe'] * 100 / $resp, 1) : 0.0,
                'noPct'     => $resp > 0 ? round($cell['no'] * 100 / $resp, 1) : 0.0,
                'score'     => $score,
            ];
        }

        return [
            'type'    => 'grid',
            'display' => 'list',
            'total'   => $voters,
            'options' => $options,
            'best'    => $best,
        ];
    }

    /**
     * The ordered option keys → labels for a poll.
     * rating → 1..5; choice/mood/grid → indexed labels from pollOptions.
     *
     * @return array<int|string,string>
     */
    private function optionKeys(Poll $poll): array
    {
        if (((string) $poll->pollType) === 'rating') {
            return ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'];
        }

        $out = [];
        foreach ($poll->getOptions() as $i => $row) {
            $out[$i] = $this->labelOf($row, $i);
        }
        return $out;
    }

    private function labelOf(mixed $row, int $i): string
    {
        if (is_string($row)) {
            return $row;
        }
        if (is_array($row)) {
            if (isset($row['label']) && $row['label'] !== '') {
                return (string) $row['label'];
            }
            $first = reset($row);
            if (is_scalar($first) && $first !== '') {
                return (string) $first;
            }
        }
        return 'Option ' . ($i + 1);
    }

    private function average(array $counts): ?float
    {
        $sum = 0;
        $n = 0;
        foreach ($counts as $key => $c) {
            if (ctype_digit((string) $key)) {
                $sum += (int) $key * $c;
                $n += $c;
            }
        }
        return $n > 0 ? round($sum / $n, 2) : null;
    }
}
