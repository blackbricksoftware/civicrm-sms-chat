<?php
declare(strict_types = 1);

use BlackBrickSoftware\CiviCRMSmsChat\Service\Config;
use CRM_SmsChat_ExtensionUtil as E;

/**
 * Admin UI for sms_chat_* settings (Administer › System Settings › SMS Chat).
 *
 * All fields are backed by settings/sms_chat.setting.php. Fields whose env
 * var (CIVICRM_SMSCHAT_*) is set are frozen by
 * BlackBrickSoftware\CiviCRMSmsChat\Subscriber\SettingsFormSubscriber.
 */
class CRM_SmsChat_Form_Settings extends CRM_Core_Form {

  public function buildQuickForm(): void {
    \CRM_Utils_System::setTitle(E::ts('SMS Chat Settings'));

    $config = \Civi::service(Config::class);
    $this->assign('smschatEnvironment', $config->environment());
    $this->assign('smschatLockdownActive', $config->lockdownActive());

    $this->add('checkbox', 'sms_chat_environment_lockdown', E::ts('Lock down sending outside Production'));
    $this->add('text', 'sms_chat_allowed_recipients', E::ts('Allowed recipients'),
      ['size' => 60, 'placeholder' => '+13235551234, +1323']);
    $this->add('checkbox', 'sms_chat_test_mode', E::ts('Test mode (record, do not deliver)'));
    $this->add('checkbox', 'sms_chat_details_preamble', E::ts('Write From/To preamble into inbound SMS details'));

    $this->addButtons([
      ['type' => 'submit', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);

    $defaults = [];
    foreach ($this->getSettingNames() as $name) {
      $defaults[$name] = \Civi::settings()->get($name);
    }
    $this->setDefaults($defaults);

    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess(): void {
    $values = $this->exportValues();
    $config = \Civi::service(Config::class);
    $settings = \Civi::settings();
    $ignored = 0;

    foreach ($this->getSettingNames() as $name) {
      if ($config->isOverridden($name)) {
        $ignored++;
        continue;
      }
      $value = $values[$name] ?? NULL;
      if (in_array($name, ['sms_chat_environment_lockdown', 'sms_chat_test_mode', 'sms_chat_details_preamble'], TRUE)) {
        $value = !empty($value);
      }
      $settings->set($name, $value);
    }

    if ($ignored) {
      \CRM_Core_Session::setStatus(E::ts('%1 env-managed setting(s) were left unchanged.', [1 => $ignored]), E::ts('Note'), 'info');
    }
    \CRM_Core_Session::setStatus(E::ts('SMS Chat settings have been saved.'), E::ts('Saved'), 'success');
    parent::postProcess();
  }

  public function getRenderableElementNames(): array {
    $names = [];
    foreach ($this->_elements as $element) {
      if (!empty($element->getLabel())) {
        $names[] = $element->getName();
      }
    }
    return $names;
  }

  private function getSettingNames(): array {
    return [
      'sms_chat_environment_lockdown',
      'sms_chat_allowed_recipients',
      'sms_chat_test_mode',
      'sms_chat_details_preamble',
    ];
  }

}
