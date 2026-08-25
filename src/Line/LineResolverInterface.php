<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Line;

/**
 * Provider-specific knowledge, isolated. A "line" is an active SmsProvider
 * row; what THIS extension can't know generically is (a) which phone
 * number(s) a provider row sends from, and (b) where an inbound webhook
 * request carries its To/From. Each SMS provider gets a resolver.
 *
 * Third-party providers register theirs on the `smschat.resolvers` event
 * (see LineResolvers). Unresolvable providers degrade gracefully: the line
 * is identified by its title only and inbound stays untagged.
 */
interface LineResolverInterface {

  /**
   * Whether this resolver handles the given provider row.
   *
   * @param array $provider SmsProvider row (id, name, title, api_params, ...)
   */
  public function supports(array $provider): bool;

  /**
   * Phone numbers the provider sends from, E.164 where possible.
   *
   * @return string[]
   */
  public function providerNumbers(array $provider): array;

  /**
   * To/From of the inbound message currently being processed by
   * CRM_SMS_Provider::processInbound (i.e. from the webhook request), or
   * NULL when this resolver can't tell.
   *
   * @return array{to: ?string, from: ?string}|null
   */
  public function inboundNumbers(\CRM_SMS_Provider $provider, \CRM_SMS_Message $message): ?array;

}
