<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Carbon's now(), following a clock of the application's.
 *
 * The framework has no clock service: each part that reads the time takes
 * a closure for it, and a test hands every part the same one (TestClock).
 * Carbon reads the time on its own, through CarbonImmutable::now() and
 * Carbon::now(), so a model stamped, or a moment made, by Carbon would
 * keep the system's time while the rest of the application moved. This
 * points Carbon's test hook at the clock instead: each now() asks the
 * clock again, so a clock moved later moves Carbon with it, and the time
 * comes back in UTC.
 *
 *     CarbonClock::follow($clock->dates());   // Testing::use() does this
 *     CarbonClock::follow(null);              // the system's time again
 *
 * Carbon keeps the hook process-wide, so this is for a test, or a test
 * profile's moved clock; a production process never follows anything.
 */
final class CarbonClock
{
    private static bool $following = false;

    /** Whether the clock is being read, for a Carbon made while it is. */
    private static bool $reading = false;

    /**
     * A clock may make a Carbon itself -- the shop's makes one from its
     * seconds -- and Carbon asks its hook while it makes any, so the clock
     * would be asked again, without end. While it is being read, Carbon is
     * answered with the system's time, as though the hook were not there:
     * which a Carbon made from a timestamp or a written date never reads.
     *
     * @param (\Closure(): \DateTimeInterface)|null $now the clock; null for the system's time
     */
    public static function follow(?\Closure $now): void
    {
        self::$following = $now !== null;
        // Carbon hands the hook the system's time, which is the answer to a
        // Carbon made while the clock is read.
        CarbonImmutable::setTestNow($now === null ? null : static function (CarbonInterface $system) use ($now): CarbonInterface {
            if (self::$reading) {
                return $system;
            }
            self::$reading = true;
            try {
                return CarbonImmutable::instance($now())->setTimezone('UTC');
            } finally {
                self::$reading = false;
            }
        });
    }

    /** Whether Carbon follows a clock, rather than the system's time. */
    public static function following(): bool
    {
        return self::$following;
    }
}
