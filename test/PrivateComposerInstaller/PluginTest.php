<?php

namespace FFraenz\PrivateComposerInstaller\Test;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Util\RemoteFilesystem;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;
use FFraenz\PrivateComposerInstaller\Plugin;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    protected function tearDown()
    {
        // Unset environment variables
        putenv('KEY_FOO');
        putenv('KEY_BAR');

        // Remove dot env file
        $dotenv = getcwd() . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($dotenv)) {
            unlink($dotenv);
        }
    }

    public function testImplementsPluginInterface()
    {
        $this->assertInstanceOf(PluginInterface::class, new Plugin());
    }

    public function testImplementsEventSubscriberInterface()
    {
        $this->assertInstanceOf(EventSubscriberInterface::class, new Plugin());
    }

    public function testActivateMakesComposerAndIOAvailable()
    {
        $composer = $this->createMock(Composer::class);
        $io = $this->createMock(IOInterface::class);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $this->assertAttributeEquals($composer, 'composer', $plugin);
        $this->assertAttributeEquals($io, 'io', $plugin);
    }

    public function testSubscribesToPrePackageInstallEvent()
    {
        $subscribedEvents = Plugin::getSubscribedEvents();
        $this->assertEquals(
            $subscribedEvents[PackageEvents::PRE_PACKAGE_INSTALL],
            'injectVersion'
        );
    }

    public function testSubscribesToPreUpdateInstallEvent()
    {
        $subscribedEvents = Plugin::getSubscribedEvents();
        $this->assertEquals(
            $subscribedEvents[PackageEvents::PRE_PACKAGE_UPDATE],
            'injectVersion'
        );
    }

    public function testSubscribesToPreFileDownloadEvent()
    {
        $subscribedEvents = Plugin::getSubscribedEvents();
        $this->assertEquals(
            $subscribedEvents[PluginEvents::PRE_FILE_DOWNLOAD],
            ['injectPlaceholders', -1]
        );
    }

    public function testIgnorePackagesWithoutPlaceholders()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods([
                'getDistUrl',
                'getPrettyVersion',
                'setDistUrl',
            ])
            ->getMockForAbstractClass();

        $package
            ->expects($this->once())
            ->method('getDistUrl')
            ->willReturn('https://example.com/download');

        $package
            ->expects($this->never())
            ->method('getPrettyVersion');

        $package
            ->expects($this->never())
            ->method('setDistUrl');

        // Mock an operation
        $operation = $this
            ->getMockBuilder(InstallOperation::class)
            ->disableOriginalConstructor()
            ->setMethods(['getJobType', 'getPackage'])
            ->getMock();

        $operation
            ->expects($this->once())
            ->method('getJobType')
            ->willReturn('install');

        $operation
            ->expects($this->once())
            ->method('getPackage')
            ->willReturn($package);

        // Mock a package event
        $packageEvent = $this
            ->getMockBuilder(PackageEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOperation'])
            ->getMock();

        $packageEvent
            ->expects($this->once())
            ->method('getOperation')
            ->willReturn($operation);

        // Trigger install event handler
        $plugin = new Plugin();
        $plugin->injectVersion($packageEvent);
    }

    public function testInjectVersionOnInstall()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods(['getDistUrl', 'getPrettyVersion', 'setDistUrl'])
            ->getMockForAbstractClass();

        $package
            ->expects($this->once())
            ->method('getPrettyVersion')
            ->willReturn('1.2.3');

        $package
            ->expects($this->once())
            ->method('getDistUrl')
            ->willReturn('https://example.com/d?key={%KEY_FOO}');

        $package
            ->expects($this->once())
            ->method('setDistUrl')
            ->with('https://example.com/d?key={%KEY_FOO}#v1.2.3');

        // Mock an operation
        $operation = $this
            ->getMockBuilder(InstallOperation::class)
            ->disableOriginalConstructor()
            ->setMethods(['getJobType', 'getPackage'])
            ->getMock();

        $operation
            ->expects($this->once())
            ->method('getJobType')
            ->willReturn('install');

        $operation
            ->expects($this->once())
            ->method('getPackage')
            ->willReturn($package);

        // Mock a package event
        $packageEvent = $this
            ->getMockBuilder(PackageEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOperation'])
            ->getMock();

        $packageEvent
            ->expects($this->once())
            ->method('getOperation')
            ->willReturn($operation);

        // Trigger install event handler
        $plugin = new Plugin();
        $plugin->injectVersion($packageEvent);
    }

    public function testReplaceVersionPlaceholderOnInstall()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods(['getDistUrl', 'getPrettyVersion', 'setDistUrl'])
            ->getMockForAbstractClass();

        $package
            ->expects($this->once())
            ->method('getPrettyVersion')
            ->willReturn('1.2.3');

        $package
            ->expects($this->once())
            ->method('getDistUrl')
            ->willReturn('https://example.com/r/{%version}/d?key={%KEY_FOO}');

        $package
            ->expects($this->once())
            ->method('setDistUrl')
            ->with('https://example.com/r/1.2.3/d?key={%KEY_FOO}');

        // Mock an operation
        $operation = $this
            ->getMockBuilder(InstallOperation::class)
            ->disableOriginalConstructor()
            ->setMethods(['getJobType', 'getPackage'])
            ->getMock();

        $operation
            ->expects($this->once())
            ->method('getJobType')
            ->willReturn('install');

        $operation
            ->expects($this->once())
            ->method('getPackage')
            ->willReturn($package);

        // Mock a package event
        $packageEvent = $this
            ->getMockBuilder(PackageEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOperation'])
            ->getMock();

        $packageEvent
            ->expects($this->once())
            ->method('getOperation')
            ->willReturn($operation);

        // Trigger install event handler
        $plugin = new Plugin();
        $plugin->injectVersion($packageEvent);
    }

    public function testInjectVersionOnUpdate()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods(['getDistUrl', 'getPrettyVersion', 'setDistUrl'])
            ->getMockForAbstractClass();

        $package
            ->expects($this->once())
            ->method('getPrettyVersion')
            ->willReturn('1.2.3');

        $package
            ->expects($this->once())
            ->method('getDistUrl')
            ->willReturn('https://example.com/d?key={%KEY_FOO}');

        $package
            ->expects($this->once())
            ->method('setDistUrl')
            ->with('https://example.com/d?key={%KEY_FOO}#v1.2.3');

        // Mock an operation
        $operation = $this
            ->getMockBuilder(InstallOperation::class)
            ->disableOriginalConstructor()
            ->setMethods(['getJobType', 'getTargetPackage'])
            ->getMock();

        $operation
            ->expects($this->once())
            ->method('getJobType')
            ->willReturn('update');

        $operation
            ->expects($this->once())
            ->method('getTargetPackage')
            ->willReturn($package);

        // Mock a package event
        $packageEvent = $this
            ->getMockBuilder(PackageEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOperation'])
            ->getMock();

        $packageEvent
            ->expects($this->once())
            ->method('getOperation')
            ->willReturn($operation);

        // Trigger install event handler
        $plugin = new Plugin();
        $plugin->injectVersion($packageEvent);
    }

    public function testReplaceVersionPlaceholderOnUpdate()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods(['getDistUrl', 'getPrettyVersion', 'setDistUrl'])
            ->getMockForAbstractClass();

        $package
            ->expects($this->once())
            ->method('getPrettyVersion')
            ->willReturn('1.2.3');

        $package
            ->expects($this->once())
            ->method('getDistUrl')
            ->willReturn('https://example.com/r/{%version}/d?key={%KEY_FOO}');

        $package
            ->expects($this->once())
            ->method('setDistUrl')
            ->with('https://example.com/r/1.2.3/d?key={%KEY_FOO}');

        // Mock an operation
        $operation = $this
            ->getMockBuilder(InstallOperation::class)
            ->disableOriginalConstructor()
            ->setMethods(['getJobType', 'getTargetPackage'])
            ->getMock();

        $operation
            ->expects($this->once())
            ->method('getJobType')
            ->willReturn('update');

        $operation
            ->expects($this->once())
            ->method('getTargetPackage')
            ->willReturn($package);

        // Mock a package event
        $packageEvent = $this
            ->getMockBuilder(PackageEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOperation'])
            ->getMock();

        $packageEvent
            ->expects($this->once())
            ->method('getOperation')
            ->willReturn($operation);

        // Trigger install event handler
        $plugin = new Plugin();
        $plugin->injectVersion($packageEvent);
    }

    public function testIgnoresProcessedUrlWithoutPlaceholders()
    {
        // Mock a RemoteFilesystem
        $rfs = $this
            ->getMockBuilder(RemoteFilesystem::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock an Event
        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getProcessedUrl',
                'getRemoteFilesystem',
                'setRemoteFilesystem'
            ])
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getProcessedUrl')
            ->willReturn('https://example.com/r/1.2.3/d');

        $event
            ->expects($this->never())
            ->method('getRemoteFilesystem')
            ->willReturn($rfs);

        $event
            ->expects($this->never())
            ->method('setRemoteFilesystem')
            ->willReturn($rfs);

        // Test placeholder injection
        $plugin = new Plugin();
        $plugin->injectPlaceholders($event);
    }

    public function testProcessedUrlWithPlaceholdersConfiguresFilesystem()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock RemoteFilesystem instance
        $options = ['options' => 'array'];
        $tlsDisabled = true;
        $rfs = $this
            ->getMockBuilder(RemoteFilesystem::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOptions', 'isTlsDisabled'])
            ->getMock();

        $rfs
            ->expects($this->once())
            ->method('getOptions')
            ->willReturn($options);

        $rfs
            ->expects($this->once())
            ->method('isTlsDisabled')
            ->willReturn($tlsDisabled);

        // Mock Config instance
        $config = $this
            ->getMockBuilder(Config::class)
            ->getMock();

        // Mock Composer instance
        $composer = $this
            ->getMockBuilder(Composer::class)
            ->setMethods(['getConfig'])
            ->getMock();

        $composer
            ->expects($this->once())
            ->method('getConfig')
            ->willReturn($config);

        // Mock IOInterface instance
        $io = $this
            ->getMockBuilder(IOInterface::class)
            ->getMock();

        // Mock an Event
        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getProcessedUrl',
                'getRemoteFilesystem',
                'setRemoteFilesystem',
            ])
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getProcessedUrl')
            ->willReturn('https://example.com/r/1.2.3/d?key={%KEY_FOO}');

        $event
            ->expects($this->once())
            ->method('getRemoteFilesystem')
            ->willReturn($rfs);

        $event
            ->expects($this->once())
            ->method('setRemoteFilesystem')
            ->with($this->callback(
                function ($rfs) use ($config, $io, $options, $tlsDisabled) {
                    $this->assertAttributeEquals($config, 'config', $rfs);
                    $this->assertAttributeEquals($io, 'io', $rfs);
                    $this->assertEquals($options, $rfs->getOptions());
                    $this->assertEquals($tlsDisabled, $rfs->isTlsDisabled());
                    return true;
                }
            ));

        // Trigger placeholder injection
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->injectPlaceholders($event);
    }

    protected function expectFileDownload($processedUrl, $expectedUrl)
    {
        // Mock RemoteFilesystem instance
        $options = ['options' => 'array'];
        $tlsDisabled = true;
        $rfs = $this
            ->getMockBuilder(RemoteFilesystem::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOptions', 'isTlsDisabled'])
            ->getMock();

        $rfs
            ->method('getOptions')
            ->willReturn($options);

        $rfs
            ->method('isTlsDisabled')
            ->willReturn($tlsDisabled);

        // Mock Config instance
        $config = $this
            ->getMockBuilder(Config::class)
            ->getMock();

        // Mock Composer instance
        $composer = $this
            ->getMockBuilder(Composer::class)
            ->setMethods(['getConfig'])
            ->getMock();

        $composer
            ->method('getConfig')
            ->willReturn($config);

        // Mock IOInterface instance
        $io = $this
            ->getMockBuilder(IOInterface::class)
            ->getMock();

        // Mock an Event
        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getProcessedUrl',
                'getRemoteFilesystem',
                'setRemoteFilesystem',
            ])
            ->getMock();

        $event
            ->method('getProcessedUrl')
            ->willReturn($processedUrl);

        $event
            ->method('getRemoteFilesystem')
            ->willReturn($rfs);

        $event
            ->method('setRemoteFilesystem')
            ->with($this->callback(
                function ($rfs) use ($expectedUrl) {
                    $this->assertAttributeEquals(
                        $expectedUrl,
                        'privateFileUrl',
                        $rfs
                    );
                    return true;
                }
            ));

        // Trigger placeholder injection
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->injectPlaceholders($event);
    }

    public function testInjectsSinglePlaceholderFromEnv()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Test placeholder injection
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}',
            'https://example.com/r/1.2.3/d?key=TEST'
        );
    }

    public function testInjectsSinglePlaceholderMultipleTimes()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Test placeholder injection
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&confirm={%KEY_FOO}',
            'https://example.com/r/1.2.3/d?key=TEST&confirm=TEST'
        );
    }

    public function testInjectsMultiplePlaceholdersFromEnv()
    {
        // Make env variables available
        putenv('KEY_FOO=Hello');
        putenv('KEY_BAR=World');

        // Test placeholder injection
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&secret={%KEY_BAR}',
            'https://example.com/r/1.2.3/d?key=Hello&secret=World'
        );
    }

    public function testInjectsMultiplePlaceholdersFromDotEnvFile()
    {
        // Make env variables available through dot env file
        file_put_contents(
            getcwd() . DIRECTORY_SEPARATOR . '.env',
            'KEY_FOO=Hello' . PHP_EOL . 'KEY_BAR=World' . PHP_EOL
        );

        // Test placeholder injection
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&secret={%KEY_BAR}',
            'https://example.com/r/1.2.3/d?key=Hello&secret=World'
        );
    }

    public function testPrefersVariableFromEnv()
    {
        // Make env variables available
        putenv('KEY_FOO=YAY');

        // Make diffrent env variable available through dot env file
        file_put_contents(
            getcwd() . DIRECTORY_SEPARATOR . '.env',
            'KEY_FOO=NAY' . PHP_EOL
        );

        // Expect the env variable to be used over the dot env file
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}',
            'https://example.com/r/1.2.3/d?key=YAY'
        );
    }

    public function testThrowsExceptionWhenEnvVariableIsMissing()
    {
        // Expect an exception
        $this->expectException(MissingEnvException::class);
        $this->expectExceptionMessage(
            'Can\'t resolve placeholder {%KEY_FOO}. ' .
            'Environment variable \'KEY_FOO\' is not set.'
        );

        // Mock a RemoteFilesystem
        $rfs = $this
            ->getMockBuilder(RemoteFilesystem::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock an Event
        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['getProcessedUrl', 'getRemoteFilesystem'])
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getProcessedUrl')
            ->willReturn('https://example.com/r/1.2.3/d?key={%KEY_FOO}');

        $event
            ->expects($this->never())
            ->method('getRemoteFilesystem')
            ->willReturn($rfs);

        // Test placeholder injection
        $plugin = new Plugin();
        $plugin->injectPlaceholders($event);
    }
}
