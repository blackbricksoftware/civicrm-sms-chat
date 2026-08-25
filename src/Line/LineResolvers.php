<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSmsChat\Line;

use Civi\Core\Event\GenericHookEvent;

/**
 * Registry of LineResolverInterface implementations.
 *
 * Built-in: Twilio. Extension point: listen to the `smschat.resolvers`
 * event and append to $event->resolvers — e.g.
 *
 *   Civi::dispatcher()->addListener('smschat.resolvers', function ($e) {
 *     $e->resolvers[] = new MyProviderLineResolver();
 *   });
 */
final class LineResolvers {

  public const EVENT = 'smschat.resolvers';

  /** @var LineResolverInterface[]|null */
  private static ?array $resolvers = NULL;

  /** @return LineResolverInterface[] */
  public static function all(): array {
    if (self::$resolvers === NULL) {
      $resolvers = [new TwilioLineResolver()];
      $event = GenericHookEvent::create(['resolvers' => &$resolvers]);
      \Civi::dispatcher()->dispatch(self::EVENT, $event);
      self::$resolvers = array_values(array_filter($resolvers, fn($r) => $r instanceof LineResolverInterface));
    }
    return self::$resolvers;
  }

  public static function for(array $provider): ?LineResolverInterface {
    foreach (self::all() as $resolver) {
      if ($resolver->supports($provider)) {
        return $resolver;
      }
    }
    return NULL;
  }

}
