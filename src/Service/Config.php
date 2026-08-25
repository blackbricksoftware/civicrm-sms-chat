<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Service;

/**
 * Reads sms_chat_* settings and reports which are env-overridden.
 *
 * All reads go through Civi::settings(), so CiviCRM's own resolution order
 * applies: env var (`global_name`) → $civicrm_setting → DB → default.
 * isOverridden() lets the settings form freeze env-managed fields.
 */
class Config {

  public function get(string $key): mixed {
    return \Civi::settings()->get($key);
  }

  public function isOverridden(string $key): bool {
    return \Civi::settings()->getMandatory($key) !== NULL;
  }

  public function detailsPreamble(): bool {
    return $this->readBool('sms_chat_details_preamble');
  }

  public function environmentLockdown(): bool {
    return $this->readBool('sms_chat_environment_lockdown');
  }

  public function testMode(): bool {
    return $this->readBool('sms_chat_test_mode');
  }

  /** @return string[] normalized numbers/prefixes (+digits) */
  public function allowedRecipients(): array {
    $raw = (string) $this->get('sms_chat_allowed_recipients');
    $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter(array_map([Lines::class, 'normalize'], $parts), fn($n) => $n !== ''));
  }

  /** CiviCRM's own environment setting (Administer › System Settings › Misc). */
  public function environment(): string {
    return (string) (\CRM_Core_Config::environment() ?: 'Production');
  }

  /** Whether the non-production lockdown is in force right now. */
  public function lockdownActive(): bool {
    return $this->environmentLockdown() && $this->environment() !== 'Production';
  }

  /**
   * Boolean coercion tolerant of env-sourced strings.
   */
  private function readBool(string $key): bool {
    $v = $this->get($key);
    if (is_bool($v)) {
      return $v;
    }
    if (is_int($v)) {
      return $v !== 0;
    }
    if ($v === NULL || $v === '') {
      return FALSE;
    }
    return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], TRUE);
  }

}
