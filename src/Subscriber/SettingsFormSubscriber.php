<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Subscriber;

use BlackBrickSoftware\CiviCRMSmsChat\Service\Config;
use Civi\Core\Event\GenericHookEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * For each sms_chat_* field on the admin settings form: if the setting is
 * env-overridden (CIVICRM_SMSCHAT_* or $civicrm_setting), freeze the field
 * so admins can see the config is externally managed.
 */
class SettingsFormSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly Config $config) {}

  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_buildForm' => 'onBuildForm'];
  }

  public function onBuildForm(GenericHookEvent $event): void {
    if ($event->formName !== 'CRM_SmsChat_Form_Settings') {
      return;
    }
    $form = $event->form;
    $overridden = [];
    foreach ($form->_elements ?? [] as $element) {
      $name = (string) $element->getName();
      if (!str_starts_with($name, 'sms_chat_') || !$this->config->isOverridden($name)) {
        continue;
      }
      $overridden[] = $name;
      $element->freeze();
    }
    $form->assign('smschatOverridden', $overridden);
  }

}
