<?php
declare(strict_types = 1);

namespace Civi\Api4\Action\SmsChat;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use BlackBrickSoftware\CiviCRMSmsChat\Service\Conversation;

/**
 * The thread for one contact, oldest-first, as normalized message DTOs.
 * Pass sinceId (the newest id already displayed) to poll incrementally, or
 * beforeId (the oldest id already displayed) to page back through history.
 * The initial page returns the newest `limit` rows; a full page means there
 * may be older history.
 */
class GetMessages extends AbstractAction {

  /**
   * @var int
   * @required
   */
  protected int $contactId;

  /**
   * Poll cursor: only messages with id greater than this.
   * @var int|null
   */
  protected ?int $sinceId = NULL;

  /**
   * History cursor, with beforeAt: the id of the oldest message already
   * displayed (infinite scroll upward).
   * @var int|null
   */
  protected ?int $beforeId = NULL;

  /**
   * History cursor, with beforeId: the activity_date_time of the oldest
   * message already displayed. Both are required for a page back in time.
   * @var string|null
   */
  protected ?string $beforeAt = NULL;

  /**
   * Page size for the initial load and history pages.
   * @var int
   */
  protected int $limit = 50;

  /**
   * Restrict to one line (SmsProvider id). 0 = messages with no line
   * attribution; omit for all lines.
   * @var int|null
   */
  protected ?int $lineId = NULL;

  public function _run(Result $result): void {
    $before = ($this->beforeId && $this->beforeAt) ? ['id' => $this->beforeId, 'at' => $this->beforeAt] : NULL;
    $page = Conversation::messages($this->contactId, $this->sinceId, $before, max(1, min($this->limit, 500)), $this->getCheckPermissions(), $this->lineId);
    foreach ($page['messages'] as $message) {
      $result[] = $message;
    }
    $result->setCountMatched(count($page['messages']));
  }

}
