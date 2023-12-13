<?php

declare(strict_types=1);

namespace WCM\WpDownloader\Tests\Unit;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
use WCM\WpDownloader\WpDownloader;

class WpDownloaderTest extends AbstractTestCase
{
    public function testCanInstantiateClass()
    {
        $instance = new WpDownloader();
        $this->assertInstanceOf(WpDownloader::class, $instance);
    }

    public function testActivate()
    {
        $mock = \Mockery::mock(WpDownloader::class)->shouldAllowMockingProtectedMethods()->makePartial();
        $composerMock = \Mockery::mock(Composer::class);
        $ioMock = \Mockery::mock(IOInterface::class);
        $filesystemMock = \Mockery::mock(Filesystem::class);
        $remoteFsMock = \Mockery::mock(RemoteFilesystem::class);
        $mock->shouldReceive('prepareFilesystem')->once()->andReturn($filesystemMock);
        $mock->shouldReceive('prepareRemoteSystem')->withArgs([
            $composerMock,
            $ioMock
        ])->once()->andReturn($remoteFsMock);
        $mock->shouldReceive('prepareConfig')->withArgs([
            $composerMock,
        ])->once();
        $mock->activate($composerMock, $ioMock);
    }
}