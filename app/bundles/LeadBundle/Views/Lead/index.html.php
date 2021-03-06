<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

$view->extend('MauticCoreBundle:Default:content.html.php');
$view['slots']->set('mauticContent', 'lead');
$view['slots']->set("headerTitle", $view['translator']->trans('mautic.lead.leads'));

$buttons = $preButtons = array();
if ($permissions['lead:leads:create']) {
    $preButtons[] = array(
        'attr'      => array(
            'class'       => 'btn btn-default btn-nospin quickadd',
            'data-toggle' => 'ajaxmodal',
            'data-target' => '#MauticSharedModal',
            'href'        => $view['router']->generate('mautic_contact_action', array('objectAction' => 'quickAdd')),
            'data-header' => $view['translator']->trans('mautic.lead.lead.menu.quickadd'),
        ),
        'iconClass' => 'fa fa-bolt',
        'btnText'   => 'mautic.lead.lead.menu.quickadd'
    );

    $buttons[] = array(
        'attr'      => array(
            'href'  => $view['router']->generate('mautic_contact_action', array('objectAction' => 'import')),
        ),
        'iconClass' => 'fa fa-upload',
        'btnText'   => 'mautic.lead.lead.import'
    );
}

// Only show toggle buttons for accessibility
$extraHtml = <<<button
<div class="btn-group ml-5 sr-only ">
    <span data-toggle="tooltip" title="{$view['translator']->trans('mautic.lead.tooltip.list')}" data-placement="left"><a id="table-view" href="{$view['router']->generate('mautic_contact_index', array('page' => $page, 'view' => 'list'))}" data-toggle="ajax" class="btn btn-default"><i class="fa fa-fw fa-table"></i></span></a>
    <span data-toggle="tooltip" title="{$view['translator']->trans('mautic.lead.tooltip.grid')}" data-placement="left"><a id="card-view" href="{$view['router']->generate('mautic_contact_index', array('page' => $page, 'view' => 'grid'))}" data-toggle="ajax" class="btn btn-default"><i class="fa fa-fw fa-th-large"></i></span></a>
</div>
button;

$view['slots']->set('actions', $view->render('MauticCoreBundle:Helper:page_actions.html.php', array(
    'templateButtons' => array(
        'new' => $permissions['lead:leads:create']
    ),
    'routeBase' => 'contact',
    'langVar'   => 'lead.lead',
    'preCustomButtons' => $preButtons,
    'customButtons'    => $buttons,
    'extraHtml'        => $extraHtml
)));

$customButtons = array(
    array(
        'attr'      => array(
            'class'   => 'hidden-xs btn btn-default btn-sm btn-nospin',
            'href'    => 'javascript: void(0)',
            'onclick' => 'Mautic.toggleLiveLeadListUpdate();',
            'id'      => 'liveModeButton',
            'data-toggle' => false,
            'data-max-id' => $maxLeadId
        ),
        'tooltip' => $view['translator']->trans('mautic.lead.lead.live_update'),
        'iconClass' => 'fa fa-bolt'
    )
);

if ($indexMode == 'list') {
    $customButtons[] = array(
        'attr'      => array(
            'class'          => 'hidden-xs btn btn-default btn-sm btn-nospin'.(($anonymousShowing) ? ' btn-primary' : ''),
            'href'           => 'javascript: void(0)',
            'onclick'        => 'Mautic.toggleAnonymousLeads();',
            'id'             => 'anonymousLeadButton',
            'data-anonymous' => $view['translator']->trans('mautic.lead.lead.searchcommand.isanonymous')
        ),
        'tooltip'   => $view['translator']->trans('mautic.lead.lead.anonymous_leads'),
        'iconClass' => 'fa fa-user-secret'
    );
}

if ($permissions['lead:leads:editown'] || $permissions['lead:leads:editother']) {
    $customButtons = array_merge(
        $customButtons,
        array(
            array(
                'attr'      => array(
                    'class'       => 'btn btn-default btn-sm btn-nospin',
                    'data-toggle' => 'ajaxmodal',
                    'data-target' => '#MauticSharedModal',
                    'href'        => $view['router']->generate('mautic_contact_action', array('objectAction' => 'batchLists')),
                    'data-header' => $view['translator']->trans('mautic.lead.batch.lists')
                ),
                'tooltip' => $view['translator']->trans('mautic.lead.batch.lists'),
                'iconClass' => 'fa fa-pie-chart'
            ),
            array(
                'attr'      => array(
                    'class'       => 'btn btn-default btn-sm btn-nospin',
                    'data-toggle' => 'ajaxmodal',
                    'data-target' => '#MauticSharedModal',
                    'href'        => $view['router']->generate('mautic_contact_action', array('objectAction' => 'batchCampaigns')),
                    'data-header' => $view['translator']->trans('mautic.lead.batch.campaigns'),
                ),
                'tooltip' => $view['translator']->trans('mautic.lead.batch.campaigns'),
                'iconClass' => 'fa fa-clock-o'
            ),
            array(
                'attr'      => array(
                    'class'       => 'hidden-xs btn btn-default btn-sm btn-nospin',
                    'data-toggle' => 'ajaxmodal',
                    'data-target' => '#MauticSharedModal',
                    'href'        => $view['router']->generate('mautic_contact_action', array('objectAction' => 'batchDnc')),
                    'data-header' => $view['translator']->trans('mautic.lead.batch.dnc'),
                ),
                'tooltip' => $view['translator']->trans('mautic.lead.batch.dnc'),
                'iconClass' => 'fa fa-ban text-danger'
            )
        )
    );
}
?>

<div class="panel panel-default bdr-t-wdh-0 mb-0">
    <?php echo $view->render('MauticCoreBundle:Helper:list_toolbar.html.php', array(
        'searchValue' => $searchValue,
        'searchHelp'  => 'mautic.lead.lead.help.searchcommands',
        'action'      => $currentRoute,
        'langVar'     => 'lead.lead',
        'routeBase'   => 'contact',
        'preCustomButtons' => $customButtons,
        'templateButtons' => array(
            'delete' => $permissions['lead:leads:deleteown'] || $permissions['lead:leads:deleteother']
        )
    )); ?>
    <div class="page-list">
        <?php $view['slots']->output('_content'); ?>
    </div>
</div>
