<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Service;

use Civi\Api4\Activity;
use Civi\Api4\Contact;

/**
 * The per-contact SMS thread as normalized message DTOs.
 *
 * Renders `Inbound SMS` + `SMS delivery` activities targeting the contact —
 * NOT the parent `SMS` activity, which is one record per send JOB with N
 * targets (a mass blast is one activity with hundreds of targets). The
 * delivery activity is the per-recipient truth: rendered text, per-message
 * timestamp, provider message id, exactly one target. Direction comes from
 * the activity TYPE (core's inbound activities are self-referential —
 * source == target == sender — so source/target can't tell you).
 *
 * Reads go through API4 WITH permission checks, so activity ACLs apply to
 * the chat exactly as they do to the activity list.
 */
final class Conversation {

  public const TYPES = ['Inbound SMS', 'SMS delivery'];

  /**
   * @param int $contactId
   * @param int|null $sinceId only messages with id > sinceId (poll cursor, oldest-first, uncapped)
   * @param array{at: string, id: int}|null $before history cursor — the oldest
   *   message the client holds; returns the newest $limit strictly before it
   * @param int $limit page size for the initial/history pages
   * @param bool $checkPermissions
   * @param int|null $lineId restrict to one line (SmsProvider id); 0 = messages
   *   with no line attribution at all; NULL = every line
   * @return array{messages: array, hasMore: bool}
   */
  public static function messages(int $contactId, ?int $sinceId, ?array $before, int $limit, bool $checkPermissions, ?int $lineId = NULL): array {
    $query = Activity::get($checkPermissions)
      ->addSelect('id', 'activity_type_id:name', 'details', 'activity_date_time', 'result', 'source_contact_id', 'target_contact_id', 'phone_number')
      ->addWhere('activity_type_id:name', 'IN', self::TYPES)
      ->addWhere('target_contact_id', 'CONTAINS', $contactId)
      ->addWhere('is_deleted', '=', FALSE);

    if (self::customFieldsAvailable()) {
      $query->addSelect('SMS_Chat.line_number', 'SMS_Chat.peer_number');
    }

    // Line filter is applied SERVER-SIDE so a filtered view pages exactly like
    // the full thread (filtering client-side would leave near-empty pages and
    // walk the whole history to fill a screen). Attribution sources: the
    // SMS_Chat custom field, plus — for inbound tagged only by a details
    // preamble ("To: +1…", the format this extension itself writes) — a LIKE
    // on details.
    if ($lineId !== NULL && self::customFieldsAvailable()) {
      if ($lineId === 0) {
        $query->addWhere('SMS_Chat.line_number', 'IS EMPTY');
        $query->addWhere('details', 'NOT LIKE', '%To: +%');
      }
      else {
        $numbers = Lines::all()[$lineId]['numbers'] ?? [];
        if (!$numbers) {
          return ['messages' => [], 'hasMore' => FALSE];
        }
        $clauses = [['SMS_Chat.line_number', 'IN', $numbers]];
        foreach ($numbers as $number) {
          $clauses[] = ['details', 'LIKE', '%To: ' . $number . '%'];
        }
        $query->addClause('OR', ...$clauses);
      }
    }

    if ($sinceId) {
      // Incremental: everything newer than the cursor, oldest first.
      $rows = (array) $query->addWhere('id', '>', $sinceId)
        ->addOrderBy('activity_date_time', 'ASC')->addOrderBy('id', 'ASC')
        ->execute();
      $hasMore = FALSE;
    }
    else {
      // Initial page (or a history page when $before is set): the newest
      // $limit, then flipped to chronological. The cursor is the (date, id)
      // tuple of the oldest message the client holds — NOT the id alone:
      // ids are not monotonic with activity_date_time (imports, backdated
      // sends, edited dates), so an id-only cursor skips history.
      if ($before && !empty($before['at']) && !empty($before['id'])) {
        $query->addClause('OR',
          ['activity_date_time', '<', $before['at']],
          ['AND', [['activity_date_time', '=', $before['at']], ['id', '<', (int) $before['id']]]]
        );
      }
      $rows = (array) $query->setLimit($limit + 1)
        ->addOrderBy('activity_date_time', 'DESC')->addOrderBy('id', 'DESC')
        ->execute();
      $hasMore = count($rows) > $limit;
      $rows = array_reverse(array_slice($rows, 0, $limit));
    }

    $names = self::displayNames(array_unique(array_filter(array_map(
      fn($r) => $r['source_contact_id'] ?? NULL, $rows
    ))), $contactId);

    $messages = [];
    foreach ($rows as $row) {
      $messages[] = self::toDto($row, $contactId, $names);
    }
    return ['messages' => $messages, 'hasMore' => $hasMore];
  }

  private static function toDto(array $row, int $contactId, array $names): array {
    $inbound = ($row['activity_type_id:name'] === 'Inbound SMS');
    [$body, $preambleTo, $preambleFrom] = self::parseDetails((string) ($row['details'] ?? ''));

    $line = $row['SMS_Chat.line_number'] ?? NULL;
    $peer = $row['SMS_Chat.peer_number'] ?? NULL;
    if ($inbound) {
      // Historical inbound may carry an install-specific From/To preamble
      // instead of structured fields — read it defensively, never assume it.
      // Core also stores the sender on every Inbound SMS activity
      // (`phone_number`, written by processInbound), which is what makes
      // "which of the contact's numbers sent this" answerable for all history.
      $line = $line ?: $preambleTo;
      $peer = $peer ?: $preambleFrom ?: ($row['phone_number'] ?? NULL);
    }
    $lineInfo = Lines::byNumber($line);

    $senderId = $inbound ? $contactId : (int) ($row['source_contact_id'] ?? 0);

    return [
      'id' => (int) $row['id'],
      'direction' => $inbound ? 'in' : 'out',
      'body' => $body,
      'line' => $line ? Lines::normalize($line) : NULL,
      'lineId' => $lineInfo['id'] ?? NULL,
      'lineTitle' => $lineInfo['title'] ?? NULL,
      'peer' => $peer ? Lines::normalize($peer) : NULL,
      'at' => $row['activity_date_time'],
      'sid' => $row['result'] ?: NULL,
      'senderContactId' => $senderId ?: NULL,
      'senderName' => $names[$senderId] ?? NULL,
      'kind' => self::isTapback($body) ? 'tapback' : 'message',
    ];
  }

  /**
   * Activity `details` -> [plain body, preamble To, preamble From].
   *
   * Strips a leading "From: … To: …" block (optionally followed by <hr>),
   * as written by this extension's preamble option or by install-specific
   * inbound decoration, then flattens the remaining HTML to text.
   */
  public static function parseDetails(string $details): array {
    $to = $from = NULL;
    $text = $details;
    // Greedy captures on purpose: they are bounded by '<' / newline, and a
    // lazy match would stop at the first character since the tail is optional.
    if (preg_match('~^\s*(?:<p>)?\s*From:\s*([^<\n]+)\s*(?:<br\s*/?>|\n)+\s*To:\s*([^<\n]+)\s*(?:</p>)?\s*(?:<hr\s*/?>)?\s*(.*)$~su', $details, $m)) {
      $from = trim($m[1]);
      $to = trim($m[2]);
      $text = $m[3];
    }
    $text = preg_replace('~<br\s*/?>|</p>~i', "\n", $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // iOS wraps tapback reactions in zero-width/hair spaces; drop them.
    $text = preg_replace('/[\x{200A}-\x{200D}\x{FEFF}\x{2060}]/u', '', $text) ?? $text;
    return [trim($text), $to, $from];
  }

  /**
   * iOS "tapback" reactions arrive as ordinary SMS text: an emoji plus
   * "to «original»" (localized: "a «…»" in Spanish), or "Removed 👍 from
   * «…»", or the older word forms ("Liked “…”"). Cosmetic classification.
   */
  public static function isTapback(string $body): bool {
    $s = preg_replace('/[\x{200A}-\x{200D}\x{FEFF}\x{2060}]/u', '', $body) ?? $body;
    $s = trim($s);
    return (bool) (
      preg_match('/^(Removed\s+)?\X{1,3}\s+(to|from|a|de|para)\s+[“"«]/u', $s)
      || preg_match('/^(Liked|Loved|Laughed at|Emphasized|Disliked|Questioned)\s+[“"«]/u', $s)
    );
  }

  private static function displayNames(array $contactIds, int $contactId): array {
    $ids = array_values(array_unique(array_merge($contactIds, [$contactId])));
    $names = [];
    // FALSE: names of the staff who sent messages must show even when the
    // viewer can't otherwise see those contacts.
    foreach (Contact::get(FALSE)->addSelect('id', 'display_name')->addWhere('id', 'IN', $ids)->execute() as $c) {
      $names[(int) $c['id']] = $c['display_name'];
    }
    return $names;
  }

  private static function customFieldsAvailable(): bool {
    static $available = NULL;
    if ($available === NULL) {
      $available = (bool) \CRM_Core_BAO_CustomField::getCustomFieldID('line_number', 'SMS_Chat');
    }
    return $available;
  }

}
