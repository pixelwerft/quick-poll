<?php

namespace pixelwerft\quickpoll\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Whether the widget auto-loads the plugin's base stylesheet (poll.css).
     * Turn this off if you ship your own CSS or load the base file yourself
     * (its URL is available via craft.quickPoll.baseCssUrl).
     */
    public bool $loadBaseCss = true;

    /**
     * Server-side secret mixed into the anonymous voter hash so that the stored
     * hash cannot be reversed to an IP. Override per environment via
     * config/quick-poll.php (recommended) rather than committing a real value.
     */
    public string $voterPepper = '';

    /**
     * Default result visibility when a poll does not set its own.
     * One of: afterVote | always | afterClose
     */
    public string $defaultResultsVisibility = 'afterVote';

    /**
     * Seconds the public results endpoint may be cached. Keep short so live
     * results feel live; 0 disables caching.
     */
    public int $resultsCacheDuration = 15;

    /**
     * UID of the category group whose categories can be assigned to polls.
     * Empty = categories disabled.
     */
    public ?string $categoryGroupUid = null;

    public function rules(): array
    {
        return [
            [['voterPepper', 'categoryGroupUid'], 'string'],
            [['defaultResultsVisibility'], 'in', 'range' => ['afterVote', 'always', 'afterClose']],
            [['resultsCacheDuration'], 'integer', 'min' => 0],
        ];
    }
}
