<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Subscriber;

use Civi\Core\Event\GenericHookEvent;
use CRM_SmsChat_ExtensionUtil as E;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The "SMS Chat" tab on the contact summary page.
 *
 * The tab is ALWAYS shown for any contact the user can view — "this contact
 * has no valid mobile number" is a state the chat UI explains inside the tab
 * (with the specific blocker), never a reason to hide the tab. Send
 * capability is likewise an in-tab concern (`send SMS` permission + consent
 * checks, reported by SmsChat.getContext); no new permission model exists
 * here: if you can see the contact's activities you can read the thread, if
 * you hold `send SMS` you can send.
 *
 * Interop notes (the reasons this class looks the way it does):
 *  - `id` is REQUIRED: org.civicrm.contactlayout rebuilds the tab list via
 *    array_column($allTabs, NULL, 'id') and silently DROPS id-less entries.
 *  - ContactLayout's layout editor re-fires this hook out of contact context
 *    with contact_id = 0 and context.caller = 'ContactLayout' — the handler
 *    must register the tab cheaply and never do per-contact work here.
 *  - No 'class' on the entry: the tab body is a plain CRM.loadPage AJAX
 *    snippet (CRM_SmsChat_Page_Chat), not an ajaxForm/livePage.
 */
class ChatTabSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      'hook_civicrm_tabset' => 'addTab',
    ];
  }

  public function addTab(GenericHookEvent $event): void {
    if ($event->tabsetName !== 'civicrm/contact/view') {
      return;
    }

    $contactId = (int) ($event->context['contact_id'] ?? 0);

    $tabs = &$event->tabs;
    $tabs[] = [
      'id' => 'sms_chat',
      'title' => E::ts('SMS Chat'),
      'icon' => 'crm-i fa-comments',
      // Right after core's Activities tab (weight 60) — the chat is a view
      // over the same records.
      'weight' => 65,
      'url' => \CRM_Utils_System::url('civicrm/contact/view/sms_chat', "reset=1&cid={$contactId}"),
      // No 'contact_type': organizations text too.
      // No 'count' (yet): a per-contact unread/message count on every summary
      // load is not worth the query until read-tracking exists.
    ];
  }

}
