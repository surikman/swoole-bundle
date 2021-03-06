<?php
declare(strict_types=1);
/*
 * @author     mfris
 * @copyright  PIXELFEDERATION s.r.o.
 * @license    Internal use only
 */

namespace K911\Swoole\Server\Runtime\HMR;

use UnexpectedValueException;

/**
 *
 */
final class HmrComposerLoader
{
    /**
     * @var LoadedFiles
     */
    private $loadedFiles;

    /**
     * @var mixed
     */
    private $decorated;

    /**
     * @var string
     */
    private $decoratedMethod;

    /**
     * @var bool
     */
    private $isFinder;

    /**
     * @param LoadedFiles $loadedFiles
     * @param mixed       $decorated
     * @param string      $decoratedMethod
     */
    public function __construct(LoadedFiles $loadedFiles, $decorated, string $decoratedMethod)
    {
        $this->loadedFiles = $loadedFiles;
        $this->decorated = $decorated;
        $this->decoratedMethod = $decoratedMethod;
        $this->isFinder = method_exists($decorated, 'findFile');
    }

    /**
     * Autoload a class by it's name
     *
     * @param string $class Name of the class to load
     */
    public function loadClass($class)
    {
        $this->decorated->{$this->decoratedMethod}($class);
    }

    /**
     * Finds either the path to the file where the class is defined,
     * or gets the appropriate php://filter stream for the given class.
     *
     * @param string $class
     * @return string|false The path/resource if found, false otherwise.
     */
    public function findFile($class)
    {
        if (!$this->isFinder) {
            return null;
        }

        $file = $this->decorated->findFile($class);

        if (is_string($file)) {
            $correctFile = $this->getCorrectFile($file);
            $this->loadedFiles->addFile($correctFile);
        }

        return $file;
    }

    /**
     * @param string $file
     *
     * @return string
     */
    private function getCorrectFile(string $file): string
    {
        if (strpos($file, 'php://') === 0) {
            if (!preg_match('/\/resource=(.*\.php)/', $file, $matches)) {
                throw new UnexpectedValueException(sprintf('Unable to parse file from string: %s', $file));
            }

            if (!isset($matches[1])) {
                throw new UnexpectedValueException(
                    sprintf('Unable to match file data from string: %s, got %s', $file, print_r($matches, true))
                );
            }

            return $matches[1];
        }

        return $file;
    }
}
