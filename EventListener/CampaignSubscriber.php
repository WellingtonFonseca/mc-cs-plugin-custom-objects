<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CoreBundle\Helper\ArrayHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Segment\Query\QueryBuilder as SegmentQueryBuilder;
use MauticPlugin\CustomObjectsBundle\CustomItemEvents;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidSegmentFilterException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Form\Type\CampaignActionLinkType;
use MauticPlugin\CustomObjectsBundle\Form\Type\CampaignConditionFieldValueType;
use MauticPlugin\CustomObjectsBundle\Helper\MergedItemCriteriaMatcher;
use MauticPlugin\CustomObjectsBundle\Helper\QueryBuilderManipulatorTrait;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\ConfigProvider;
use MauticPlugin\CustomObjectsBundle\Repository\DbalQueryTrait;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\QueryFilterFactory;
use MauticPlugin\CustomObjectsBundle\Segment\Query\UnionQueryContainer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    use DbalQueryTrait;
    use QueryBuilderManipulatorTrait;

    public function __construct(
        private CustomFieldModel $customFieldModel,
        private CustomObjectModel $customObjectModel,
        private CustomItemModel $customItemModel,
        private TranslatorInterface $translator,
        private ConfigProvider $configProvider,
        private QueryFilterHelper $queryFilterHelper,
        private QueryFilterFactory $queryFilterFactory,
        private Connection $connection,
        private MergedItemCriteriaMatcher $criteriaMatcher
    ) {
    }

    /**
     * @return mixed[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD               => ['onCampaignBuild'],
            CustomItemEvents::ON_CAMPAIGN_TRIGGER_ACTION    => ['onCampaignTriggerAction'],
            CustomItemEvents::ON_CAMPAIGN_TRIGGER_CONDITION => ['onCampaignTriggerCondition'],
        ];
    }

    /**
     * Add event triggers and actions.
     */
    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        if (!$this->configProvider->pluginIsEnabled()) {
            return;
        }

        $customObjects = $this->customObjectModel->fetchAllPublishedEntities();

        foreach ($customObjects as $customObject) {
            if (0 === $customObject->getCustomFields()->count()) {
                // Filter COs without defined custom fields
                continue;
            }

            $linkLabel  = $this->translator->trans('custom.item.events.link.contact', ['%customObject%' => $customObject->getNameSingular()]);
            $fieldLabel = $this->translator->trans('custom.item.events.field.value', ['%customObject%' => $customObject->getNameSingular()]);
            $this->registerNodeLabels((int) $customObject->getId(), (string) $linkLabel, (string) $fieldLabel);

            $event->addAction("custom_item.{$customObject->getId()}.linkcontact", [
                'label'           => $linkLabel,
                'description'     => $this->translator->trans('custom.item.events.link.contact_descr', ['%customObject%' => $customObject->getNameSingular()]),
                'eventName'       => CustomItemEvents::ON_CAMPAIGN_TRIGGER_ACTION,
                'formType'        => CampaignActionLinkType::class,
                'formTypeOptions' => ['customObjectId' => $customObject->getId()],
            ]);

            $event->addCondition("custom_item.{$customObject->getId()}.fieldvalue", [
                'label'           => $fieldLabel,
                'description'     => $this->translator->trans('custom.item.events.field.value_descr', ['%customObject%' => $customObject->getNameSingular()]),
                'eventName'       => CustomItemEvents::ON_CAMPAIGN_TRIGGER_CONDITION,
                'formType'        => CampaignConditionFieldValueType::class,
                'formTheme'       => '@CustomObjects/FormTheme/FieldValueCondition/campaign_condition_field_value_widget.html.twig',
                'formTypeOptions' => ['customObjectId' => $customObject->getId()],
            ]);
        }
    }

    public function onCampaignTriggerAction(CampaignExecutionEvent $event): void
    {
        if (!$this->configProvider->pluginIsEnabled()) {
            return;
        }

        if (!preg_match('/custom_item.(\d*).linkcontact/', $event->getEvent()['type'])) {
            return;
        }

        $eventConfig        = $event->getConfig();
        $linkCustomItemId   = (int) ArrayHelper::getValue('linkCustomItemId', $eventConfig);
        $unlinkCustomItemId = (int) ArrayHelper::getValue('unlinkCustomItemId', $eventConfig);
        $contactId          = (int) $event->getLead()->getId();

        if ($linkCustomItemId) {
            try {
                $customItem = $this->customItemModel->fetchEntity($linkCustomItemId);
                $this->customItemModel->linkEntity($customItem, 'contact', $contactId);
            } catch (NotFoundException) {
                // Do nothing if the custom item doesn't exist anymore.
            }
        }

        if ($unlinkCustomItemId) {
            try {
                $customItem = $this->customItemModel->fetchEntity($unlinkCustomItemId);
                $this->customItemModel->unlinkEntity($customItem, 'contact', $contactId);
            } catch (NotFoundException) {
                // Do nothing if the custom item doesn't exist anymore.
            }
        }
    }

    /**
     * @throws NotFoundException
     * @throws InvalidSegmentFilterException|Exception
     */
    public function onCampaignTriggerCondition(CampaignExecutionEvent $event): void
    {
        if (!$this->configProvider->pluginIsEnabled()) {
            return;
        }

        if (!preg_match('/custom_item.(\d*).fieldvalue/', $event->getEvent()['type'])) {
            return;
        }

        $contact = $event->getLead();

        if (empty($contact) || !$contact->getId()) {
            $event->setResult(false);

            return;
        }

        $config = $event->getConfig();

        // Several criteria: ONE item must satisfy all of them (the same query as the merged segment filter).
        $additional = (array) ($config['additionalCriteria'] ?? []);
        if ([] !== $additional || MergedItemCriteriaMatcher::ITEM_NAME === ($config['field'] ?? null) || $this->isIncludingExcludingOnText($config)) {
            $criteria = array_merge(
                [['field' => $config['field'], 'operator' => $config['operator'], 'value' => $config['value']]],
                array_values($additional)
            );
            $itemId = $this->criteriaMatcher->findItemId($criteria, (int) $contact->getId(), $this->customObjectIdOf($event));

            if ($itemId) {
                $event->setChannel('customItem', $itemId);
                $event->setResult(true);
            } else {
                $event->setResult(false);
            }

            return;
        }

        try {
            $customField = $this->customFieldModel->fetchEntity((int) $config['field']);
        } catch (NotFoundException) {
            $event->setResult(false);

            return;
        }

        $queryAlias        = 'q1';
        $value             = $config['value'];
        $operator          = $config['operator'];
        $innerQueryBuilder = $this->queryFilterFactory->configureQueryBuilderFromSegmentFilter(
            $this->modelSegmentFilterArray($customField, $operator, $value),
            $queryAlias
        );

        if ($innerQueryBuilder instanceof UnionQueryContainer) {
            $this->applyParamsToMultipleQueries($innerQueryBuilder, $queryAlias, $contact);
        } else {
            $this->applyParamsToQuery($innerQueryBuilder, $queryAlias, $contact);
        }

        $queryBuilder = $this->buildOuterQuery($innerQueryBuilder, $queryAlias);

        $customItemId = $this->executeSelect($queryBuilder)->fetchOne();

        if ($customItemId) {
            $event->setChannel('customItem', $customItemId);
            $event->setResult(true);
        } else {
            $event->setResult(false);
        }
    }

    /**
     * The node of an event in the campaign builder says what the event is with the translation of `mautic.campaign.<type>` (core's
     * Campaign/_events.html.twig and Event/_generic.html.twig); with no such translation it prints the key. The type of these events holds
     * the id of the object (custom_item.<id>.fieldvalue), so no translation file can hold them: they are added to the catalogue of the
     * language in use, for this request, when the events are built (the builder, the campaign page and the ajax answer of a saved node
     * all ask for them first). A translator that does not keep a catalogue (a test double) is left alone.
     */
    private function registerNodeLabels(int $objectId, string $linkLabel, string $fieldLabel): void
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return;
        }

        $locale = method_exists($this->translator, 'getLocale') ? $this->translator->getLocale() : null;
        $this->translator->getCatalogue($locale)->add([
            "mautic.campaign.custom_item.{$objectId}.linkcontact" => $linkLabel,
            "mautic.campaign.custom_item.{$objectId}.fieldvalue"  => $fieldLabel,
        ]);
    }

    /**
     * "Including" / "excluding" on a text field is answered by the merged query even for ONE criterion: the single query
     * builds the positive condition and only the segment wraps it in NOT EXISTS, so "excluding" matched whoever had an item
     * with any other value.
     *
     * @param mixed[] $config
     */
    private function isIncludingExcludingOnText(array $config): bool
    {
        if (!in_array($config['operator'] ?? null, ['in', '!in'], true) || !is_numeric($config['field'] ?? null)) {
            return false;
        }

        try {
            return !$this->customFieldModel->fetchEntity((int) $config['field'])->canHaveMultipleValues();
        } catch (NotFoundException) {
            return false;
        }
    }

    private function applyParamsToMultipleQueries(UnionQueryContainer $unionQueryContainer, string $queryAlias, Lead $contact): void
    {
        foreach ($unionQueryContainer as $segmentQueryBuilder) {
            $this->applyParamsToQuery($segmentQueryBuilder, $queryAlias, $contact);
        }
    }

    private function applyParamsToQuery(SegmentQueryBuilder $innerQueryBuilder, string $queryAlias, Lead $contact): void
    {
        $innerQueryBuilder->select($queryAlias.'_value.custom_item_id');
        $this->queryFilterHelper->addContactIdRestriction($innerQueryBuilder, $queryAlias, (int) $contact->getId());
    }

    /**
     * @param UnionQueryContainer|SegmentQueryBuilder $innerQuery
     */
    private function buildOuterQuery($innerQuery, string $queryAlias): QueryBuilder
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select(CustomItem::TABLE_ALIAS.'.id');
        $queryBuilder->from(MAUTIC_TABLE_PREFIX.CustomItem::TABLE_NAME, CustomItem::TABLE_ALIAS);
        $queryBuilder->innerJoin(
            CustomItem::TABLE_ALIAS,
            "({$innerQuery->getSQL()})",
            $queryAlias,
            CustomItem::TABLE_ALIAS.".id = {$queryAlias}.custom_item_id"
        );

        $queryBuilder->setParameters($innerQuery->getParameters(), $innerQuery->getParameterTypes());

        return $queryBuilder;
    }

    /**
     * @param mixed $value
     *
     * @return mixed[]
     */
    private function modelSegmentFilterArray(CustomField $customField, string $operator, $value): array
    {
        if ($customField->canHaveMultipleValues() && is_string($value)) {
            $value = [$value];
        }

        return [
            'glue'     => 'and',
            'field'    => 'cmf_'.$customField->getId(),
            'object'   => 'custom_object',
            'type'     => $customField->getType(),
            'filter'   => $value,
            'operator' => $operator,
            'display'  => null,
        ];
    }

    /**
     * The Custom Object of the event: its type is custom_item.<object id>.fieldvalue.
     */
    private function customObjectIdOf(CampaignExecutionEvent $event): int
    {
        return preg_match('/custom_item\.(\d+)\./', $event->getEvent()['type'], $matches) ? (int) $matches[1] : 0;
    }
}
