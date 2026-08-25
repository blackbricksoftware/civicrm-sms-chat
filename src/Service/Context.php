<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Service;

use Civi\Api4\Contact;
use Civi\Api4\Phone;

/**
 * Everything the chat header/composer needs to render its state in one call:
 * who the contact is, which mobile numbers they have, whether they can be
 * texted (and if not, exactly why), which lines exist, and what the viewer
 * may do. No new permission model: `send SMS` governs sending, contact
 * visibility governs the rest.
 */
final class Context {

  public const MAX_CHARS = 460; // CRM_SMS_Provider::MAX_SMS_CHAR

  public static function build(int $contactId, bool $checkPermissions): array {
    $contact = Contact::get($checkPermissions)
      ->addSelect('id', 'display_name', 'contact_type', 'do_not_sms', 'is_deceased', 'is_deleted')
      ->addWhere('id', '=', $contactId)
      ->execute()->first();
    if (!$contact) {
      throw new \CRM_Core_Exception('Contact not found or not visible');
    }

    // ALL phones, so the UI can explain "no_mobile" precisely: core's SMS
    // send (which we mirror, never bypass) only texts numbers typed Mobile,
    // yet plenty of contacts who text in have their number typed "Phone".
    $phones = (array) Phone::get($checkPermissions)
      ->addSelect('id', 'phone', 'phone_numeric', 'is_primary', 'phone_type_id:label', 'phone_type_id:name', 'location_type_id:label')
      ->addWhere('contact_id', '=', $contactId)
      ->addOrderBy('is_primary', 'DESC')
      ->addOrderBy('id', 'ASC')
      ->execute();
    $usable = array_values(array_filter($phones, fn($p) => !empty($p['phone_numeric']) && $p['phone_type_id:name'] === 'Mobile'));

    $lines = array_values(Lines::all());
    $canSendPerm = \CRM_Core_Permission::check('send SMS');

    $blockers = [];
    if (!$canSendPerm) {
      $blockers[] = 'no_permission';
    }
    if (!$lines) {
      $blockers[] = 'no_provider';
    }
    if (!empty($contact['is_deceased'])) {
      $blockers[] = 'deceased';
    }
    if (!empty($contact['do_not_sms'])) {
      $blockers[] = 'do_not_sms';
    }
    if (!$usable) {
      $blockers[] = 'no_mobile';
    }

    $me = \CRM_Core_Session::getLoggedInContactID();
    $config = \Civi::service(Config::class);

    return [
      'contact' => [
        'id' => (int) $contact['id'],
        'displayName' => $contact['display_name'],
        'contactType' => $contact['contact_type'],
      ],
      'phones' => array_map(fn($p) => [
        'id' => (int) $p['id'],
        'phone' => $p['phone'],
        'numeric' => $p['phone_numeric'],
        'isPrimary' => (bool) $p['is_primary'],
      ], $usable),
      'allPhones' => array_map(fn($p) => [
        'id' => (int) $p['id'],
        'phone' => $p['phone'],
        'type' => $p['phone_type_id:label'],
        'location' => $p['location_type_id:label'],
        'isPrimary' => (bool) $p['is_primary'],
        'isMobile' => $p['phone_type_id:name'] === 'Mobile',
      ], $phones),
      'lines' => $lines,
      'canSms' => $blockers === [],
      'blockers' => $blockers,
      'maxChars' => self::MAX_CHARS,
      // Safety posture, so the UI can say what will actually happen on Send.
      'sendMode' => $config->testMode() ? 'test' : 'live',
      'lockdown' => [
        'active' => $config->lockdownActive(),
        'environment' => $config->environment(),
        'allowed' => $config->allowedRecipients(),
      ],
      'viewer' => [
        'contactId' => $me ? (int) $me : NULL,
        'displayName' => $me ? (\CRM_Core_Session::singleton()->getLoggedInContactDisplayName() ?: NULL) : NULL,
      ],
    ];
  }

}
