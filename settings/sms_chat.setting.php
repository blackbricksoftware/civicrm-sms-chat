<?php
declare(strict_types = 1);

use CRM_SmsChat_ExtensionUtil as E;

/**
 * Every setting here is both UI-editable (Administer › System Settings ›
 * SMS Chat) and env-overridable via the CIVICRM_SMSCHAT_* variable in
 * `global_name`. When the env var is set, CiviCRM's SettingsBag::getMandatory()
 * returns non-NULL and the settings form renders the field read-only
 * (BlackBrickSoftware\CiviCRMSmsChat\Subscriber\SettingsFormSubscriber).
 */
return [
  'sms_chat_details_preamble' => [
    'name' => 'sms_chat_details_preamble',
    'type' => 'Boolean',
    'default' => TRUE,
    'html_type' => 'checkbox',
    'title' => E::ts('Write From/To preamble into inbound SMS details'),
    'description' => E::ts('Prepends "From: … To: …" to the details of every Inbound SMS activity so the plain activity list shows which line a message arrived on. Structured attribution is always stored in the SMS Chat custom fields regardless.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'is_env_loadable' => TRUE,
    'global_name' => 'CIVICRM_SMSCHAT_DETAILS_PREAMBLE',
  ],
  'sms_chat_allowed_recipients' => [
    'name' => 'sms_chat_allowed_recipients',
    'type' => 'String',
    'default' => '',
    'html_type' => 'text',
    'title' => E::ts('Allowed SMS recipients'),
    'description' => E::ts('Comma-separated E.164 numbers and/or prefixes (e.g. +13235551234,+1323). When non-empty, SMS Chat refuses to send to any other number. Always enforced when set; the environment lockdown makes it mandatory outside Production.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'is_env_loadable' => TRUE,
    'global_name' => 'CIVICRM_SMSCHAT_ALLOWED_RECIPIENTS',
  ],
  'sms_chat_environment_lockdown' => [
    'name' => 'sms_chat_environment_lockdown',
    'type' => 'Boolean',
    'default' => TRUE,
    'html_type' => 'checkbox',
    'title' => E::ts('Lock down sending outside Production'),
    'description' => E::ts('When the CiviCRM environment (Administer › System Settings › Misc) is not "Production", SMS Chat only sends to numbers on the allowed-recipients list, and refuses everything when that list is empty. Protects dev/staging copies of production data from texting real people.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'is_env_loadable' => TRUE,
    'global_name' => 'CIVICRM_SMSCHAT_ENVIRONMENT_LOCKDOWN',
  ],
  'sms_chat_test_mode' => [
    'name' => 'sms_chat_test_mode',
    'type' => 'Boolean',
    'default' => FALSE,
    'html_type' => 'checkbox',
    'title' => E::ts('Test mode (record, do not deliver)'),
    'description' => E::ts('Messages sent from SMS Chat are recorded as SMS activities exactly as a real send would be, but are never handed to the SMS provider. For exercising the full UI in development without any provider traffic.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'is_env_loadable' => TRUE,
    'global_name' => 'CIVICRM_SMSCHAT_TEST_MODE',
  ],
];
