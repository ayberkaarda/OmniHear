<?php

namespace Tests\Support\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads the backend's own PHP sources so a test can assert about their text.
 *
 * The architecture tests under tests/Feature/Architecture are lint rules, not
 * behaviour: they never boot the application, they only look at what is
 * written in the files. This class is the one place that knows how to find
 * them, so the rules themselves stay pure (path + contents in, findings out)
 * and can therefore be exercised against handwritten strings as well as
 * against the real tree.
 */
final class SourceTree
{
    /**
     * The backend/ directory: tests/Support/Architecture -> tests/Support -> tests -> backend.
     */
    public static function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every .php file under the given repository-relative roots.
     *
     * Keys are POSIX-style relative paths ("app/Models/User.php") so a rule
     * can match on them identically on Windows and Linux, and so a failure
     * message names something a developer can open.
     *
     * @param  list<string>  $roots
     * @return array<string, string> relative path => file contents
     */
    public static function phpFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            $absolute = self::basePath().DIRECTORY_SEPARATOR.$root;

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::basePath()) + 1));

                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Split a file into 0-indexed lines, tolerating CRLF.
     *
     * @return list<string>
     */
    public static function lines(string $contents): array
    {
        return preg_split('/\r\n|\r|\n/', $contents) ?: [];
    }

    /**
     * True for a line that is nothing but a comment.
     *
     * `#[` is excluded on purpose: a PHP 8 attribute is code, and treating it
     * as a comment would let a justification comment "reach" across it.
     */
    public static function isCommentLine(string $line): bool
    {
        $trimmed = ltrim($line);

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '#')) {
            return ! str_starts_with($trimmed, '#[');
        }

        return str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '/*');
    }
}
