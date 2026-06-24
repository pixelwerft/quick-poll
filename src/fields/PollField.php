<?php

namespace pixelwerft\quickpoll\fields;

use Craft;
use craft\fields\BaseRelationField;
use pixelwerft\quickpoll\elements\Poll;

/**
 * "Quick Poll" field — relates one (or more) Poll elements. Drop it into any
 * field layout (entry, Matrix block, …) to let editors pick a poll, then render
 * it: {% include 'quick-poll/widget' with { poll: entry.myField.one() } %}
 */
class PollField extends BaseRelationField
{
    public static function displayName(): string
    {
        return Craft::t('quick-poll', 'Quick Poll');
    }

    public static function icon(): string
    {
        return 'square-poll-vertical';
    }

    public static function elementType(): string
    {
        return Poll::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('quick-poll', 'Add a poll');
    }
}
