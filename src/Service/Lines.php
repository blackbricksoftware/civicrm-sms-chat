<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Service;

use Civi\Api4\SmsProvider;
use BlackBrickSoftware\CiviCRMSmsChat\Line\LineResolvers;

/**
 * The organisation's SMS "lines": one per active SmsProvider row. Display
 * name = the provider's title (installs already label their providers —
 * that IS the generic "name of the phone number"); numbers come from the
 * provider's LineResolver, when one exists.
 */
final class Lines {

  /** Per-request cache. */
  private static ?array $lines = NULL;

  /**
   * @return array<int, array{id:int, title:string, numbers:string[], isDefault:bool}>
   *   keyed by provider id, default lines first, then by title.
   */
  public static function all(): array {
    if (self::$lines !== NULL) {
      return self::$lines;
    }
    $providers = SmsProvider::get(FALSE)
      ->addSelect('id', 'name', 'title', 'api_params', 'is_default')
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('is_default', 'DESC')
      ->addOrderBy('title', 'ASC')
      ->execute();

    $lines = [];
    foreach ($providers as $provider) {
      $resolver = LineResolvers::for($provider);
      $numbers = $resolver ? $resolver->providerNumbers($provider) : [];
      $lines[(int) $provider['id']] = [
        'id' => (int) $provider['id'],
        'title' => (string) $provider['title'],
        'numbers' => array_map([self::class, 'normalize'], $numbers),
        'isDefault' => (bool) $provider['is_default'],
      ];
    }
    return self::$lines = $lines;
  }

  /** The line owning a given number, or NULL. */
  public static function byNumber(?string $number): ?array {
    if ($number === NULL || $number === '') {
      return NULL;
    }
    $needle = self::normalize($number);
    foreach (self::all() as $line) {
      if (in_array($needle, $line['numbers'], TRUE)) {
        return $line;
      }
    }
    return NULL;
  }

  /**
   * Canonical comparison form: digits with a leading '+'. Not a full E.164
   * normalizer (no country inference) — good enough to match a provider's
   * configured number against what a webhook/activity carries.
   */
  public static function normalize(string $number): string {
    $digits = preg_replace('/\D+/', '', $number) ?? '';
    return $digits === '' ? '' : '+' . $digits;
  }

}
