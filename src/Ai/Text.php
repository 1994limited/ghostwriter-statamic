<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

/**
 * Text that has been through a model, a photo library or a pasted brief is
 * not always valid UTF-8 all the way through, and one bad byte makes
 * json_encode give up on a whole session. Anything written as JSON goes
 * through here first.
 */
class Text
{
    /**
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::scrub($item);
            }
        }

        return $value;
    }
}
