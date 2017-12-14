<?php

use CRM_Contacteditor_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

require_once __DIR__ . '/BaseUnitTestClass.php';
/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class ContactEditorTest extends BaseUnitTestClass implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testValidContactChange() {
    $contact = civicrm_api3('Contact', 'create', array('contact_type' => 'Individual', 'first_name' => 'Really an Org', 'email' => 'arealliveperson@example.com'));
    CRM_Core_Config::singleton()->userPermissionClass->permissions = array('Change CiviCRM contact type', 'access CiviCRM', 'edit all contacts');
    $this->callAPISuccess('Contact', 'create', array('contact_type' => 'Organization', 'id' => $contact['id']));
    $contact = $this->callAPISuccess('Contact', 'getsingle', array('id' => $contact['id'], 'return' => 'addressee'));
    $this->assertEquals('Really an Org', $contact['addressee_display']);
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testNoPermissionForContactChange() {
    $contact = civicrm_api3('Contact', 'create', array('contact_type' => 'Individual', 'first_name' => 'Really an Org', 'email' => 'arealliveperson@example.com'));

    CRM_Core_Config::singleton()->userPermissionClass->permissions = array();
    $this->callAPIFailure('Contact', 'create', array('contact_type' => 'Organization', 'id' => $contact['id'], 'check_permissions' => 1
    ), 'You do have not permission to change the contact type');
  }

}
