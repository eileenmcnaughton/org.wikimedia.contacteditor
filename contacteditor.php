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
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function contacteditor_civicrm_enable() {
  _contacteditor_civix_civicrm_enable();
}

/**
 * Add permission to change contact type.
 *
 * @param array $permissions
 */
function contacteditor_civicrm_permission(&$permissions) {
  $permissions['Change CiviCRM contact type'] = [
    'label' => E::ts('Change CiviCRM contact type'),
    'description' => E::ts('Permits changing a contact type. This may result in data loss if the new type does not support all data of the old type.'),
  ];
}

function contacteditor_civicrm_summaryActions(&$actions, $contactID) {
  if (CRM_Core_Permission::check('Change CiviCRM contact type')) {
    $actions['otherActions']['change_contact_type'] = [
      'title' => E::ts('Change Contact Type'),
      'weight' => 901,
      'class' => 'no-popup',
      'ref' => 'change-contact-type',
      'key' => 'change-contact-type',
      'href' => CRM_Utils_System::url('civicrm/contacttypechange', ['contact_id' => $contactID]),
      'icon' => 'crm-i fa-exchange',
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
