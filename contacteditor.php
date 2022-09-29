<?php

require_once 'contacteditor.civix.php';

use CRM_Contacteditor_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function contacteditor_civicrm_config(&$config) {
  Civi::dispatcher()
    ->addListener('civi.api.prepare', [
      'CRM_Contacteditor_ChangeContactType',
      'validate',
    ], -100);
  _contacteditor_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function contacteditor_civicrm_install() {
  _contacteditor_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function contacteditor_civicrm_postInstall() {
  _contacteditor_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function contacteditor_civicrm_uninstall() {
  _contacteditor_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function contacteditor_civicrm_enable() {
  _contacteditor_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function contacteditor_civicrm_disable() {
  _contacteditor_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function contacteditor_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _contacteditor_civix_civicrm_upgrade($op, $queue);
}

/**
 * Add permission to change contact type.
 *
 * @param array $permissions
 */
function contacteditor_civicrm_permission(&$permissions) {
  $permissions['Change CiviCRM contact type'] = [
    E::ts('Change CiviCRM contact type'),
    E::ts('Permits changing a contact type. This may result in data loss if the new type does not support all data of the old type.'),
  ];
}

function contacteditor_civicrm_summaryActions(&$actions, $contactID) {
  if (CRM_Core_Permission::check('Change CiviCRM contact type')) {
    $actions['change_type'] = [
      'title' => 'Change Contact Type',
      'weight' => 901,
      'class' => 'no-popup',
      'ref' => 'change-contact-type',
      'key' => 'change-contact-type',
      'href' => CRM_Utils_System::url('civicrm/contacttypechange', ['contact_id' => $contactID]),
    ];
  }
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
 * function contacteditor_civicrm_preProcess($formName, &$form) {
 *
 * } // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
 * function contacteditor_civicrm_navigationMenu(&$menu) {
 * _contacteditor_civix_insert_navigation_menu($menu, NULL, array(
 * 'label' => E::ts('The Page'),
 * 'name' => 'the_page',
 * 'url' => 'civicrm/the-page',
 * 'permission' => 'access CiviReport,access CiviContribute',
 * 'operator' => 'OR',
 * 'separator' => 0,
 * ));
 * _contacteditor_civix_navigationMenu($menu);
 * } // */

/**
 * Implements hook_civicrm_entityTypes().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function contacteditor_civicrm_entityTypes(&$entityTypes) {
  _contacteditor_civix_civicrm_entityTypes($entityTypes);
}
