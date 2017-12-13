<?php

require_once 'contacteditor.civix.php';
use CRM_Contacteditor_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function contacteditor_civicrm_config(&$config) {
  Civi::dispatcher()->addListener('civi.api.prepare', array('CRM_Contacteditor_ChangeContactType', 'validate'), -100);
  _contacteditor_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function contacteditor_civicrm_xmlMenu(&$files) {
  _contacteditor_civix_civicrm_xmlMenu($files);
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
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function contacteditor_civicrm_managed(&$entities) {
  _contacteditor_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function contacteditor_civicrm_caseTypes(&$caseTypes) {
  _contacteditor_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function contacteditor_civicrm_angularModules(&$angularModules) {
  _contacteditor_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function contacteditor_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _contacteditor_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Add permission to change contact type.
 *
 * @param array $permissions
 */
function contacteditor_civicrm_permission(&$permissions) {
  $permissions['Change CiviCRM contact type'] = array(
    E::ts('Change CiviCRM contact type'),
    E::ts('Permits changing a contact type. This may result in data loss if the new type does not support all data of the old type.'),
  );
}

function contacteditor_civicrm_summaryActions(&$actions, $contactID) {
  if (CRM_Core_Permission::check('Change CiviCRM contact type')) {
    $actions['casework'] = array(
      'title' => 'Change Contact Type',
      'weight' => 999,
      'class' => 'no-popup',
      'ref' => 'change-contact-type',
      'key' => 'change-contact-type',
      'href' => '/civicrm/contacttypechange?contact_id=' . $contactID
    );
  }
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function contacteditor_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function contacteditor_civicrm_navigationMenu(&$menu) {
  _contacteditor_civix_insert_navigation_menu($menu, NULL, array(
    'label' => E::ts('The Page'),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _contacteditor_civix_navigationMenu($menu);
} // */
