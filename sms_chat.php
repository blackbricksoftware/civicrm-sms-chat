<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'sms_chat.civix.php';
// phpcs:enable

use CRM_SmsChat_ExtensionUtil as E;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Implements hook_civicrm_container().
 *
 * Each feature is a single EventSubscriber class under
 * BlackBrickSoftware\CiviCRMSmsChat\Subscriber\. Toggle a feature by
 * commenting its addSubscriber line (then `cv flush`).
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container/
 */
function sms_chat_civicrm_container(ContainerBuilder $container): void {
  $container->autowire(\BlackBrickSoftware\CiviCRMSmsChat\Service\Config::class)->setPublic(TRUE);
  $container->autowire(\BlackBrickSoftware\CiviCRMSmsChat\Service\Sender::class)->setPublic(TRUE);

  $config = new Reference(\BlackBrickSoftware\CiviCRMSmsChat\Service\Config::class);
  $container->findDefinition('dispatcher')
    // Comment any line to disable the feature.
    ->addMethodCall('addSubscriber', [new Definition(\BlackBrickSoftware\CiviCRMSmsChat\Subscriber\ChatTabSubscriber::class)])                       // SMS Chat tab on contact records
    ->addMethodCall('addSubscriber', [new Definition(\BlackBrickSoftware\CiviCRMSmsChat\Subscriber\InboundLineTaggerSubscriber::class, [$config])])  // inbound SMS: line attribution (+ optional details preamble)
    ->addMethodCall('addSubscriber', [new Definition(\BlackBrickSoftware\CiviCRMSmsChat\Subscriber\SettingsFormSubscriber::class, [$config])])       // freeze env-managed fields on the settings form
    ->addMethodCall('addSubscriber', [new Definition(\BlackBrickSoftware\CiviCRMSmsChat\Subscriber\NavigationMenuSubscriber::class)])                // Administer › System Settings › SMS Chat
  ;
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function sms_chat_civicrm_config(\CRM_Core_Config $config): void {
  _sms_chat_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function sms_chat_civicrm_install(): void {
  _sms_chat_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function sms_chat_civicrm_enable(): void {
  _sms_chat_civix_civicrm_enable();
}
