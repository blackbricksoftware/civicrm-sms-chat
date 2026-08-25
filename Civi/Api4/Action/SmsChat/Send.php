<?php
declare(strict_types = 1);

namespace Civi\Api4\Action\SmsChat;

use BlackBrickSoftware\CiviCRMSmsChat\Service\Conversation;
use BlackBrickSoftware\CiviCRMSmsChat\Service\Sender;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Send one text message to a contact from one of the organisation's lines.
 * Requires the `send SMS` permission (see SmsChat::permissions()). Returns
 * the sent message as a thread DTO so the UI can reconcile its optimistic
 * bubble.
 */
class Send extends AbstractAction {

  /**
   * @var int
   * @required
   */
  protected int $contactId;

  /**
   * SmsProvider id of the line to send from.
   * @var int
   * @required
   */
  protected int $providerId;

  /**
   * @var string
   * @required
   */
  protected string $text;

  public function _run(Result $result): void {
    $me = (int) \CRM_Core_Session::getLoggedInContactID();
    if (!$me) {
      throw new \CRM_Core_Exception('No logged-in contact to send as.');
    }
    $deliveryId = \Civi::service(Sender::class)->send($this->contactId, $this->providerId, $this->text, $me);
    $page = Conversation::messages($this->contactId, $deliveryId - 1, NULL, 1, FALSE);
    foreach ($page['messages'] as $m) {
      if ($m['id'] === $deliveryId) {
        $result[] = $m;
      }
    }
  }

}
