<?php

namespace pixelwerft\quickpoll;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use pixelwerft\quickpoll\elements\Poll;
use pixelwerft\quickpoll\fields\PollField;
use pixelwerft\quickpoll\models\Settings;
use pixelwerft\quickpoll\services\ResultsService;
use pixelwerft\quickpoll\services\VoteService;
use yii\base\Event;

/**
 * Quick Poll plugin — lightweight, reusable polls & votings.
 *
 * Self-contained: polls are a plugin-managed, localizable element with their own
 * CP section, the votes live in plugin-owned tables, and the front-end widget
 * ships from the plugin's own templates/assets. Nothing is written to the host's
 * project config and no scaffold step is required — installing is enough.
 *
 * @method static QuickPoll getInstance()
 * @method Settings getSettings()
 * @property-read VoteService $votes
 * @property-read ResultsService $results
 */
class QuickPoll extends Plugin
{
    public string $schemaVersion = '1.3.0';
    public bool $hasCpSettings = true;

    // CP section "Polls" — overview dashboard (all polls + vote totals + CSV
    // export). The per-poll result tab still lives on the entry itself.
    public bool $hasCpSection = true;

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item === null) {
            return null;
        }
        $item['label'] = Craft::t('quick-poll', 'Polls');
        return $item;
    }

    public static function config(): array
    {
        return [
            'components' => [
                'votes'   => VoteService::class,
                'results' => ResultsService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Make the plugin's front-end templates resolvable as `quick-poll/*`
        // (e.g. {% include 'quick-poll/widget' %}). CP templates already resolve
        // under the same prefix via Craft's automatic CP template root.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['quick-poll'] = __DIR__ . '/templates';
            }
        );

        // Register the plugin-managed Poll element type.
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = Poll::class;
            }
        );

        // Register the "Quick Poll" field type.
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = PollField::class;
            }
        );

        // CP edit routes for poll elements.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['quick-poll/polls/new'] = 'quick-poll/polls/edit';
                $event->rules['quick-poll/polls/<pollId:\d+>'] = 'quick-poll/polls/edit';
            }
        );

        // Expose read-only results to templates:
        //   {% set r = craft.quickPoll.results(pollEntry) %}
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('quickPoll', $this->get('results'));
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('quick-poll/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }
}
