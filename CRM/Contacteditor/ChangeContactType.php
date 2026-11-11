<?php

use CRM_Contacteditor_ExtensionUtil as E;

/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 11/22/17
 * Time: 2:55 PM
 */
class CRM_Contacteditor_ChangeContactType {

  /**
   * Core fields that belong to one contact type only (core schema/Contact/Contact.entityType.php).
   */
  const TYPE_SPECIFIC_FIELDS = [
    'Individual' => ['first_name', 'middle_name', 'last_name', 'prefix_id', 'suffix_id', 'formal_title', 'job_title', 'gender_id', 'birth_date', 'employer_id'],
    'Organization' => ['organization_name', 'legal_name', 'sic_code'],
    'Household' => ['household_name', 'primary_contact_id'],
  ];

  /**
   * Name fields per contact type, in display order.
   */
  const NAME_FIELDS = [
    'Individual' => ['first_name', 'middle_name', 'last_name'],
    'Organization' => ['organization_name'],
    'Household' => ['household_name'],
  ];

  /**
   * Custom greeting text per greeting id field.
   */
  const GREETING_CUSTOM_FIELDS = [
    'email_greeting_id' => 'email_greeting_custom',
    'postal_greeting_id' => 'postal_greeting_custom',
    'addressee_id' => 'addressee_custom',
  ];

  /**
   * Validate whether the contact type is being changed and if so whether it is
   * permitted.
   *
   * @param Civi\API\Event\PrepareEvent $apiRequest
   *
   * @throws \CRM_Core_Exception
   */
  public static function validate($apiRequest) {
    $request = $apiRequest->getApiRequest();
    if ($request['entity'] !== 'Contact' || $request['action'] !== 'create') {
      return;
    }
    if (empty($request['params']['id']) || empty($request['params']['contact_type'])) {
      // Hook is only interested in changes that update contact type.
      return;
    }
    $contactID = $request['params']['id'];
    $newContactType = $request['params']['contact_type'];
    // Usually we will return after getting this - if we proceed past here we do more extensive data gathering as it is 'the real deal'.
    $existingContactType = civicrm_api3('Contact', 'getvalue', [
      'id' => $contactID,
      'return' => 'contact_type',
    ]);
    if ($existingContactType === $newContactType) {
      return;
    }
    if (!empty($request['params']['check_permissions']) && !CRM_Core_Permission::check(['Change CiviCRM contact type'])) {
      throw new CRM_Core_Exception('You do have not permission to change the contact type');
    }
    $oldTypeFields = self::TYPE_SPECIFIC_FIELDS[$existingContactType] ?? [];
    // APIv3 Contact.get does not return primary_contact_id.
    $existingContact = \Civi\Api4\Contact::get(FALSE)
      ->setSelect(array_merge($oldTypeFields, self::GREETING_CUSTOM_FIELDS, ['contact_sub_type', 'is_deceased', 'deceased_date', 'display_name']))
      ->addWhere('id', '=', $contactID)
      ->execute()
      ->single();
    $existingContact['contact_type'] = $existingContactType;

    if (!empty($request['params']['contact_sub_type']) || !empty($existingContact['contact_sub_type'])) {
      throw new CRM_Core_Exception(E::ts('Contact type cannot be changed if subtype is not empty'));
    }

    if ($existingContactType === 'Individual' && (
        !empty($existingContact['birth_date'])
        || !empty($existingContact['is_deceased'])
        || !empty($existingContact['deceased_date'])
      )) {
      throw new CRM_Core_Exception(E::ts('Individuals cannot be changed to another type while they have birth or death data'));
    }

    if (self::hasCustomDataExclusiveToContactType($contactID, $existingContactType)) {
      throw new CRM_Core_Exception(E::ts('The contact has custom data that is not valid for the new type.'));
    }

    // We check the id before checking for more relationships as it might give an early return with less queries.
    if (!empty($existingContact['primary_contact_id'])
      || self::hasRelationshipsInvalidForNewType($contactID, $newContactType)
    ) {
      throw new CRM_Core_Exception(E::ts('The contact has one or more relationships that are not valid for the new type'));
    }
    $clearedFields = array_diff($oldTypeFields, self::TYPE_SPECIFIC_FIELDS[$newContactType] ?? []);
    $details = [];
    foreach ($clearedFields as $field) {
      if (isset($existingContact[$field]) && $existingContact[$field] !== '') {
        $details[] = $field . ' -> ' . $existingContact[$field];
      }
    }
    // Core drops the custom text unless the request passes one.
    foreach (self::GREETING_CUSTOM_FIELDS as $customField) {
      if (self::isFilled($existingContact[$customField]) && ($request['params'][$customField] ?? NULL) !== $existingContact[$customField]) {
        $details[] = $customField . ' -> ' . $existingContact[$customField];
      }
    }

    self::adjustFieldsForContactTypeChange($request['params'], $existingContact);
    foreach ($clearedFields as $field) {
      $request['params'][$field] = 'null';
    }
    civicrm_api3('Activity', 'create', [
      'target_contact_id' => $contactID,
      'activity_type_id' => 'contact_type_changed',
      'subject' => E::ts('Contact type changed from %1 to %2', [
        1 => $existingContactType,
        2 => $newContactType,
      ]),
      'details' => empty($details) ? '' : E::ts('Data lost by the change : ') . implode(', ', $details),
    ]);
    $apiRequest->setApiRequest($request);
  }

  /**
   * Make field adjustments required for contact type change.
   *
   * @param array $params
   * @param array $existingContact
   */
  public static function adjustFieldsForContactTypeChange(&$params, $existingContact) {
    self::setDefaultGreetingsForContactType($params);
    self::setNamesForContactType($params, $existingContact);
  }

  /**
   * Set the name for the new contact type based on information from the old
   * one.
   *
   * Any name passed in for the new type takes preference. Otherwise the
   * existing name goes into the organization_name, household_name or
   * last_name field as relates to the type, falling back to the display name.
   *
   * @param array $params
   * @param array $existingContact
   *   Relevant values from saved contact.
   */
  public static function setNamesForContactType(&$params, $existingContact) {
    $newContactType = $params['contact_type'];
    foreach (self::NAME_FIELDS[$newContactType] ?? [] as $nameField) {
      if (self::isFilled($params[$nameField] ?? NULL)) {
        return;
      }
    }
    $nameParts = [];
    foreach (self::NAME_FIELDS[$existingContact['contact_type']] ?? [] as $nameField) {
      if (!empty($existingContact[$nameField])) {
        $nameParts[] = $existingContact[$nameField];
      }
    }
    $storeNameField = [
      'Individual' => 'last_name',
      'Organization' => 'organization_name',
      'Household' => 'household_name',
    ][$newContactType];
    $params[$storeNameField] = implode(' ', $nameParts) ?: ($existingContact['display_name'] ?? '');
  }

  /**
   * Whether an API parameter carries a value; 'null' is APIv3's marker for clearing a field.
   *
   * @param mixed $value
   *
   * @return bool
   */
  public static function isFilled($value) {
    return !empty($value) && $value !== 'null';
  }

  /**
   * Check whether the contact has values in custom fields that only apply to its current type.
   *
   * Reads the custom tables directly so every row of a multi-record group counts.
   *
   * @param int $contactID
   * @param string $contactType
   *
   * @return bool
   */
  public static function hasCustomDataExclusiveToContactType($contactID, $contactType) {
    $groups = civicrm_api3('CustomGroup', 'get', [
      'extends' => $contactType,
      'return' => ['table_name'],
      'options' => ['limit' => 0],
    ]);
    foreach ($groups['values'] as $group) {
      $fields = civicrm_api3('CustomField', 'get', [
        'custom_group_id' => $group['id'],
        'return' => ['column_name', 'data_type'],
        'options' => ['limit' => 0],
      ]);
      $conditions = [];
      foreach ($fields['values'] as $field) {
        $column = CRM_Utils_Type::escape($field['column_name'], 'MysqlColumnNameOrAlias');
        $isText = in_array($field['data_type'], ['String', 'Memo', 'Link'], TRUE);
        $conditions[] = $isText ? "($column IS NOT NULL AND $column <> '')" : "$column IS NOT NULL";
      }
      if (!$conditions) {
        continue;
      }
      $table = CRM_Utils_Type::escape($group['table_name'], 'MysqlColumnNameOrAlias');
      $hasData = CRM_Core_DAO::singleValueQuery(
        "SELECT 1 FROM $table WHERE entity_id = %1 AND (" . implode(' OR ', $conditions) . ') LIMIT 1',
        [1 => [$contactID, 'Integer']]
      );
      if ($hasData) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Check if any of the contact's relationships invalid for the new contact
   * type.
   *
   * @param int $contactID
   * @param string $newContactType
   *
   * @return bool
   */
  public static function hasRelationshipsInvalidForNewType($contactID, $newContactType) {
    $directions = ['a', 'b'];

    if (!isset(Civi::$statics[__CLASS__]['relationships']['generic'])) {
      Civi::$statics[__CLASS__]['relationships']['generic'] = [];
      foreach ($directions as $direction) {
        $genericRelationshipTypes = $relationshipTypes = civicrm_api3('RelationshipType', 'get', [
          'contact_type_' . $direction => ['IS NULL' => TRUE],
          'return' => 'id',
          'options' => ['limit' => 0],
        ]);
        foreach ($genericRelationshipTypes['values'] as $relationshipType) {
          Civi::$statics[__CLASS__]['relationships']['generic'][$direction][$relationshipType['id']] = TRUE;
        }
      }
    }

    if (!isset(Civi::$statics[__CLASS__]['relationships'][$newContactType])) {
      Civi::$statics[__CLASS__]['relationships'][$newContactType] = [];
      foreach ($directions as $direction) {
        $relationshipTypes = civicrm_api3('RelationshipType', 'get', [
          'contact_type_' . $direction => $newContactType,
          'return' => 'id',
          'options' => ['limit' => 0],
        ]);
        foreach ($relationshipTypes['values'] as $relationshipType) {
          Civi::$statics[__CLASS__]['relationships'][$newContactType][$direction][$relationshipType['id']] = TRUE;
        }
      }
    }

    foreach ($directions as $direction) {
      $relationships = civicrm_api3('Relationship', 'get', [
        'contact_id_' . $direction => $contactID,
        'return' => ['relationship_type_id'],
        'options' => ['limit' => 0],
      ]);
      foreach ($relationships['values'] as $relationship) {
        if (!isset(Civi::$statics[__CLASS__]['relationships'][$newContactType][$direction][$relationship['relationship_type_id']])
          && !isset(Civi::$statics[__CLASS__]['relationships']['generic'][$direction][$relationship['relationship_type_id']])
        ) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Set the defaults of the new type for the greetings the request leaves out.
   *
   * @param array $params
   */
  public static function setDefaultGreetingsForContactType(&$params) {
    foreach (self::GREETING_CUSTOM_FIELDS as $idField => $customField) {
      // Core derives the Customized id from a passed custom text.
      if (!self::isFilled($params[$idField] ?? NULL) && !self::isFilled($params[$customField] ?? NULL)) {
        // 'null' clears a greeting the new type has no default for, e.g. an organization's postal greeting.
        $params[$idField] = CRM_Contact_BAO_Contact_Utils::defaultGreeting($params['contact_type'], substr($idField, 0, -3)) ?? 'null';
      }
    }
  }
}
