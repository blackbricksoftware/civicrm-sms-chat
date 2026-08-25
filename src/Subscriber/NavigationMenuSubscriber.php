<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Subscriber;

use Civi\Core\Event\GenericHookEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds "SMS Chat" under Administer → System Settings (next to SMS
 * Providers). Comment out the addSubscriber line in smschat.php to hide it.
 */
class NavigationMenuSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_navigationMenu' => 'onNavigationMenu'];
  }

  public function onNavigationMenu(GenericHookEvent $event): void {
    // The hook arg is named "params" (CRM_Utils_Hook::navigationMenu()), not "menu".
    $menu = &$event->params;
    _smschat_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
      'label' => ts('SMS Chat', ['domain' => 'smschat']),
      'name' => 'smschat_settings',
      'url' => 'civicrm/admin/smschat',
      'permission' => 'administer CiviCRM',
      'operator' => 'OR',
      'separator' => 0,
    ]);
    _smschat_civix_navigationMenu($menu);
  }

}
