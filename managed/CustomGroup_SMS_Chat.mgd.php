<?php
declare(strict_types = 1);

use CRM_SmsChat_ExtensionUtil as E;

/**
 * Structured line attribution for SMS activities (see DESIGN.md §4a).
 *
 *   line_number  the organisation-side number: To for inbound, From for outbound
 *   peer_number  the contact-side number actually used
 *
 * Extends the three activity types by NAME (portable across installs whose
 * option values differ). Data-bearing, so cleanup is 'never'.
 */
return [
  [
    'name' => 'CustomGroup_SMS_Chat',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'SMS_Chat',
        'title' => E::ts('SMS Chat'),
        'extends' => 'Activity',
        'extends_entity_column_value:name' => ['SMS', 'Inbound SMS', 'SMS delivery'],
        'style' => 'Inline',
        'collapse_display' => TRUE,
        'is_public' => FALSE,
        'is_reserved' => TRUE,
        'weight' => 100,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'CustomGroup_SMS_Chat_CustomField_line_number',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'SMS_Chat',
        'name' => 'line_number',
        'label' => E::ts('Line Number'),
        'help_pre' => E::ts('The organisation-side phone number this message travelled through.'),
        'data_type' => 'String',
        'html_type' => 'Text',
        'text_length' => 32,
        'is_searchable' => TRUE,
        'is_view' => TRUE,
        'is_reserved' => TRUE,
        'weight' => 1,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomGroup_SMS_Chat_CustomField_peer_number',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'SMS_Chat',
        'name' => 'peer_number',
        'label' => E::ts('Contact Number'),
        'help_pre' => E::ts('The contact-side phone number this message travelled through.'),
        'data_type' => 'String',
        'html_type' => 'Text',
        'text_length' => 32,
        'is_searchable' => TRUE,
        'is_view' => TRUE,
        'is_reserved' => TRUE,
        'weight' => 2,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
];
