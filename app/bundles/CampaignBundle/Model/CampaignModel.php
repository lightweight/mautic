<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CampaignBundle\Model;

use Doctrine\ORM\PersistentCollection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Model\FormModel as CommonFormModel;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Model\FormModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Event as Events;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\CoreBundle\Helper\Chart\LineChart;
use Mautic\CoreBundle\Helper\Chart\PieChart;
use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Monolog\Logger;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Class CampaignModel
 * {@inheritdoc}
 * @package Mautic\CoreBundle\Model\FormModel
 */
class CampaignModel extends CommonFormModel
{
    /**
     * @var mixed
     */
    protected $batchSleepTime;

    /**
     * @var mixed
     */
    protected $batchCampaignSleepTime;

    /**
     * @var LeadModel
     */
    protected $leadModel;
    
    /**
     * @var ListModel
     */
    protected $leadListModel;

    /**
     * @var FormModel
     */
    protected $formModel;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * CampaignModel constructor.
     * 
     * @param CoreParametersHelper $coreParametersHelper
     * @param LeadModel $leadModel
     * @param ListModel $leadListModel
     * @param FormModel $formModel
     */
    public function __construct(CoreParametersHelper $coreParametersHelper, LeadModel $leadModel, ListModel $leadListModel, FormModel $formModel)
    {
        $this->leadModel = $leadModel;
        $this->leadListModel = $leadListModel;
        $this->formModel = $formModel;
        $this->batchSleepTime = $coreParametersHelper->getParameter('mautic.batch_sleep_time');
        $this->batchCampaignSleepTime = $coreParametersHelper->getParameter('mautic.batch_campaign_sleep_time');
    }

    /**
     * @param Logger $logger
     */
    public function setLogger(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     *
     * @return \Mautic\CampaignBundle\Entity\CampaignRepository
     */
    public function getRepository ()
    {
        return $this->em->getRepository('MauticCampaignBundle:Campaign');
    }

    /**
     * @return \Mautic\CampaignBundle\Entity\EventRepository
     */
    public function getEventRepository ()
    {
        return $this->em->getRepository('MauticCampaignBundle:Event');
    }

    /**
     * @return \Mautic\CampaignBundle\Entity\LeadRepository
     */
    public function getCampaignLeadRepository ()
    {
        return $this->em->getRepository('MauticCampaignBundle:Lead');
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getPermissionBase ()
    {
        return 'campaign:campaigns';
    }

    /**
     * {@inheritdoc}
     *
     * @param       $entity
     * @param       $formFactory
     * @param null  $action
     * @param array $options
     *
     * @return mixed
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function createForm ($entity, $formFactory, $action = null, $options = array())
    {
        if (!$entity instanceof Campaign) {
            throw new MethodNotAllowedHttpException(array('Campaign'));
        }
        $params = (!empty($action)) ? array('action' => $action) : array();

        return $formFactory->create('campaign', $entity, $params);
    }

    /**
     * Get a specific entity or generate a new one if id is empty
     *
     * @param $id
     *
     * @return null|Campaign
     */
    public function getEntity ($id = null)
    {
        if ($id === null) {
            return new Campaign();
        }

        $entity = parent::getEntity($id);

        return $entity;
    }

    /**
     * @param object $entity
     */
    public function deleteEntity($entity)
    {
        // Null all the event parents for this campaign to avoid database constraints
        $this->getEventRepository()->nullEventParents($entity->getId());

        parent::deleteEntity($entity);
    }

    /**
     * {@inheritdoc}
     *
     * @param $action
     * @param $event
     * @param $entity
     * @param $isNew
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function dispatchEvent ($action, &$entity, $isNew = false, \Symfony\Component\EventDispatcher\Event $event = null)
    {
        if ($entity instanceof \Mautic\CampaignBundle\Entity\Lead) {
            return;
        }

        if (!$entity instanceof Campaign) {
            throw new MethodNotAllowedHttpException(array('Campaign'));
        }

        switch ($action) {
            case "pre_save":
                $name = CampaignEvents::CAMPAIGN_PRE_SAVE;
                break;
            case "post_save":
                $name = CampaignEvents::CAMPAIGN_POST_SAVE;
                break;
            case "pre_delete":
                $name = CampaignEvents::CAMPAIGN_PRE_DELETE;
                break;
            case "post_delete":
                $name = CampaignEvents::CAMPAIGN_POST_DELETE;
                break;
            default:
                return null;
        }

        if ($this->dispatcher->hasListeners($name)) {
            if (empty($event)) {
                $event = new Events\CampaignEvent($entity, $isNew);
            }

            $this->dispatcher->dispatch($name, $event);

            return $event;
        } else {
            return null;
        }
    }

    /**
     * @param Campaign $entity
     * @param          $sessionEvents
     * @param          $sessionConnections
     * @param          $deletedEvents
     *
     * @return array
     */
    public function setEvents (Campaign $entity, $sessionEvents, $sessionConnections, $deletedEvents)
    {
        $existingEvents = $entity->getEvents();
        $events =
        $hierarchy =
        $parentUpdated = array();

        //set the events from session
        foreach ($sessionEvents as $id => $properties) {
            $isNew = (!empty($properties['id']) && isset($existingEvents[$properties['id']])) ? false : true;
            $event = !$isNew ? $existingEvents[$properties['id']] : new Event();

            foreach ($properties as $f => $v) {
                if ($f == 'id' && strpos($v, 'new') === 0) {
                    //set the temp ID used to be able to match up connections
                    $event->setTempId($v);
                }

                if (in_array($f, array('id', 'order', 'parent')))
                    continue;

                $func = "set" . ucfirst($f);
                if (method_exists($event, $func)) {
                    $event->$func($v);
                }
            }

            $event->setCampaign($entity);
            $events[$id] = $event;
        }

        foreach ($deletedEvents as $deleteMe) {
            if (isset($existingEvents[$deleteMe])) {
                // Remove child from parent
                $parent = $existingEvents[$deleteMe]->getParent();
                if ($parent) {
                    $parent->removeChild($existingEvents[$deleteMe]);
                    $existingEvents[$deleteMe]->removeParent();
                }

                $entity->removeEvent($existingEvents[$deleteMe]);

                unset($events[$deleteMe]);
            }
        }

        $relationships = array();
        if (isset($sessionConnections['connections'])) {
            foreach ($sessionConnections['connections'] as $connection) {
                $source = $connection['sourceId'];
                $target = $connection['targetId'];

                if (in_array($source, array('lists', 'forms'))) {
                    // Only concerned with events and not sources
                    continue;
                }
                $sourceDecision = (!empty($connection['anchors'][0])) ? $connection['anchors'][0]['endpoint'] : null;

                if ($sourceDecision == 'leadsource') {
                    // Lead source connection that does not matter
                    continue;
                }

                $relationships[$target] = array(
                    'parent'   => $source,
                    'decision' => $sourceDecision
                );
            }
        }

        // Assign parent/child relationships
        foreach ($events as $id => $e) {
            if (isset($relationships[$id])) {
                // Has a parent
                $anchor = in_array($relationships[$id]['decision'], array('yes', 'no')) ? $relationships[$id]['decision'] : null;
                $events[$id]->setDecisionPath($anchor);

                $parentId = $relationships[$id]['parent'];
                $events[$id]->setParent($events[$parentId]);

                $hierarchy[$id] = $parentId;
            } elseif ($events[$id]->getParent()) {
                // No longer has a parent so null it out

                // Remove decision so that it doesn't affect execution
                $events[$id]->setDecisionPath(null);

                // Remove child from parent
                $parent = $events[$id]->getParent();
                $parent->removeChild($events[$id]);

                // Remove parent from child
                $events[$id]->removeParent();
                $hierarchy[$id] = 'null';
            } else {
                // Is a parent
                $hierarchy[$id] = 'null';

                // Remove decision so that it doesn't affect execution
                $events[$id]->setDecisionPath(null);
            }
        }

        //set event order used when querying the events
        $this->buildOrder($hierarchy, $events, $entity);

        uasort($events, function ($a, $b) {
            $aOrder = $a->getOrder();
            $bOrder = $b->getOrder();
            if ($aOrder == $bOrder) {
                return 0;
            }

            return ($aOrder < $bOrder) ? -1 : 1;
        });

        // Persist events if campaign is being edited
        if ($entity->getId()) {
            $this->getEventRepository()->saveEntities($events);
        }

        return $events;
    }

    /**
     * @param      $entity
     * @param      $settings
     * @param bool $persist
     * @param null $events
     *
     * @return mixed
     */
    public function setCanvasSettings($entity, $settings, $persist = true, $events = null)
    {
        if ($events === null) {
            $events = $entity->getEvents();
        }

        $tempIds = array();

        foreach ($events as $e) {
            if ($e instanceof Event) {
                $tempIds[$e->getTempId()] = $e->getId();
            } else {
                $tempIds[$e['tempId']] = $e['id'];
            }
        }

        if (!isset($settings['nodes'])) {
            $settings['nodes'] = array();
        }

        foreach ($settings['nodes'] as &$node) {
            if (strpos($node['id'], 'new') !== false) {
                // Find the real one and update the node
                $node['id'] = str_replace($node['id'], $tempIds[$node['id']], $node['id']);
            }
        }

        if (!isset($settings['connections'])) {
            $settings['connections'] = array();
        }

        foreach ($settings['connections'] as &$connection) {
            // Check source
            if (strpos($connection['sourceId'], 'new') !== false) {
                // Find the real one and update the node
                $connection['sourceId'] = str_replace($connection['sourceId'], $tempIds[$connection['sourceId']], $connection['sourceId']);
            }

            // Check target
            if (strpos($connection['targetId'], 'new') !== false) {
                // Find the real one and update the node
                $connection['targetId'] = str_replace($connection['targetId'], $tempIds[$connection['targetId']], $connection['targetId']);
            }

            // Rebuild anchors
            if (!isset($connection['anchors']['source'])) {
                $anchors = array();
                foreach ($connection['anchors'] as $k => $anchor) {
                    $type           = ($k === 0) ? 'source' : 'target';
                    $anchors[$type] = $anchor['endpoint'];
                }

                $connection['anchors'] = $anchors;
            }
        }

        $entity->setCanvasSettings($settings);

        if ($persist) {
            $this->getRepository()->saveEntity($entity);
        } else {

            return $settings;
        }
    }

    /**
     * @param          $hierarchy
     * @param          $events
     * @param Campaign $entity
     * @param string   $root
     * @param int      $order
     */
    private function buildOrder ($hierarchy, &$events, $entity, $root = 'null', $order = 1)
    {
        $count = count($hierarchy);

        foreach ($hierarchy as $eventId => $parent) {
            if ($parent == $root || $count === 1) {
                $events[$eventId]->setOrder($order);
                $entity->addEvent($eventId, $events[$eventId]);
                unset($hierarchy[$eventId]);
                if (count($hierarchy)) {
                    $this->buildOrder($hierarchy, $events, $entity, $eventId, $order + 1);
                }
            }
        }
    }

    /**
     * Gets array of custom events from bundles subscribed CampaignEvents::CAMPAIGN_ON_BUILD
     *
     * @return mixed
     */
    public function getEvents ()
    {
        static $events;

        if (empty($events)) {
            //build them
            $events = array();
            $event  = new Events\CampaignBuilderEvent($this->translator);
            $this->dispatcher->dispatch(CampaignEvents::CAMPAIGN_ON_BUILD, $event);
            $events['decision']     = $event->getLeadDecisions();
            $events['condition']    = $event->getLeadConditions();
            $events['action']       = $event->getActions();
        }

        return $events;
    }

    /**
     * Get list of sources for a campaign
     *
     * @param $campaign
     *
     * @return array
     */
    public function getLeadSources($campaign)
    {
        $campaignId = ($campaign instanceof Campaign) ? $campaign->getId() : $campaign;

        $sources = array();

        // Lead lists
        $sources['lists'] = $this->getRepository()->getCampaignListSources($campaignId);

        // Forms
        $sources['forms'] = $this->getRepository()->getCampaignFormSources($campaignId);

        return $sources;
    }

    /**
     * Add and/or delete lead sources from a campaign
     *
     * @param $entity
     * @param $addedSources
     * @param $deletedSources
     */
    public function setLeadSources(Campaign $entity, $addedSources, $deletedSources)
    {
        foreach ($addedSources as $type => $sources) {
            foreach ($sources as $id) {
                switch ($type) {
                    case 'lists':
                        $entity->addList($this->em->getReference('MauticLeadBundle:LeadList', $id));
                        break;
                    case 'forms':
                        $entity->addForm($this->em->getReference('MauticFormBundle:Form', $id));
                        break;
                    default:
                        break;
                }
            }
        }

        foreach ($deletedSources as $type => $sources) {
            foreach ($sources as $id) {
                switch ($type) {
                    case 'lists':
                        $entity->removeList($this->em->getReference('MauticLeadBundle:LeadList', $id));
                        break;
                    case 'forms':
                        $entity->removeForm($this->em->getReference('MauticFormBundle:Form', $id));
                        break;
                    default:
                        break;
                }
            }
        }
    }

    /**
     * Get a list of source choices
     *
     * @param $sourceType
     *
     * @return array
     */
    public function getSourceLists($sourceType = null)
    {
        $choices = array();
        switch ($sourceType) {
            case 'lists':
            case null:
                $choices['lists'] = array();

                $lists = (empty($options['global_only'])) ? $this->leadListModel->getUserLists() : $this->leadListModel->getGlobalLists();

                foreach ($lists as $list) {
                    $choices['lists'][$list['id']] = $list['name'];
                }

            case 'forms':
            case null:
                $choices['forms'] = array();

                $viewOther = $this->security->isGranted('form:forms:viewother');
                $repo      = $this->formModel->getRepository();
                $repo->setCurrentUser($this->user);

                $forms = $repo->getFormList('', 0, 0, $viewOther, 'campaign');
                foreach ($forms as $form) {
                    $choices['forms'][$form['id']] = $form['name'];
                }
        }

        foreach ($choices as &$typeChoices) {
            asort($typeChoices);
        }

        return ($sourceType == null) ? $choices : $choices[$sourceType];
    }

    /**
     * @param mixed $form
     *
     * @return array
     */
    public function getCampaignsByForm($form)
    {
        $formId = ($form instanceof Form) ? $form->getId() : $form;

        return $this->getRepository()->findByFormId($formId);
    }

    /**
     * Gets the campaigns a specific lead is part of
     *
     * @param Lead $lead
     * @param bool $forList
     *
     * @return mixed
     */
    public function getLeadCampaigns (Lead $lead = null, $forList = false)
    {
        static $campaigns = array();

        if ($lead === null) {
            $lead = $this->leadModel->getCurrentLead();
        }

        if (!isset($campaigns[$lead->getId()])) {
            $repo   = $this->getRepository();
            $leadId = $lead->getId();
            //get the campaigns the lead is currently part of
            $campaigns[$leadId] = $repo->getPublishedCampaigns(null, $lead->getId(), $forList);
        }

        return $campaigns[$lead->getId()];
    }

    /**
     * Gets a list of published campaigns
     *
     * @param bool $forList
     *
     * @return array
     */
    public function getPublishedCampaigns ($forList = false)
    {
        static $campaigns = array();

        if (empty($campaigns)) {
            $campaigns = $this->getRepository()->getPublishedCampaigns(null, null, $forList);
        }

        return $campaigns;
    }

    /**
     * Add lead to the campaign
     *
     * @param Campaign  $campaign
     * @param           $lead
     * @param bool|true $manuallyAdded
     */
    public function addLead (Campaign $campaign, $lead, $manuallyAdded = true)
    {
        $this->addLeads($campaign, array($lead), $manuallyAdded);

        unset($campaign, $lead);
    }

    /**
     * Add lead(s) to a campaign
     *
     * @param Campaign $campaign
     * @param array    $leads
     * @param bool     $manuallyAdded
     * @param bool     $batchProcess
     * @param int      $searchListLead
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function addLeads (Campaign $campaign, array $leads, $manuallyAdded = false, $batchProcess = false, $searchListLead = 1)
    {
        foreach ($leads as $lead) {
            if (!$lead instanceof Lead) {
                $leadId = (is_array($lead) && isset($lead['id'])) ? $lead['id'] : $lead;
                $lead   = $this->em->getReference('MauticLeadBundle:Lead', $leadId);
            }

            if ($searchListLead == -1) {
                $campaignLead = null;
            } elseif ($searchListLead) {
                $campaignLead = $this->getCampaignLeadRepository()->findOneBy(array(
                    'lead'     => $lead,
                    'campaign' => $campaign
                ));
            } else {
                $campaignLead = $this->em->getReference('MauticCampaignBundle:Lead', array(
                    'lead'     => $leadId,
                    'campaign' => $campaign->getId()
                ));
            }

            $dispatchEvent = true;
            if ($campaignLead != null) {
                if ($campaignLead->wasManuallyRemoved()) {
                    $campaignLead->setManuallyRemoved(false);
                    $campaignLead->setManuallyAdded($manuallyAdded);

                    try {
                        $this->getRepository()->saveEntity($campaignLead);
                    } catch (\Exception $exception) {
                        $dispatchEvent = false;
                        $this->logger->log('error', $exception->getMessage());
                    }
                } else {
                    $this->em->detach($campaignLead);
                    if ($batchProcess) {
                        $this->em->detach($lead);
                    }

                    unset($campaignLead, $lead);

                    continue;
                }
            } else {
                $campaignLead = new \Mautic\CampaignBundle\Entity\Lead();
                $campaignLead->setCampaign($campaign);
                $campaignLead->setDateAdded(new \DateTime());
                $campaignLead->setLead($lead);
                $campaignLead->setManuallyAdded($manuallyAdded);

                try {
                    $this->getRepository()->saveEntity($campaignLead);
                } catch (\Exception $exception) {
                    $dispatchEvent = false;
                    $this->logger->log('error', $exception->getMessage());
                }
            }

            if ($dispatchEvent && $this->dispatcher->hasListeners(CampaignEvents::CAMPAIGN_ON_LEADCHANGE)) {
                $event = new Events\CampaignLeadChangeEvent($campaign, $lead, 'added');
                $this->dispatcher->dispatch(CampaignEvents::CAMPAIGN_ON_LEADCHANGE, $event);

                unset($event);
            }

            // Detach CampaignLead to save memory
            $this->em->detach($campaignLead);
            if ($batchProcess) {
                $this->em->detach($lead);
            }
            unset($campaignLead, $lead);
        }

        unset($leadModel, $campaign, $leads);
    }

    /**
     * Remove lead from the campaign
     *
     * @param Campaign $campaign
     * @param          $lead
     * @param bool     $manuallyRemoved
     */
    public function removeLead (Campaign $campaign, $lead, $manuallyRemoved = true)
    {
        $this->removeLeads($campaign, array($lead), $manuallyRemoved);

        unset($campaign, $lead);
    }

    /**
     * Remove lead(s) from the campaign
     *
     * @param Campaign   $campaign
     * @param array      $leads
     * @param bool|false $manuallyRemoved
     * @param bool|false $batchProcess
     * @param bool|false $skipFindOne
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function removeLeads (Campaign $campaign, array $leads, $manuallyRemoved = false, $batchProcess = false, $skipFindOne = false)
    {
        foreach ($leads as $lead) {
            $dispatchEvent = false;

            if (!$lead instanceof Lead) {
                $leadId = (is_array($lead) && isset($lead['id'])) ? $lead['id'] : $lead;
                $lead   = $this->em->getReference('MauticLeadBundle:Lead', $leadId);
            }

            $campaignLead = (!$skipFindOne) ?
                $this->getCampaignLeadRepository()->findOneBy(array(
                    'lead'     => $lead,
                    'campaign' => $campaign
                )) :
                $this->em->getReference('MauticCampaignBundle:Lead', array(
                    'lead'     => $leadId,
                    'campaign' => $campaign->getId()
                ));

            if ($campaignLead == null) {
                if ($batchProcess) {
                    $this->em->detach($lead);
                    unset($lead);
                }

                continue;
            }

            if (($manuallyRemoved && $campaignLead->wasManuallyAdded()) || (!$manuallyRemoved && !$campaignLead->wasManuallyAdded())) {
                //lead was manually added and now manually removed or was not manually added and now being removed

                // Manually added and manually removed so chuck it
                $dispatchEvent   = true;

                $this->getEventRepository()->deleteEntity($campaignLead);
            } elseif ($manuallyRemoved) {
                $dispatchEvent = true;

                $campaignLead->setManuallyRemoved(true);
                $this->getEventRepository()->saveEntity($campaignLead);
            }

            if ($dispatchEvent) {
                //remove scheduled events if the lead was removed
                $this->removeScheduledEvents($campaign, $lead);

                if ($this->dispatcher->hasListeners(CampaignEvents::CAMPAIGN_ON_LEADCHANGE)) {
                    $event = new Events\CampaignLeadChangeEvent($campaign, $lead, 'removed');
                    $this->dispatcher->dispatch(CampaignEvents::CAMPAIGN_ON_LEADCHANGE, $event);

                    unset($event);
                }
            }

            // Detach CampaignLead to save memory
            $this->em->detach($campaignLead);

            if ($batchProcess) {
                $this->em->detach($lead);
            }

            unset($campaignLead, $lead);
        }
    }

    /**
     * Get details of leads in a campaign
     *
     * @param      $campaign
     * @param null $leads
     *
     * @return mixed
     */
    public function getLeadDetails ($campaign, $leads = null)
    {
        $campaignId = ($campaign instanceof Campaign) ? $campaign->getId() : $campaign;

        if ($leads instanceof PersistentCollection) {
            $leads = array_keys($leads->toArray());
        }

        return $this->em->getRepository('MauticCampaignBundle:Lead')->getLeadDetails($campaignId, $leads);
    }

    /**
     * @param Campaign        $campaign
     * @param int             $limit
     * @param bool            $maxLeads
     * @param OutputInterface $output
     *
     * @return int
     */
    public function rebuildCampaignLeads (Campaign $campaign, $limit = 1000, $maxLeads = false, OutputInterface $output = null)
    {
        defined('MAUTIC_REBUILDING_CAMPAIGNS') or define('MAUTIC_REBUILDING_CAMPAIGNS', 1);

        $repo = $this->getRepository();

        // Get a list of lead lists this campaign is associated with
        $lists = $repo->getCampaignListIds($campaign->getId());

        $batchLimiters = array(
            'dateTime' => (new DateTimeHelper())->toUtcString()
        );

        if (count($lists)) {
            // Get a count of new leads
            $newLeadsCount = $repo->getCampaignLeadsFromLists(
                $campaign->getId(),
                $lists,
                array(
                    'countOnly'     => true,
                    'batchLimiters' => $batchLimiters
                )
            );

            // Ensure the same list is used each batch
            $batchLimiters['maxId'] = (int) $newLeadsCount['maxId'];

            // Number of total leads to process
            $leadCount = (int) $newLeadsCount['count'];
        } else {
            // No lists to base campaign membership off of so ignore
            $leadCount = 0;
        }

        if ($output) {
            $output->writeln($this->translator->trans('mautic.campaign.rebuild.to_be_added', array('%leads%' => $leadCount, '%batch%' => $limit)));
        }

        // Handle by batches
        $start = $leadsProcessed = 0;

        // Try to save some memory
        gc_enable();

        if ($leadCount) {
            $maxCount = ($maxLeads) ? $maxLeads : $leadCount;

            if ($output) {
                $progress = new ProgressBar($output, $maxCount);
                $progress->start();
            }

            // Add leads
            while ($start < $leadCount) {
                // Keep CPU down for large lists; sleep per $limit batch
                $this->batchSleep();

                // Get a count of new leads
                $newLeadList = $repo->getCampaignLeadsFromLists(
                    $campaign->getId(),
                    $lists,
                    array(
                        'limit'         => $limit,
                        'batchLimiters' => $batchLimiters
                    )
                );

                $start += $limit;

                foreach ($newLeadList as $l) {
                    $this->addLeads($campaign, array($l), false, true, -1);

                    $leadsProcessed++;
                    if ($output && $leadsProcessed < $maxCount) {
                        $progress->setProgress($leadsProcessed);
                    }

                    unset($l);

                    if ($maxLeads && $leadsProcessed >= $maxLeads) {
                        // done for this round, bye bye
                        if ($output) {
                            $progress->finish();
                        }

                        return $leadsProcessed;
                    }
                }

                unset($newLeadList);

                // Free some memory
                gc_collect_cycles();
            }

            if ($output) {
                $progress->finish();
                $output->writeln('');
            }
        }

        // Get a count of leads to be removed
        $removeLeadCount = $repo->getCampaignOrphanLeads(
            $campaign->getId(),
            $lists,
            array(
                'countOnly'     => true,
                'batchLimiters' => $batchLimiters
            )
        );

        // Restart batching
        $start                  = $lastRoundPercentage = 0;
        $leadCount              = $removeLeadCount['count'];
        $batchLimiters['maxId'] = $removeLeadCount['maxId'];

        if ($output) {
            $output->writeln($this->translator->trans('mautic.lead.list.rebuild.to_be_removed', array('%leads%' => $leadCount, '%batch%' => $limit)));
        }

        if ($leadCount) {
            $maxCount = ($maxLeads) ? $maxLeads : $leadCount;

            if ($output) {
                $progress = new ProgressBar($output, $maxCount);
                $progress->start();
            }

            // Remove leads
            while ($start < $leadCount) {
                // Keep CPU down for large lists; sleep per $limit batch
                $this->batchSleep();

                $removeLeadList = $repo->getCampaignOrphanLeads(
                    $campaign->getId(),
                    $lists,
                    array(
                        'limit'         => $limit,
                        'batchLimiters' => $batchLimiters
                    )
                );

                foreach ($removeLeadList as $l) {
                    $this->removeLeads($campaign, array($l), false, true, true);

                    $leadsProcessed++;
                    if ($output && $leadsProcessed < $maxCount) {
                        $progress->setProgress($leadsProcessed);
                    }

                    if ($maxLeads && $leadsProcessed >= $maxLeads) {
                        // done for this round, bye bye
                        $progress->finish();

                        return $leadsProcessed;
                    }
                }

                $start += $limit;

                unset($removeLeadList);

                // Free some memory
                gc_collect_cycles();
            }

            if($output) {
                $progress->finish();
                $output->writeln('');
            }
        }

        return $leadsProcessed;
    }

    /**
     * Get leads for a campaign.  If $event is passed in, only leads who have not triggered the event are returned
     *
     * @param Campaign $campaign
     * @param array    $event
     *
     * @return mixed
     */
    public function getCampaignLeads($campaign, $event = null)
    {
        $campaignId = ($campaign instanceof Campaign) ? $campaign->getId() : $campaign;
        $eventId    = (is_array($event) && isset($event['id'])) ? $event['id'] : $event;
        $leads      = $this->em->getRepository('MauticCampaignBundle:Lead')->getLeads($campaignId, $eventId);

        return $leads;
    }

    /**
     * @param Campaign $campaign
     * @param          $lead
     */
    public function removeScheduledEvents($campaign, $lead)
    {
        $this->em->getRepository('MauticCampaignBundle:LeadEventLog')->removeScheduledEvents($campaign->getId(), $lead->getId());
    }

    /**
     * @param $id
     *
     * @return array
     */
    public function getCampaignListIds($id)
    {
        return $this->getRepository()->getCampaignListIds((int) $id);
    }

    /**
     * Batch sleep according to settings
     */
    protected function batchSleep()
    {
        $eventSleepTime = $this->batchCampaignSleepTime ? $this->batchCampaignSleepTime : ($this->batchSleepTime ? $this->batchSleepTime : 1);

        if (empty($eventSleepTime)) {
            return;
        }

        if ($eventSleepTime < 1) {
            usleep($eventSleepTime * 1000000);
        } else {
            sleep($eventSleepTime);
        }
    }

    /**
     * Get line chart data of leads added to campaigns
     *
     * @param char      $unit   {@link php.net/manual/en/function.date.php#refsect1-function.date-parameters}
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @param string    $dateFormat
     * @param array     $filter
     * @param boolean   $canViewOthers
     *
     * @return array
     */
    public function getLeadsAddedLineChartData($unit, \DateTime $dateFrom, \DateTime $dateTo, $dateFormat = null, $filter = array(), $canViewOthers = true)
    {
        $chart = new LineChart($unit, $dateFrom, $dateTo, $dateFormat);
        $query = $chart->getChartQuery($this->em->getConnection());
        $q     = $query->prepareTimeDataQuery('campaign_leads', 'date_added', $filter);

        if (!$canViewOthers) {
            $q->join('t', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = c.campaign_id')
                ->andWhere('c.created_by = :userId')
                ->setParameter('userId', $this->user->getId());
        }

        $data = $query->loadAndBuildTimeData($q);
        $chart->setDataset($this->translator->trans('mautic.campaign.campaign.leads'), $data);

        return $chart->render();
    }
}
