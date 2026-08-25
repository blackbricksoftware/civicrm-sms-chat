<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Service;

use Civi\Api4\Activity;

/**
 * Sends one chat message and records it the way CiviCRM's own SMS send does.
 *
 * Why not CRM_Activity_BAO_Activity::sendSMSMessage()? It swallows provider
 * failures: the Twilio provider returns a PEAR_Error instead of throwing, so
 * core reports success on API rejects. A chat UI must surface real
 * failures, so this talks to the provider directly and replicates the three
 * things core does around it — recipient resolution, provider params
 * (contact_id / parent_activity_id / To), and the target ActivityContact
 * row — while checking the provider's return value.
 *
 * Records, mirroring core: one parent `SMS` activity (source = sender,
 * target = contact, subject naming the line), plus the per-recipient
 * `SMS delivery` activity the provider creates (which is what the thread
 * renders). Both get SMS_Chat line/peer attribution.
 *
 * Safety (before anything reaches a provider):
 *  - allowed-recipients list, when set, is always enforced;
 *  - outside Production with lockdown on, an empty list means deny-all;
 *  - test mode records the delivery activity itself and never calls the
 *    provider.
 */
class Sender {

  public function __construct(private readonly Config $config) {}

  /**
   * @return int the `SMS delivery` activity id representing the sent message
   * @throws \CRM_Core_Exception with a user-facing message on any refusal/failure
   */
  public function send(int $contactId, int $providerId, string $text, int $senderContactId): int {
    $text = trim($text);
    if ($text === '') {
      throw new \CRM_Core_Exception(ts('Message is empty.'));
    }
    if (mb_strlen($text) > Context::MAX_CHARS) {
      throw new \CRM_Core_Exception(ts('Message exceeds %1 characters.', [1 => Context::MAX_CHARS]));
    }

    $line = Lines::all()[$providerId] ?? NULL;
    if (!$line) {
      throw new \CRM_Core_Exception(ts('That SMS line is not available.'));
    }

    // Recipient: the contact's primary Mobile, resolved with the same rules as
    // core (Mobile type, numeric, not do_not_sms, not deceased). Never an
    // arbitrary "To" — that path would bypass consent.
    $context = Context::build($contactId, FALSE);
    if (!$context['canSms']) {
      $why = array_diff($context['blockers'], ['no_permission']); // permission is the API layer's job
      throw new \CRM_Core_Exception(ts('This contact cannot be texted (%1).', [1 => implode(', ', $why) ?: 'blocked']));
    }
    $to = '+' . ltrim((string) $context['phones'][0]['numeric'], '+');
    // phone_numeric is digits-only; US 10-digit numbers get the country code
    // core's providers assume, anything already carrying one is left alone.
    if (preg_match('/^\+\d{10}$/', $to)) {
      $to = '+1' . substr($to, 1);
    }

    $this->guard($to);

    $lineNumber = $line['numbers'][0] ?? NULL;
    $subject = ts('SMS Chat via %1', [1 => $line['title']]);

    $parentId = (int) Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'SMS')
      ->addValue('source_contact_id', $senderContactId)
      ->addValue('target_contact_id', [$contactId])
      ->addValue('activity_date_time', 'now')
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', $subject)
      ->addValue('details', $text)
      ->addValue('SMS_Chat.line_number', $lineNumber)
      ->addValue('SMS_Chat.peer_number', $to)
      ->execute()->first()['id'];

    $maxBefore = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_activity');

    if ($this->config->testMode()) {
      $deliveryId = $this->recordDelivery($contactId, $senderContactId, $text, 'TEST-' . strtoupper(bin2hex(random_bytes(6))));
    }
    else {
      try {
        $provider = \CRM_SMS_Provider::singleton(['provider_id' => $providerId]);
        $result = $provider->send($to, [
          'To' => $to,
          'contact_id' => $contactId,
          'parent_activity_id' => $parentId,
          'provider_id' => $providerId,
          'activity_subject' => $subject,
        ], $text, NULL, $senderContactId);
      }
      catch (\Throwable $e) {
        $result = $e;
      }
      if ($result instanceof \Throwable || is_a($result, 'PEAR_Error')) {
        $message = $result instanceof \Throwable ? $result->getMessage() : $result->getMessage();
        // No phantom "sent" record for a message the provider rejected.
        Activity::delete(FALSE)->addWhere('id', '=', $parentId)->execute();
        \Civi::log()->error('sms_chat: send failed for contact {cid} via provider {pid}: {err}', ['cid' => $contactId, 'pid' => $providerId, 'err' => $message]);
        throw new \CRM_Core_Exception(ts('The SMS provider rejected the message: %1', [1 => $message]));
      }
      // The provider (Twilio at least) creates the per-recipient `SMS delivery`
      // activity itself; providers that don't get one recorded here so the
      // thread still shows the message.
      $deliveryId = (int) (Activity::get(FALSE)
        ->addSelect('id')
        ->addWhere('activity_type_id:name', '=', 'SMS delivery')
        ->addWhere('target_contact_id', 'CONTAINS', $contactId)
        ->addWhere('id', '>', $maxBefore)
        ->addOrderBy('id', 'DESC')
        ->setLimit(1)
        ->execute()->first()['id'] ?? 0);
      if (!$deliveryId) {
        $deliveryId = $this->recordDelivery($contactId, $senderContactId, $text, NULL);
      }
    }

    Activity::update(FALSE)
      ->addWhere('id', '=', $deliveryId)
      ->addValue('SMS_Chat.line_number', $lineNumber)
      ->addValue('SMS_Chat.peer_number', $to)
      ->execute();

    return $deliveryId;
  }

  /**
   * Refuse before any provider contact when the recipient isn't allowed.
   */
  private function guard(string $to): void {
    $allowed = $this->config->allowedRecipients();
    $needle = Lines::normalize($to);

    if ($allowed === []) {
      if ($this->config->lockdownActive()) {
        throw new \CRM_Core_Exception(ts('Sending is locked down in the "%1" environment and no allowed recipients are configured (Administer › System Settings › SMS Chat).', [1 => $this->config->environment()]));
      }
      return;
    }
    foreach ($allowed as $entry) {
      if ($needle === $entry || str_starts_with($needle, $entry)) {
        return;
      }
    }
    throw new \CRM_Core_Exception(ts('%1 is not on the allowed-recipients list, so this message was not sent.', [1 => $to]));
  }

  private function recordDelivery(int $contactId, int $senderContactId, string $text, ?string $result): int {
    return (int) Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'SMS delivery')
      ->addValue('source_contact_id', $senderContactId)
      ->addValue('target_contact_id', [$contactId])
      ->addValue('activity_date_time', 'now')
      ->addValue('status_id:name', 'Completed')
      ->addValue('details', $text)
      ->addValue('result', $result)
      ->execute()->first()['id'];
  }

}
