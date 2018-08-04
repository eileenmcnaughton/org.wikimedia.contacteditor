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
    if (!empty($request['params']['check_permissions']) && !CRM_Core_Permission::check(array('Change CiviCRM contact type'))) {
      throw new CRM_Core_Exception('You do have not permission to change the contact type');
    }
    $contactTypeSpecificCustomFields = self::getCustomFieldsExclusiveToContactType($existingContactType);
    $contactTypeSpecificFields = array_merge($contactTypeSpecificCustomFields, array(
      'birth_date',
      'contact_sub_type',
      'deceased_date',
      'is_deceased',
      'full_name',
      'first_name',
      'last_name',
      'organization_name',
      'nick_name',
      'suffix_id',
      'prefix_id',
      'gender_id',
      'primary_contact_id',
      'job_title',
      'legal_name',
      'household_name',
    ));

    $existingContact = civicrm_api3('Contact', 'getsingle', [
      'id' => $contactID,
      'return' => $contactTypeSpecificFields,
    ]);
    $existingContact['contact_type'] = $existingContactType;

    if (!empty($params['contact_sub_type']) || !empty($existingContact['contact_sub_type'])) {
      throw new CRM_Core_Exception(E::ts('Contact type cannot be changed if subtype is not empty'));
    }

    if ($existingContactType === 'Individual' && (
        !empty($existingContact['birth_date'])
        || !empty($existingContact['is_deceased'])
        || !empty($existingContact['deceased_date'])
      )) {
      throw new CRM_Core_Exception(E::ts('Individuals cannot be changed to another type while they have birth or death data'));
    }

    if (self::hasCustomFieldsInvalidForNewType($contactTypeSpecificCustomFields, $existingContact)) {
      throw new CRM_Core_Exception(E::ts('The contact has custom data that is not valid for the new type.'));
    }

    // We check the id before checking for more relationships as it might give an early return with less queries.
    if (($newContactType === 'Individual' && !empty($existingContact['primary_contact_id']))
      || self::hasRelationshipsInvalidForNewType($contactID, $newContactType)
    ) {
      throw new CRM_Core_Exception(E::ts('The contact has one or more relationships that are not valid for the new type'));
    }
    $nullableFields = array_intersect_key($existingContact, array_flip($contactTypeSpecificFields));
    $details = array();
    foreach (array_keys($nullableFields) as $nullableField) {
      $params[$nullableField] = 'null';
      if (!empty($existingContact[$nullableField])) {
        $details[] = $nullableField . ' -> ' . $existingContact[$nullableField];
      }
    }

    self::adjustFieldsForContactTypeChange($request['params'], $existingContact);
    civicrm_api3('Activity', 'create', array(
      'target_contact_id' => $contactID,
      'activity_type_id' => 'contact_type_changed',
      'subject' => E::ts('Contact type changed from %1 to %2', array(
        $existingContactType,
        $newContactType,
      )),
      'details' => empty($details) ? '' : E::ts('Data lost by the change : ') . implode(', ', $details),
    ));
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
   * Any passed in parameters will take preference but if not supplied put
   * existing name data into the organization_name, household_name or
   * last_name field as relates to the type. The name fields per the previous
   * type will be nulled.
   *
   * @param array $params
   * @param array $existingContact
   *   Relevant values from saved contact.
   */
  public static function setNamesForContactType(&$params, $existingContact) {
    $nameParts = array();

    if ($existingContact['contact_type'] === 'Individual') {
      $nameFields = array(
        'first_name',
        'nick_name',
        'middle_name',
        'last_name',
      );
      foreach ($nameFields as $nameField) {
        if (!empty($existingContact[$nameField])) {
          $nameParts[$nameField] = $existingContact[$nameField];
          $params[$nameField] = 'null';
        }
      }
    }
    elseif ($existingContact['contact_type'] === 'Household') {
      if (!empty($existingContact['household_name'])) {
        $nameParts['household_name'] = $existingContact['household_name'];
        $params['household_name'] = 'null';
      }
    }
    elseif ($existingContact['contact_type'] === 'Organization') {
      if (!empty($existingContact['organization_name'])) {
        $nameParts['organization_name'] = $existingContact['organization_name'];
        $params['organization_name'] = 'null';
      }
    }
    $storeNameField = 'last_name';
    if ($params['contact_type'] === 'Organization') {
      $storeNameField = 'organization_name';
    }
    if ($params['contact_type'] === 'Household') {
      $storeNameField = 'household_name';
    }
    if (empty($params[$storeNameField])) {
      $params[$storeNameField] = implode(' ', $nameParts);
    }
  }

  /**
   * Check if any of the contact's custom fields are invalid for the new
   * contact type.
   *
   * @param int $contactID
   * @param string $newContactType
   *
   * @return bool
   */
  public static function hasCustomFieldsInvalidForNewType($contactTypeSpecificCustomFields, $existingContact) {
    foreach ($contactTypeSpecificCustomFields as $contactTypeSpecificCustomField) {
      if (!empty($existingContact[$contactTypeSpecificCustomField])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Get the custom fields that are specific to the custom type.
   *
   * ie. get fields that are for Individuals only (but not those that apply to
   * individual by virtue of being for all contact types).
   *
   * @param string $contactType
   */
  public static function getCustomFieldsExclusiveToContactType($contactType) {
    if (!isset(Civi::$statics[__CLASS__]['custom_fields'][$contactType])) {
      Civi::$statics[__CLASS__]['custom_fields'][$contactType] = array();
      $customFields = civicrm_api3('CustomField', 'get', array(
        'custom_group_id.extends' => $contactType,
        'return' => 'id',
      ));
      foreach ($customFields['values'] as $customField) {
        Civi::$statics[__CLASS__]['custom_fields'][$contactType][$customField['id']] = 'custom_' . $customField['id'];
      }
    }
    return Civi::$statics[__CLASS__]['custom_fields'][$contactType];
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
    $directions = array('a', 'b');

    if (!isset(Civi::$statics[__CLASS__]['relationships']['generic'])) {
      Civi::$statics[__CLASS__]['relationships']['generic'] = array();
      foreach ($directions as $direction) {
        $genericRelationshipTypes = $relationshipTypes = civicrm_api3('RelationshipType', 'get', array(
          'contact_type_' . $direction => array('IS NULL' => TRUE),
          'return' => 'id',
        ));
        foreach ($genericRelationshipTypes['values'] as $relationshipType) {
          Civi::$statics[__CLASS__]['relationships']['generic'][$direction][$relationshipType['id']] = TRUE;
        }
      }
    }

    if (!isset(Civi::$statics[__CLASS__]['relationships'][$newContactType])) {
      Civi::$statics[__CLASS__]['relationships'][$newContactType] = array();
      foreach ($directions as $direction) {
        $relationshipTypes = civicrm_api3('RelationshipType', 'get', array(
          'contact_type_' . $direction => $newContactType,
          'return' => 'id',
        ));
        foreach ($relationshipTypes['values'] as $relationshipType) {
          Civi::$statics[__CLASS__]['relationships'][$newContactType][$direction][$relationshipType['id']] = TRUE;
        }
      }
    }

    foreach ($directions as $direction) {
      $relationships = civicrm_api3('Relationship', 'get', array('contact_id_' . $direction => $contactID));
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
   * Get the default details for selected type
   *
   * @param array
   */
  public static function setDefaultGreetingsForContactType(&$params) {
    $contactType = $params['contact_type'];
    foreach (self::getDefaultGreetingsForContactType($contactType) as $key => $value) {
      $params[$key] = $value;
    }
  }

  /**
   * Get the default details for selected type
   *
   * @param array
   */
  public static function getDefaultGreetingsForContactType($contactTypeToRetrieve) {

    if (!isset(Civi::$statics[__CLASS__]['greetings'][$contactTypeToRetrieve])) {
      Civi::$statics[__CLASS__]['greetings'][$contactTypeToRetrieve] = array();

      $optionGroups = civicrm_api3('OptionGroup', 'get', array(
          'name' => array(
            'IN' => array(
              'email_greeting',
              'postal_greeting',
              'addressee',
            ),
          ),
        )
      );

      $optionValues = civicrm_api3('OptionValue', 'get', array(
        'option_group_id' => array('IN' => array_keys($optionGroups['values'])),
        'is_default' => 1,
        'is_active' => 1,
        'return' => array('name', 'option_group_id', 'filter', 'value'),
      ));

      $contactTypes = civicrm_api3('ContactType', 'get', array(
        'is_reserved' => 1,
        'return' => array('name'),
      ));
      foreach ($contactTypes['values'] as $contactTypeID => $contactType) {
        foreach ($optionValues['values'] as $id => $optionValue) {
          $optionGroupName = $optionGroups['values'][$optionValue['option_group_id']]['name'];
          $keyName = $optionGroupName . '_id';

          // If there is no value - e.g Organization does not by default have a postal_greeting then
          // setting to null will clear it out.
          Civi::$statics[__CLASS__]['greetings'][$contactType['name']][$keyName] = 'null';

          if (empty($optionValue['filter']) || $optionValue['filter'] == $contactTypeID) {
            Civi::$statics[__CLASS__]['greetings'][$contactType['name']][$keyName] = $optionValue['value'];
          }
        }
      }
    }

    return Civi::$statics[__CLASS__]['greetings'][$contactTypeToRetrieve];
  }
}
