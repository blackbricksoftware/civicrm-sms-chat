<?php
declare(strict_types = 1);

namespace Civi\Api4;

/**
 * SmsChat — the chat UI's API surface. Not a DB entity: three purpose-built
 * actions over CiviCRM's native SMS activities.
 *
 * @searchable none
 * @since 6.10
 * @package Civi\Api4
 */
class SmsChat extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   * @return Action\SmsChat\GetContext
   */
  public static function getContext($checkPermissions = TRUE) {
    return (new Action\SmsChat\GetContext(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Action\SmsChat\GetMessages
   */
  public static function getMessages($checkPermissions = TRUE) {
    return (new Action\SmsChat\GetMessages(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Action\SmsChat\Send
   */
  public static function send($checkPermissions = TRUE) {
    return (new Action\SmsChat\Send(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, fn() => []))
      ->setCheckPermissions($checkPermissions);
  }

  public static function permissions(): array {
    return [
      'meta' => ['access CiviCRM'],
      'default' => ['access CiviCRM'],
      'send' => ['send SMS'],
    ];
  }

}
