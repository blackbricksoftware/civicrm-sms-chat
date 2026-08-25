<?php
declare(strict_types = 1);

namespace Civi\Api4\Action\SmsChat;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use BlackBrickSoftware\CiviCRMSmsChat\Service\Context;

/**
 * Header/composer state for one contact: identity, mobile numbers, consent,
 * lines, send capability and blockers.
 */
class GetContext extends AbstractAction {

  /**
   * @var int
   * @required
   */
  protected int $contactId;

  public function _run(Result $result): void {
    $result[] = Context::build($this->contactId, $this->getCheckPermissions());
  }

}
