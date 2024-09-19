<?php

use CRM_Contacteditor_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_Contacteditor_Form_ContactTypeChange extends CRM_Core_Form {

  protected $contactID;

  protected $contact;

  /**
   * Explicitly declare the form context.
   */
  public function getDefaultContext() {
    return 'create';
  }

  /**
   * Explicitly declare the entity api name.
   */
  public function getDefaultEntity() {
    return 'Contact';
  }

  /**
   * Form pre-processing.
   */
  public function preProcess() {
    $this->setAction(CRM_Core_Action::UPDATE);
    $this->contactID = CRM_Utils_Request::retrieveValue('contact_id', 'Positive', $this->get('contactID'));
    $this->set('contactID', $this->contactID);
    $this->contact = civicrm_api3('Contact', 'getsingle', array('id' => $this->contactID, 'return' => ['contact_type', 'display_name']));
    $this->assign('introText', E::ts('Change contact type for %1', array($this->contact['display_name'])));
  }

  /**
   * Build the form.
   */
  public function buildQuickForm() {
    $this->addField('contact_type');
    $this->assign('elementNames', 'contact_type');

    $buttons = array(
      array(
        'type' => 'upload',
        'name' => ts('Save'),
        'isDefault' => TRUE,
      ),
      array(
        'type' => 'cancel',
        'name' => ts('Cancel'),
      ),
    );
    $this->addButtons($buttons);

    parent::buildQuickForm();
  }

  /**
   * Process form submission.
   */
  public function postProcess() {
    $params = $this->exportValues();

    $params['contact_id'] = $this->contactID;

    try {
      civicrm_api3('Contact', 'create', $params);
      CRM_Core_Session::setStatus(E::ts('Contact type updated'));
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Session::setStatus($e->getMessage(), E::ts('Contact type not changed'));
    }
    parent::postProcess();
  }

  /**
   * Set defaults for the form.
   *
   * @return array
   */
  public function setDefaultValues() {
    $defaults['contact_type'] = $this->contact['contact_type'];
    return $defaults;
  }

}
