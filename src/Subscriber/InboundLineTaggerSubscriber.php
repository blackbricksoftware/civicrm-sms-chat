<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Subscriber;

use Civi\Api4\Activity;
use Civi\Core\Event\GenericHookEvent;
use BlackBrickSoftware\CiviCRMSmsChat\Line\LineResolvers;
use BlackBrickSoftware\CiviCRMSmsChat\Service\Config;
use BlackBrickSoftware\CiviCRMSmsChat\Service\CustomData;
use BlackBrickSoftware\CiviCRMSmsChat\Service\Lines;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Structured line attribution for inbound SMS (DESIGN.md §4a).
 *
 * Two hooks, one webhook request:
 *  1. hook_civicrm_inboundSMS fires BEFORE the activity exists (no id), with
 *     the CRM_SMS_Message in hand. Recover To/From through the provider's
 *     LineResolver and stash them. Optionally (setting
 *     sms_chat_details_preamble) prepend a human-readable "From/To" preamble
 *     to the body, which processInbound() stores as activity details.
 *  2. hook_civicrm_post on the resulting Inbound SMS activity writes the
 *     stashed numbers into the SMS_Chat custom fields.
 *
 * Silent no-op outside a real inbound webhook (no provider resolvable, no
 * resolver for the provider, nothing stashed).
 */
class InboundLineTaggerSubscriber implements EventSubscriberInterface {

  /** @var array{to: ?string, from: ?string}|null stashed between the two hooks */
  private static ?array $pending = NULL;

  public function __construct(private readonly Config $config) {}

  public static function getSubscribedEvents(): array {
    return [
      'hook_civicrm_inboundSMS' => ['onInboundSms', 10],
      'hook_civicrm_post' => 'onPost',
    ];
  }

  public function onInboundSms(GenericHookEvent $event): void {
    /** @var \CRM_SMS_Message $message */
    $message = $event->message;
    self::$pending = NULL;

    // Resolve the provider exactly as core's callback page does
    // (CRM_SMS_Page_Callback): from the request, which may carry
    // provider_id=N, provider=<extension key>, or mailing_id=N — webhooks are
    // registered in all three shapes in the wild. Each resolver then decides
    // by the provider OBJECT whether it can read To/From for it.
    try {
      $providerObj = \CRM_SMS_Provider::singleton($_REQUEST);
    }
    catch (\Throwable $e) {
      \Civi::log()->warning('sms_chat: could not resolve inbound provider from request: ' . $e->getMessage());
      return;
    }
    $numbers = NULL;
    foreach (LineResolvers::all() as $resolver) {
      $numbers = $resolver->inboundNumbers($providerObj, $message);
      if ($numbers) {
        break;
      }
    }
    if (!$numbers) {
      return;
    }
    self::$pending = ['to' => $numbers['to'] ?? NULL, 'from' => $numbers['from'] ?? NULL];

    if ($this->config->detailsPreamble()) {
      $from = htmlspecialchars((string) (self::$pending['from'] ?? ''), ENT_QUOTES, 'UTF-8');
      $to = htmlspecialchars((string) (self::$pending['to'] ?? ''), ENT_QUOTES, 'UTF-8');
      // Same shape Conversation::parseDetails() strips back out.
      $message->body = "<p>From: {$from}<br />To: {$to}</p><hr /><p>" . $message->body . '</p>';
    }
  }

  public function onPost(GenericHookEvent $event): void {
    if (self::$pending === NULL || $event->action !== 'create' || $event->entity !== 'Activity') {
      return;
    }
    $inboundTypeId = \CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Inbound SMS');
    if ((int) ($event->object->activity_type_id ?? 0) !== (int) $inboundTypeId) {
      return;
    }
    $pending = self::$pending;
    self::$pending = NULL; // before the update: it re-fires hook_civicrm_post

    if (!CustomData::available()) {
      \Civi::log()->warning('sms_chat: SMS_Chat custom fields are not active; inbound activity ' . $event->id . ' left untagged');
      return;
    }
    try {
      Activity::update(FALSE)
        ->addWhere('id', '=', $event->id)
        ->addValue('SMS_Chat.line_number', $pending['to'] ? Lines::normalize($pending['to']) : NULL)
        ->addValue('SMS_Chat.peer_number', $pending['from'] ? Lines::normalize($pending['from']) : NULL)
        ->execute();
    }
    catch (\Throwable $e) {
      \Civi::log()->warning('sms_chat: could not tag inbound activity ' . $event->id . ': ' . $e->getMessage());
    }
  }

}
