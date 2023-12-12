<?php

declare(strict_types=1);

namespace WCM\WpDownloader\Tests\Unit;

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
        $instance = new WpDownloader();
        $this->assertInstanceOf(WpDownloader::class, $instance);
    }
}