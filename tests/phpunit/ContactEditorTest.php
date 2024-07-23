<?php

use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

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
class ContactEditorTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;

  public function setUpHeadless(): CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testValidContactChange() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Really an Org', 'email' => 'arealliveperson@example.com']);
    $this->mockLoggedInUser();

    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['Change CiviCRM contact type', 'access CiviCRM', 'edit all contacts'];

    $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'id' => $contact['id']]);

    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => 'addressee']);
    $this->assertEquals('Really an Org', $contact['addressee_display']);
    $activity = $this->callAPISuccessGetSingle('Activity', ['target_contact_id' => $contact['id']]);
    $this->assertEquals('Data lost by the change : first_name -> Really an Org', $activity['details']);
    $this->assertEquals('Contact type changed from Individual to Organization', $activity['subject']);
  }

  /**
   * Test that the all-contacts to all contacts relationship type does not block change.
   */
  public function testValidContactChangeWithChangeableRelationship() {
    $contacta = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Really an Org', 'email' => 'arealliveperson@example.com']);
    $contactb = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'organization_name' => 'Just an Org', 'email' => 'anorg@example.com']);
    $this->mockLoggedInUser();

    $params = [
      'name_a_b' => 'contact of',
      'name_b_a' => 'contact to',
    ];
    // This should be removed by transaction rollback but we can try to reduce the pain.
    $exists = $this->callAPISuccess('RelationshipType', 'get', $params);
    if (!empty($exists['id'])) {
      $params['id'] = $exists['id'];
    }

    $this->callAPISuccess('RelationshipType', 'create', array_merge($params, [
      'contact_type_a' => 'null',
      'contact_type_b' => 'null',
      'api.relationship.create' => ['contact_id_a' => $contacta['id'], 'contact_id_b' => $contactb['id']],
    ]));
    // Clear cache.
    unset(\Civi::$statics['CRM_Contacteditor_ChangeContactType']);

    $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'id' => $contacta['id']]);
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testNoPermissionForContactChange() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Really an Org', 'email' => 'arealliveperson@example.com']);

    CRM_Core_Config::singleton()->userPermissionClass->permissions = [];
    $this->callAPIFailure('Contact', 'create', [
      'contact_type' => 'Organization',
      'id' => $contact['id'],
      'check_permissions' => 1,
    ], 'You do have not permission to change the contact type');
  }

  /**
   * Mock a logged in user.
   */
  protected function mockLoggedInUser() {
    $contact = $this->callApiSuccess('Contact', 'create', ['email' => 'email@example.org', 'contact_type' => 'Individual']);
    // Source contact for activity is the logged in user, emulate.
    CRM_Core_Session::singleton()->set('userID', $contact['id']);
  }

}
