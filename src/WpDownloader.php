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

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Plugin\PluginInterface;
use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;

/**
 * This plugins provides a one-step, zero effort way to solve a very specific issue in a very specific
 * edge case.
 *
 * The issue is that by using Composer to manage both WordPress core and plugins and themes and at
 * same time to tell Composer to put  plugins and themes inside WordPress default `/wp-content` folder
 * is just not possible without issues.
 * Because, in short, every time WordPress is installed or updated, the whole WordPress folder
 * (so including `/wp-content` dir) is deleted the and so plugins and themes packages inside it are lost.
 *
 * This plugins allows to install themes and plugin inside WordPress `/wp-content` folder by
 * not treating WordPress as a Composer package, but downloading it as zip from wp.org releases.
 *
 * This plugin also prevent that Composer packages of type `worpdress-core` are installed into the
 * system, to avoid issues and unnecessary downloads.
 *
 * See README for more information.
 *
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @package wp-downloader
 * @license http://opensource.org/licenses/MIT MIT
 */
class WpDownloader implements PluginInterface, EventSubscriberInterface
{

    const RELEASES_URL = 'https://api.wordpress.org/core/version-check/1.7/';
    const DOWNLOADS_BASE_URL = 'https://downloads.wordpress.org/release/wordpress-';

    /**
     * @var array
     */
    private static $done = [];

    /**
     * @var \Composer\IO\IOInterface
     */
    private $io;

    /**
     * @var \Composer\Composer
     */
    private $composer;

    /**
     * @var \Composer\Util\RemoteFilesystem
     */
    private $remoteFilesystem;

    /**
     * @var \Composer\Util\Filesystem
     */
    private $filesystem;

    /**
     * @var bool
     */
    private $isUpdate = false;

    /**
     * @var array
     */
    private $config = [];

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return [
            'pre-package-install' => 'prePackage',
            'pre-package-update'  => 'prePackage',
            'pre-install-cmd'     => 'install',
            'pre-update-cmd'      => 'update',
        ];
    }

    /**
     * @inheritdoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->filesystem = new Filesystem();
        $this->remoteFilesystem = new RemoteFilesystem($io, $composer->getConfig());
        $this->io = $io;

        $extra = (array)$composer->getPackage()->getExtra();
        $dirs = empty($extra['wordpress-install-dir']) ? [] : $extra['wordpress-install-dir'];
        is_string($dirs) and $dirs = [$dirs];
        is_array($dirs) and $dirs = array_filter(array_filter($dirs, 'is_string'));
        $default = [
            'version'    => '',
            'no-content' => true,
            'target-dir' => (is_array($dirs) && $dirs) ? reset($dirs) : 'wordpress',
        ];
        $config = array_key_exists('wp-downloader', $extra) ? $extra['wp-downloader'] : [];
        $config = array_merge($default, $config);
        $this->config = $config;
    }

    /**
     * This is triggered before _each_ package is installed.
     *
     * When the package is a Composer plugin, it does nothing. Since plugins are always all placed
     * on top of the dependencies stack doing this we ensure that the login in this method runs
     * when all the plugins are installed activated and very likely all the custom installers
     * are installed.
     * This is because we want to remove all the installers capable to install WordPress core
     * and replace them with our "CoreInstaller" that actually do nothing, avoiding that core
     * packages are installed at all: we don't need them since we are already downloading WP
     * from wp.org repo.
     *
     * Besides of this, during the very first installation, `install()` method is not triggered,
     * because Composer can't know about it _before_ this package is actually installed.
     * This is why in that case, and only in that case, we use this method to also trigger
     * installation.
     *
     * @param PackageEvent $event
     *
     * @see NoopCoreInstaller
     */
    public function prePackage(PackageEvent $event)
    {
        if (in_array(__FUNCTION__, self::$done, true)) {
            return;
        }

        $operation = $event->getOperation();
        $package = null;
        $operation instanceof UpdateOperation and $package = $operation->getTargetPackage();
        $operation instanceof InstallOperation and $package = $operation->getPackage();

        if (!$package || $package->getType() === 'composer-plugin') {
            return;
        }

        $manager = $event->getComposer()->getInstallationManager();
        $fakeInstaller = new NoopCoreInstaller($event->getIO());
        $manager->addInstaller($fakeInstaller);

        self::$done[] = __FUNCTION__;

        if (!in_array('install', self::$done, true)) {
            /** @var callable $method */
            $method = [$this, $operation->getJobType()];
            $method();
        }
    }

    /**
     * Setup `$isUpdate` flag to true, then just run `WpDownloader::install()`
     *
     * @see WpDownloader::install()
     */
    public function update()
    {
        $this->isUpdate = true;
        $this->install();
    }

    /**
     * Hooked on installer event, it setups configs, installed and target version
     * then launches the download of WP when needed.
     */
    public function install()
    {
        if (in_array(__FUNCTION__, self::$done, true)) {
            return;
        }

        self::$done[] = __FUNCTION__;

        $targetVersion = $this->discoverTargetVersion();
        $installedVersion = $this->discoverInstalledVersion();

        if (!$this->shouldInstall($targetVersion, $installedVersion)) {
            $this->write([
                "\n<info>No need to download WordPress:</info>",
                "<info>installed version matches required version.</info>\n",
            ]);

            return;
        }

        $version = $this->resolveTargetVersion($targetVersion);

        $info = $version;
        $this->config['no-content'] and $info .= ' - No Content';

        $this->write("Installing <info>WordPress</info> (<comment>{$info}</comment>)", true);

        list($target, $targetTemp, $zipUrl, $zipFile) = $this->preparePaths($version);

        if (!$this->remoteFilesystem->copy('wordpress.org', $zipUrl, $zipFile)) {
            throw new \RuntimeException(
                sprintf(
                    'Error downloading WordPress %s from %s',
                    $version,
                    $zipUrl
                )
            );
        }

        if (!is_file($zipFile)) {
            throw new \RuntimeException(
                sprintf('Error downloading WordPress %s from %s', $version, $zipUrl)
            );
        }

        $this->filesystem->ensureDirectoryExists($target);

        $unzipper = new Unzipper($this->io, $this->composer->getConfig());

        $this->write("    Unzipping...");
        $unzipper->unzip($zipFile, $targetTemp);
        $this->filesystem->unlink($zipFile);
        $this->write("    Moving to destination folder...");
        $this->filesystem->copyThenRemove("{$targetTemp}/wordpress", $target);
        $this->filesystem->removeDirectory($targetTemp);

        $this->write("\n<info>WordPress {$version} installed.</info>\n", true);
    }

    /**
     * Build the paths to install WP package.
     * Cleanup existent paths.
     *
     * @param string $version
     * @return array
     */
    private function preparePaths($version)
    {
        $cwd = rtrim(getcwd(), '\\/');
        $fs = $this->filesystem;

        $targetDir = ltrim($this->config['target-dir'], '\\/');
        $target = $fs->normalizePath("{$cwd}/{$targetDir}");

        $parent = dirname($this->config['target-dir']);
        $targetTempSubdir = $parent === '.'
            ? "/.{$this->config['target-dir']}"
            : "{$parent}/." . basename($this->config['target-dir']);

        $targetTemp = $fs->normalizePath("{$cwd}/{$targetTempSubdir}");

        $zipUrl = self::DOWNLOADS_BASE_URL . "{$version}";
        $zipUrl .= $this->config['no-content'] ? '-no-content.zip' : '.zip';

        $zipFile = $cwd . '/' . basename(parse_url($zipUrl, PHP_URL_PATH));
        $zipFile = $fs->normalizePath($zipFile);

        $this->write("Cleaning previous WordPress in files...");

        // Delete leftover zip file if found
        file_exists($zipFile) and $fs->unlink($zipFile);

        // Delete leftover unzip temp folder if found
        is_dir($targetTemp) and $fs->removeDirectory($targetTemp);

        // Delete WordPress wp-includes folder if found
        is_dir("{$target}/wp-includes") and $fs->removeDirectory("{$target}/wp-includes");

        // Delete WordPress wp-admin folder if found
        is_dir("{$target}/wp-admin") and $fs->removeDirectory("{$target}/wp-admin");

        // Delete all files in WordPress root, skipping wp-config.php if there
        $files = glob("{$target}/*.*");
        foreach ($files as $file) {
            if (is_file($file) && basename($file) !== 'wp-config.php') {
                $fs->unlink($file);
            }
        }

        return [$target, $targetTemp, $zipUrl, $zipFile];
    }

    /**
     * Look in filesystem to find an installed version of WordPress and discover its version.
     *
     * @return string
     */
    private function discoverInstalledVersion()
    {
        $dir = $this->filesystem->normalizePath(getcwd() . '/' . $this->config['target-dir']);

        if (!is_dir($dir)) {
            return '';
        }

        $versionFile = "{$dir}/wp-includes/version.php";
        if (!is_file($versionFile) || !is_readable($versionFile)) {
            return '';
        }

        $wp_version = '';
        /** @noinspection PhpIncludeInspection */
        require $versionFile;

        if (!$wp_version) {
            return '';
        }

        try {
            return $this->normalizeVersionWp($wp_version);
        } catch (\UnexpectedValueException $version) {
            return '';
        }
    }

    /**
     * Looks config in `composer.json` to see which version (or version range) is required.
     * If nothing is set (or something wrong is set) the last available WordPress version
     * is returned.
     *
     * @return string
     * @see WpDownloader::queryLastVersion()
     * @see WpDownloader::discoverWpPackageVersion()
     */
    private function discoverTargetVersion()
    {
        $version = trim($this->config['version']);

        if ($version === 'latest' || $version === '*') {
            return $this->queryLastVersion();
        }

        if (!$version && ($wpPackageVer = $this->discoverWpPackageVersion())) {
            $version = $wpPackageVer;
        }

        $fixedTargetVersion = preg_match('/^[3|4]\.([0-9]){1}(\.[0-9])?+$/', $version) > 0;

        return $fixedTargetVersion ? $this->normalizeVersionWp($version) : trim($version);
    }

    /**
     * Looks config in `composer.json` for any wordpress core package and return the first found.
     *
     * This used to set the version to download if no wp-downloader specific configs are set.
     *
     * @return string
     */
    private function discoverWpPackageVersion()
    {
        static $wpConstraint;
        if (isset($wpConstraint)) {
            return $wpConstraint;
        }

        $rootPackage = $this->composer->getPackage();
        $repo = $this->composer->getRepositoryManager();

        /** @var Link $link */
        foreach ($rootPackage->getRequires() as $link) {
            $constraint = $link->getConstraint();
            $package = $repo->findPackage($link->getTarget(), $constraint);
            if ($package && $package->getType() === 'wordpress-core') {
                $wpConstraint = $constraint->getPrettyString();

                return $wpConstraint;
            }
        }

        return '';
    }

    /**
     * Query wp.org API to get the last version.
     *
     * @return string
     *
     * @throws \RuntimeException in case of API errors
     *
     * @see WpDownloader::queryVersions()
     */
    private function queryLastVersion()
    {
        $versions = $this->queryVersions();
        if (!$versions) {
            throw new \RuntimeException(
                'Could not resolve available WordPress versions from wp.org API.'
            );
        }

        $last = reset($versions);

        return $last;
    }

    /**
     * Query wp.org API to get available versions for download.
     *
     * @return \string[]
     */
    private function queryVersions()
    {
        static $versions;
        if (is_array($versions)) {
            return $versions;
        }

        $this->write('Retrieving WordPress versions info...');
        $content = $this->remoteFilesystem->getContents('wordpress.org', self::RELEASES_URL, false);
        $code = $this->remoteFilesystem->findStatusCode($this->remoteFilesystem->getLastHeaders());
        if ($code !== 200) {
            return [];
        }

        $extractVer = function ($package) {
            if (!is_array($package) || empty($package['version'])) {
                return '';
            }

            return $this->normalizeVersionWp($package['version']);
        };

        try {
            $data = @json_decode($content, true);
            if (!$data || !is_array($data) || empty($data['offers'])) {
                return [];
            }

            $parsed = array_unique(array_filter(array_map($extractVer, (array)$data['offers'])));
            $versions = $parsed ? Semver::rsort($parsed) : [];

            return $versions;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Return true if based on current setup (target and installed ver, update or install context)
     * a new version should be downloaded from wp.org.
     *
     * @param string $targetVersion
     * @param string $installedVersion
     * @return bool
     */
    private function shouldInstall($targetVersion, $installedVersion)
    {
        if (!$installedVersion) {
            return true;
        }

        if (Comparator::equalTo($installedVersion, $targetVersion)) {
            return false;
        }

        if (!$this->isUpdate && Semver::satisfies($installedVersion, $targetVersion)) {
            return false;
        }

        $resolved = $this->resolveTargetVersion($targetVersion);

        return $resolved !== $installedVersion;
    }

    /**
     * Resolve a range of target versions into a canonical version.
     *
     * E.g. ">=4.5" is resolved in something like "4.6.1"
     *
     * @param string $version
     *
     * @return string
     */
    private function resolveTargetVersion($version)
    {
        static $resolved = [];

        if (array_key_exists($version, $resolved)) {
            return $resolved[$version];
        }

        $exact = preg_match('|^[0-9]+\.[0-9]{1}(\.[0-9]+)*(\.[0-9]+)*$|', $version);

        if ($exact) {
            $resolved[$version] = $this->normalizeVersionWp($version);
            // good luck
            return $resolved[$version];
        }

        $versions = $this->queryVersions();

        if (!$versions) {
            throw new \RuntimeException(
                'Could not resolve available WordPress versions from wp.org API.'
            );
        }

        $satisfied = Semver::satisfiedBy($versions, $version);

        if (!$satisfied) {
            throw new \RuntimeException(
                sprintf("No WordPress available version satisfies requirements '{$version}'.")
            );
        }

        $satisfied = Semver::rsort($satisfied);
        $last = reset($satisfied);
        $resolved[$version] = $this->normalizeVersionWp($last);

        return $resolved[$version];
    }

    /**
     * Normalize a version string in the form x.x.x (where "x" is an integer)
     * because Composer semver normalization returns versions in the form  x.x.x.x
     * Moreover, things like x.x.0 are converted to x.x, because WordPress skip zeroes for
     * minor versions.
     *
     * @param string $version
     * @return string
     */
    private function normalizeVersionWp($version)
    {
        $beta = explode('-', trim($version, ". \t\n\r\0\x0B"), 2);
        $stable = $beta[0];

        $pieces = explode('.', preg_replace('/[^0-9\.]/', '', $stable));
        $pieces = array_map('intval', $pieces);
        isset($pieces[0]) or $pieces[0] = 0;
        isset($pieces[1]) or $pieces[1] = 0;
        if ($pieces[1] > 9) {
            $str = (string)$pieces[1];
            $pieces[1] = $str[0];
        }
        if (empty($pieces[2])) {
            return "{$pieces[0]}.{$pieces[1]}";
        }
        if ($pieces[2] > 9) {
            $str = (string)$pieces[1];
            $pieces[2] = $str[0];
        }

        return "{$pieces[0]}.{$pieces[1]}.{$pieces[2]}";
    }

    /**
     * Wrapper around Composer `IO::write`, only write give message IO is verbose or
     * `$force` param is true.
     *
     * @param string $message
     * @param bool $force
     */
    private function write($message, $force = false)
    {
        if ($force || $this->io->isVeryVerbose() || $this->io->isVerbose()) {
            $this->io->write($message);
        }
    }
}
