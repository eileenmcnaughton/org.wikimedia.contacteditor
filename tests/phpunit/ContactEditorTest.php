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
      ->callback([__CLASS__, 'createCustomGroups'], 'contacteditor-custom-groups')
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    unset(\Civi::$statics['CRM_Contacteditor_ChangeContactType']);
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
   * Test that an organization changed to an individual gets the individual default greetings.
   */
  public function testChangeToIndividualSetsDefaultGreetings() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'organization_name' => 'Really a person']);
    $this->mockLoggedInUser();
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['Change CiviCRM contact type', 'access CiviCRM', 'edit all contacts'];

    $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'id' => $contact['id'], 'first_name' => 'Really', 'last_name' => 'Person']);

    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => ['email_greeting_id', 'postal_greeting_id', 'addressee_id']]);
    foreach (['email_greeting', 'postal_greeting', 'addressee'] as $greeting) {
      $default = $this->callAPISuccessGetValue('OptionValue', [
        'option_group_id' => $greeting,
        'is_default' => 1,
        'filter' => 1,
        'return' => 'value',
      ]);
      $this->assertEquals($default, $contact[$greeting . '_id'], $greeting);
    }
  }

  /**
   * Test that greetings passed with the change are kept.
   */
  public function testPassedGreetingIsKept() {
    $greeting = $this->callAPISuccessGetValue('OptionValue', ['option_group_id' => 'email_greeting', 'is_default' => 0, 'filter' => 1, 'options' => ['limit' => 1], 'return' => 'value']);
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'organization_name' => 'Acme Ltd']);
    $this->changeType($contact['id'], ['contact_type' => 'Individual', 'email_greeting_id' => $greeting]);

    $this->assertEquals($greeting, $this->callAPISuccessGetValue('Contact', ['id' => $contact['id'], 'return' => 'email_greeting_id']));
  }

  /**
   * Test that a custom greeting text, with or without the Customized id, can be passed with the change.
   *
   * @dataProvider withIdProvider
   */
  public function testPassedCustomGreetingIsKept(bool $withId) {
    $customized = $this->callAPISuccessGetValue('OptionValue', ['option_group_id' => 'addressee', 'name' => 'Customized', 'return' => 'value']);
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'organization_name' => 'Acme Ltd']);
    $this->changeType($contact['id'], ['contact_type' => 'Individual', 'addressee_custom' => 'Dr. Jane Example'] + ($withId ? ['addressee_id' => $customized] : []));

    // APIv3 Contact.get does not return addressee_custom.
    $contact = \Civi\Api4\Contact::get(FALSE)->addSelect('addressee_id', 'addressee_custom')->addWhere('id', '=', $contact['id'])->execute()->single();
    $this->assertEquals($customized, $contact['addressee_id']);
    $this->assertEquals('Dr. Jane Example', $contact['addressee_custom']);
  }

  /**
   * Test that a custom greeting text dropped by the change is recorded in the activity.
   *
   * @dataProvider withIdProvider
   */
  public function testDroppedCustomGreetingIsRecorded(bool $withId) {
    $customized = $this->callAPISuccessGetValue('OptionValue', ['option_group_id' => 'addressee', 'name' => 'Customized', 'return' => 'value']);
    $default = $this->callAPISuccessGetValue('OptionValue', ['option_group_id' => 'addressee', 'is_default' => 1, 'filter' => 1, 'return' => 'value']);
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'organization_name' => 'Acme Ltd', 'addressee_id' => $customized, 'addressee_custom' => 'The Acme team']);
    $this->changeType($contact['id'], ['contact_type' => 'Individual'] + ($withId ? ['addressee_id' => $default] : []));

    $details = $this->callAPISuccessGetValue('Activity', ['target_contact_id' => $contact['id'], 'activity_type_id' => 'contact_type_changed', 'return' => 'details']);
    $this->assertStringContainsString('addressee_custom -> The Acme team', $details);
    $this->assertNull(\Civi\Api4\Contact::get(FALSE)->addSelect('addressee_custom')->addWhere('id', '=', $contact['id'])->execute()->single()['addressee_custom']);
  }

  public function withIdProvider(): array {
    return ['without id' => [FALSE], 'with id' => [TRUE]];
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
   * Test that fields of the old type are cleared and all name parts move to the new name.
   */
  public function testFieldsOfTheOldTypeAreCleared() {
    $contact = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Ada',
      'middle_name' => 'Maria',
      'last_name' => 'Example',
      'nick_name' => 'Addy',
      'job_title' => 'Engineer',
      'gender_id' => 1,
      'formal_title' => 'Dr.',
    ]);
    $this->changeType($contact['id'], ['contact_type' => 'Organization']);

    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => ['organization_name', 'first_name', 'middle_name', 'last_name', 'nick_name', 'job_title', 'gender_id', 'formal_title']]);
    $this->assertEquals('Ada Maria Example', $contact['organization_name']);
    $this->assertEquals('Addy', $contact['nick_name']);
    foreach (['first_name', 'middle_name', 'last_name', 'job_title', 'gender_id', 'formal_title'] as $field) {
      $this->assertEquals('', $contact[$field], $field);
    }
  }

  /**
   * Test that a name passed for the new type is not mixed with the old name.
   */
  public function testPassedNameTakesPreference() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'organization_name' => 'Acme Ltd', 'legal_name' => 'Acme Limited']);
    $this->changeType($contact['id'], ['contact_type' => 'Individual', 'first_name' => 'Jane']);

    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => ['first_name', 'last_name', 'organization_name', 'legal_name', 'display_name']]);
    $this->assertEquals('Jane', $contact['first_name']);
    $this->assertEquals('', $contact['last_name']);
    $this->assertEquals('', $contact['organization_name']);
    $this->assertEquals('', $contact['legal_name']);
    $this->assertEquals('Jane', $contact['display_name']);
  }

  /**
   * Test that the APIv3 'null' marker does not count as a passed name.
   */
  public function testNullMarkerIsNoValue() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'organization_name' => 'Acme Ltd']);
    $this->changeType($contact['id'], ['contact_type' => 'Individual', 'first_name' => 'null']);

    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => ['contact_type', 'first_name', 'last_name']]);
    $this->assertEquals('Individual', $contact['contact_type']);
    $this->assertEquals('', $contact['first_name']);
    $this->assertEquals('Acme Ltd', $contact['last_name']);
  }

  /**
   * Test that a contact without name parts keeps its display name.
   */
  public function testContactWithoutNameKeepsDisplayName() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'email' => 'noname@example.org']);
    $this->changeType($contact['id'], ['contact_type' => 'Organization']);

    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => ['organization_name', 'display_name']]);
    $this->assertEquals('noname@example.org', $contact['organization_name']);
    $this->assertEquals('noname@example.org', $contact['display_name']);
  }

  /**
   * Test that a subtype passed with the change is refused.
   */
  public function testSubtypeInTheRequestIsRefused() {
    $this->callAPISuccess('ContactType', 'create', ['name' => 'Contacteditor_Test', 'label' => 'Contacteditor test', 'parent_id' => 'Individual']);
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'organization_name' => 'Org']);
    $this->mockLoggedInUser();
    $this->callAPIFailure('Contact', 'create', [
      'id' => $contact['id'],
      'contact_type' => 'Individual',
      'contact_sub_type' => 'Contacteditor_Test',
    ], 'Contact type cannot be changed if subtype is not empty');
  }

  /**
   * Test that zero and multi-record custom values of the old type block the change.
   *
   * @dataProvider customDataProvider
   */
  public function testCustomDataOfTheOldTypeBlocksChange(string $groupName, string $sqlType, $value, bool $isMultiple) {
    $group = $this->callAPISuccessGetSingle('CustomGroup', ['name' => $groupName, 'return' => ['table_name']]);
    $column = $this->callAPISuccessGetValue('CustomField', ['custom_group_id' => $group['id'], 'return' => 'column_name']);
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Has', 'last_name' => 'Data']);
    $rows = $isMultiple ? '(%1, %2), (%1, NULL)' : '(%1, %2)';
    CRM_Core_DAO::executeQuery("INSERT INTO {$group['table_name']} (entity_id, $column) VALUES $rows", [1 => [$contact['id'], 'Integer'], 2 => [$value, $sqlType]]);

    $this->mockLoggedInUser();
    $this->callAPIFailure('Contact', 'create', ['id' => $contact['id'], 'contact_type' => 'Organization'], 'The contact has custom data that is not valid for the new type.');
  }

  public function customDataProvider(): array {
    return [
      'boolean no' => ['Contacteditor_Boolean', 'Integer', 0, FALSE],
      'multi-record' => ['Contacteditor_Multiple', 'String', 'filled', TRUE],
    ];
  }

  /**
   * Custom groups for Individuals, created with the schema so transactions roll back their data.
   */
  public static function createCustomGroups(): void {
    $groups = [
      'Contacteditor_Boolean' => [FALSE, 'Boolean', 'Radio'],
      'Contacteditor_Multiple' => [TRUE, 'String', 'Text'],
    ];
    foreach ($groups as $name => [$isMultiple, $dataType, $htmlType]) {
      $group = civicrm_api3('CustomGroup', 'create', ['name' => $name, 'title' => $name, 'extends' => 'Individual', 'is_multiple' => $isMultiple, 'style' => $isMultiple ? 'Tab' : 'Inline']);
      civicrm_api3('CustomField', 'create', ['custom_group_id' => $group['id'], 'label' => 'Value', 'data_type' => $dataType, 'html_type' => $htmlType]);
    }
  }

  /**
   * Test that a household with a primary contact cannot become an organization.
   */
  public function testHouseholdWithPrimaryContactIsRefused() {
    $head = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Head', 'last_name' => 'Of House']);
    $household = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Household', 'household_name' => 'House']);
    CRM_Core_DAO::setFieldValue('CRM_Contact_DAO_Contact', $household['id'], 'primary_contact_id', $head['id']);

    $this->mockLoggedInUser();
    $this->callAPIFailure('Contact', 'create', ['id' => $household['id'], 'contact_type' => 'Organization'], 'The contact has one or more relationships that are not valid for the new type');
  }

  /**
   * Test that relationship types beyond the first 25 are known.
   */
  public function testRelationshipOfAnyGenericTypeAllowsChange() {
    $individual = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Many', 'last_name' => 'Types']);
    $other = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Organization', 'organization_name' => 'Other']);
    for ($i = 1; $i <= 30; $i++) {
      $type = $this->callAPISuccess('RelationshipType', 'create', ['name_a_b' => "generic $i of", 'name_b_a' => "generic $i to", 'contact_type_a' => 'null', 'contact_type_b' => 'null']);
    }
    $this->callAPISuccess('Relationship', 'create', ['contact_id_a' => $individual['id'], 'contact_id_b' => $other['id'], 'relationship_type_id' => $type['id']]);

    $this->changeType($individual['id'], ['contact_type' => 'Organization']);
    $this->assertEquals('Organization', $this->callAPISuccessGetValue('Contact', ['id' => $individual['id'], 'return' => 'contact_type']));
  }

  /**
   * Change the contact type as a logged in user.
   */
  protected function changeType(int $contactID, array $params): void {
    $this->mockLoggedInUser();
    $this->callAPISuccess('Contact', 'create', ['id' => $contactID] + $params);
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
