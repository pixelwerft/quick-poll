<?php

namespace pixelwerft\quickpoll\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * Element query for Poll. Joins the plugin's own tables: non-localized settings
 * from quickpoll_polls, and the options for the requested site from
 * quickpoll_polls_sites.
 */
class PollQuery extends ElementQuery
{
    public mixed $pollType = null;
    public mixed $pollAccess = null;
    public mixed $category = null;

    public function pollType(mixed $value): static
    {
        $this->pollType = $value;
        return $this;
    }

    public function pollAccess(mixed $value): static
    {
        $this->pollAccess = $value;
        return $this;
    }

    /** Filter to polls related to the given category id(s). */
    public function category(mixed $value): static
    {
        $this->category = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('quickpoll_polls');

        $this->query->select([
            'quickpoll_polls.pollType',
            'quickpoll_polls.pollAccess',
            'quickpoll_polls.multiSelect',
            'quickpoll_polls.openUntil',
            'quickpoll_polls.resultsVisibility',
            'quickpoll_polls.showShare',
            'quickpoll_polls.hideAfterClose',
            'quickpoll_polls.allowRevote',
            'quickpoll_polls_sites.options',
            'quickpoll_polls_sites.resultText',
        ]);

        // Options for the site this query is resolving (elements_sites.siteId).
        $this->query->leftJoin(
            ['quickpoll_polls_sites' => '{{%quickpoll_polls_sites}}'],
            '[[quickpoll_polls_sites.pollId]] = [[quickpoll_polls.id]] AND [[quickpoll_polls_sites.siteId]] = [[elements_sites.siteId]]'
        );

        if ($this->pollType !== null) {
            $this->subQuery->andWhere(Db::parseParam('quickpoll_polls.pollType', $this->pollType));
        }
        if ($this->pollAccess !== null) {
            $this->subQuery->andWhere(Db::parseParam('quickpoll_polls.pollAccess', $this->pollAccess));
        }
        if ($this->category !== null) {
            $this->subQuery->innerJoin(
                ['quickpoll_poll_categories' => '{{%quickpoll_poll_categories}}'],
                '[[quickpoll_poll_categories.pollId]] = [[elements.id]]'
            );
            $this->subQuery->andWhere(Db::parseParam('quickpoll_poll_categories.categoryId', $this->category));
        }

        return parent::beforePrepare();
    }
}
