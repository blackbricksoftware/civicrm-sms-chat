<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Line;

/**
 * org.civicrm.sms.twilio.
 *
 * - Sends from the `From` key of the provider's api_params (newline-separated
 *   `key = value` text, parsed by CRM_SMS_BAO_SmsProvider::getProviderInfo).
 *   A `|`-separated value is a pool the provider picks from at random.
 * - Its inbound() passes $to = NULL to processInbound, so CRM_SMS_Message->to
 *   is empty by the time hook_civicrm_inboundSMS fires; the webhook POST does
 *   carry To/From, readable through the provider's own retrieve().
 */
class TwilioLineResolver implements LineResolverInterface {

  public const PROVIDER_NAME = 'org.civicrm.sms.twilio';

  public function supports(array $provider): bool {
    return ($provider['name'] ?? '') === self::PROVIDER_NAME;
  }

  public function providerNumbers(array $provider): array {
    // getProviderInfo() returns the row with api_params parsed into an array.
    $params = \CRM_SMS_BAO_SmsProvider::getProviderInfo((int) $provider['id']);
    $from = $params['api_params']['From'] ?? '';
    if (!is_string($from) || trim($from) === '') {
      return [];
    }
    return array_values(array_filter(array_map('trim', explode('|', $from)), fn($n) => $n !== ''));
  }

  public function inboundNumbers(\CRM_SMS_Provider $provider, \CRM_SMS_Message $message): ?array {
    if (!$provider instanceof \org_civicrm_sms_twilio) {
      return NULL;
    }
    // retrieve() exits the request on a missing REQUIRED param; pass FALSE so
    // an absent key is a NULL, never a hard failure inside a webhook.
    return [
      'to' => $message->to ?: ($provider->retrieve('To', 'String', FALSE) ?: NULL),
      'from' => $message->from ?: ($provider->retrieve('From', 'String', FALSE) ?: NULL),
    ];
  }

}
