This extension permits a contact to be changed from one type to another.

Currently you can change contact type using the api. This unintentionally allows data loss. Conversely you cannot change the type from the UI.

This extension alters the contact.create api to do the following actions

**Block change if**
 - contact has a relationship that would not be valid on the new type
 - data is held for is_deceased, deceased_date, birth_date - since this is material data that should be manually reviewed. Gender is not on this
 list as it is more likely to be just a default entry.
- contact has custom data that is invalid for the new type.
- new or old contact type has a sub_type as there has been no thought put into these scenarios.

**Create an activity containing non-valid data for new type**
- Organization specific fields organization_name, legal_name, sic_code
- Individual specific fields: suffix, prefix, nick_name, gender,  job_title, gender

**Munges name & greeting data**
if the api does not receive name & greeting fields they are computed:
  - the full name of the individual or household fills the organisation name or household name.
 - the full organization name or household name goes into the last_name.

**Notes**
- By not doing the conversion if the relationship is invalid we are able to
ignore membership issues - since it is the relationship that would
make the conversion invalid.

**Installation**
- After installing, grant the 'Change CiviCRM contact type' permission to appropriate roles
