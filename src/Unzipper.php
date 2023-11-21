<?php # -*- coding: utf-8 -*-
/*
 * This file is part of the wp-downloader package.
 *
 * (c) Giuseppe Mazzapica
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WCM\WpDownloader;

use Composer\Config;
use Composer\Downloader\ZipDownloader;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Composer provides ZipDownloader class which extends ArchiveDownloader which in turn
 * extends FileDownloader.
 * So ZipDownloader is a "downloader", but we need just an "unzipper".
 *
 * This class exists because the `extract()` method of ZipDownloader, that is the only one we need,
 * is protected, so we need a subclass to access it.
 *
 * This class is final because four levels of inheritance are definitively enough.
 *
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @package wp-downloader
 * @license http://opensource.org/licenses/MIT MIT
 */
final class Unzipper extends ZipDownloader
{

    public function __construct(IOInterface $io, Config $config)
    {
        parent::__construct($io, $config);
    }

    /**
     * Unzip a given zip file to given target path.
     *
     * @param string $zipPath
     * @param string $target
     */
    public function unzip($zipPath, $target)
    {
        $this->checkLibrary($zipPath);
        parent::extract($zipPath, $target);
    }

    /**
     * This is just an unzipper, we don't download anything.
     *
     * @param PackageInterface $package
     * @param string $path
     * @param PackageInterface|null $prevPackage
     * @param bool $output
     * @return PromiseInterface
     */
    public function download(PackageInterface $package, string $path, ?PackageInterface $prevPackage = null, bool $output = true): PromiseInterface
    {
        return new Promise(fn() => null);
    }

    /**
     * Check that system unzip command or ZipArchive class is available.
     *
     * Parent class do this in `download()` method that we can't use because it needs a package
     * instance that we don't have and it runs an actual file download that we don't need.
     *
     * @param string $zipPath
     */
    private function checkLibrary($zipPath)
    {
        $hasSystemUnzip = (new ExecutableFinder())->find('unzip');

        if (!$hasSystemUnzip && !class_exists('ZipArchive')) {
            $name = basename($zipPath);
            throw new \RuntimeException(
                "Can't unzip '{$name}' because your system does not support unzip."
            );
        }
    }
}
