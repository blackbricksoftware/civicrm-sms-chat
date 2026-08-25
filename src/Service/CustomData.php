<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Service;

use Civi\Api4\CustomField;

/**
 * Is the SMS_Chat custom group (managed/CustomGroup_SMS_Chat.mgd.php) usable
 * right now? "Usable" means ACTIVE group + active field: API4 only publishes
 * active custom fields, so querying an inactive one fails with "Invalid
 * field" — a DB-level existence check is not enough. (Managed entities are
 * disabled whenever their extension is disabled, so this state is reachable.)
 */
final class CustomData {

  public const GROUP = 'SMS_Chat';

  private static ?bool $available = NULL;

  public static function available(): bool {
    if (self::$available === NULL) {
      try {
        self::$available = (bool) CustomField::get(FALSE)
          ->addSelect('id')
          ->addWhere('name', '=', 'line_number')
          ->addWhere('is_active', '=', TRUE)
          ->addWhere('custom_group_id:name', '=', self::GROUP)
          ->addWhere('custom_group_id.is_active', '=', TRUE)
          ->execute()->first();
      }
      catch (\Throwable $e) {
        self::$available = FALSE;
      }
    }
    return self::$available;
  }

}
