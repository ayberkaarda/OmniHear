<?php

namespace App\Support\OpenApi;

use ReflectionMethod;

/**
 * The source text of a method body.
 *
 * PHP keeps no AST at run time, so a generator that wants to know what a
 * controller returns has to read the file. Everything that depends on this is
 * therefore *static analysis of this application's own code* — accurate for the
 * shapes this codebase actually writes, and deliberately conservative: when a
 * scan finds nothing it says so in the document rather than inventing a shape.
 */
final class MethodSource
{
    public static function of(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return null;
        }

        // Cast rather than checked: reflection has just told us the file
        // exists, and a second unreachable branch would only be dead weight.
        $lines = (array) file($file, FILE_IGNORE_NEW_LINES);

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }
}
