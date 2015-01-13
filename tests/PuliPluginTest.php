<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\ComposerPlugin\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\RepositoryManager;
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use PHPUnit_Framework_MockObject_MockObject;
use Puli\ComposerPlugin\PuliPlugin;
use Puli\ComposerPlugin\Tests\Fixtures\TestLocalRepository;
use Puli\RepositoryManager\Tests\JsonWriterTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\Json\JsonDecoder;
use Webmozart\PathUtil\Path;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @runTestsInSeparateProcesses
 */
class PuliPluginTest extends JsonWriterTestCase
{
    /**
     * @var PuliPlugin
     */
    private $plugin;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|IOInterface
     */
    private $io;

    /**
     * @var TestLocalRepository
     */
    private $localRepository;

    /**
     * @var RepositoryManager
     */
    private $repositoryManager;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|InstallationManager
     */
    private $installationManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var RootPackage
     */
    private $rootPackage;

    private $tempDir;

    private $tempHome;

    private $previousWd;

    private $installPaths;

    public function getInstallPath(Package $package)
    {
        if (isset($this->installPaths[$package->getName()])) {
            return $this->installPaths[$package->getName()];
        }

        return $this->tempDir.'/'.basename($package->getName());
    }

    protected function setUp()
    {
        while (false === mkdir($this->tempDir = sys_get_temp_dir().'/puli-plugin/PuliPluginTest_root'.rand(10000, 99999), 0777, true)) {}
        while (false === mkdir($this->tempHome = sys_get_temp_dir().'/puli-plugin/PuliPluginTest_home'.rand(10000, 99999), 0777, true)) {}

        $filesystem = new Filesystem();
        $filesystem->mirror(__DIR__.'/Fixtures/root', $this->tempDir);
        $filesystem->mirror(__DIR__.'/Fixtures/home', $this->tempHome);

        $this->plugin = new PuliPlugin();
        $this->io = $this->getMock('Composer\IO\IOInterface');
        $this->config = new Config(false, $this->tempDir);
        $this->config->merge(array('config' => array('vendor-dir' => 'the-vendor')));

        $this->installationManager = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->installationManager->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(array($this, 'getInstallPath')));

        $this->rootPackage = new RootPackage('vendor/root', '1.0', '1.0');

        $this->localRepository = new TestLocalRepository(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->repositoryManager = new RepositoryManager($this->io, $this->config);
        $this->repositoryManager->setLocalRepository($this->localRepository);
        $this->installPaths = array();

        $this->composer = new Composer();
        $this->composer->setRepositoryManager($this->repositoryManager);
        $this->composer->setInstallationManager($this->installationManager);
        $this->composer->setConfig($this->config);
        $this->composer->setPackage($this->rootPackage);

        $this->previousWd = getcwd();

        chdir($this->tempDir);
        putenv('PULI_HOME='.$this->tempHome);
    }

    protected function tearDown()
    {
        chdir($this->previousWd);
        putenv('PULI_HOME');

        $filesystem = new Filesystem();
        $filesystem->remove($this->tempDir);
        $filesystem->remove($this->tempHome);
    }

    public function testActivate()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $dispatcher->expects($this->once())
            ->method('addSubscriber')
            ->with($this->plugin);

        $this->composer->setEventDispatcher($dispatcher);

        $this->plugin->activate($this->composer, $this->io);
    }

    public function getInstallEventNames()
    {
        return array(
            array(ScriptEvents::POST_INSTALL_CMD),
            array(ScriptEvents::POST_UPDATE_CMD),
        );
    }

    /**
     * @dataProvider getInstallEventNames
     */
    public function testInstallNewPuliPackages($eventName)
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey($eventName, $listeners);

        $listener = $listeners[$eventName];
        $event = new CommandEvent($eventName, $this->composer, $this->io);

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('Installing <info>vendor/package2</info> (<comment>package2</comment>)');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Building Puli resource repository</info>');

        $this->plugin->$listener($event);

        $this->assertFileExists($this->tempDir.'/My/PuliFactory.php');
        $this->assertFileExists($this->tempDir.'/repository');

        $this->assertJsonFileEquals($this->tempDir.'/puli-all-installed.json', $this->tempDir.'/puli.json');
    }

    /**
     * @dataProvider getInstallEventNames
     * @depends testInstallNewPuliPackages
     */
    public function testEventListenersOnlyProcessedOnFirstCall($eventName)
    {
        // Execute normal test
        $this->testInstallNewPuliPackages($eventName);

        // Now fire again
        $event = new CommandEvent($eventName, $this->composer, $this->io);
        $listeners = PuliPlugin::getSubscribedEvents();
        $listener = $listeners[$eventName];

        $this->plugin->$listener($event);
    }

    public function testDoNotReinstallExistingPuliPackages()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy($this->tempDir.'/puli-partially-installed.json', $this->tempDir.'/puli.json');

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package2</info> (<comment>package2</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Building Puli resource repository</info>');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Building Puli resource discovery</info>');

        $this->plugin->postInstall($event);
    }

    // meta packages have no install path
    public function testDoNotInstallPackagesWithoutInstallPath()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
        ));

        $this->installPaths['vendor/package1'] = '';

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Building Puli resource repository</info>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Building Puli resource discovery</info>');

        $this->plugin->postInstall($event);
    }

    public function testResolveAliasPackages()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $package = new Package('vendor/package1', '1.0', '1.0');

        $this->localRepository->setPackages(array(
            // Package is not listed in installed packages
            new AliasPackage($package, '1.0', '1.0'),
        ));

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Building Puli resource repository</info>');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Building Puli resource discovery</info>');

        $this->plugin->postInstall($event);
    }

    public function testInstallAliasedPackageOnlyOnce()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $package = new Package('vendor/package1', '1.0', '1.0');

        $this->localRepository->setPackages(array(
            // This time the package is returned here as well
            $package,
            new AliasPackage($package, '1.0', '1.0'),
        ));

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Building Puli resource repository</info>');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Building Puli resource discovery</info>');

        $this->plugin->postInstall($event);
    }

    public function testRemoveRemovedPackages()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy($this->tempDir.'/puli-too-many-installed.json', $this->tempDir.'/puli.json');

        $this->localRepository->setPackages(array());

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Removing <info>vendor/package3</info> (<comment>package3</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Building Puli resource repository</info>');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Building Puli resource discovery</info>');

        $this->plugin->postInstall($event);

        $this->assertFileExists($this->tempDir.'/My/PuliFactory.php');
    }

    public function testDoNotRemovePackagesFromOtherInstaller()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy($this->tempDir.'/puli-other-installer.json', $this->tempDir.'/puli.json');

        $this->localRepository->setPackages(array());

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Building Puli resource repository</info>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Building Puli resource discovery</info>');

        $this->plugin->postInstall($event);

        $this->assertFileExists($this->tempDir.'/My/PuliFactory.php');
    }

    public function testReinstallPackagesWithInstallPathMovedToSubPath()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy($this->tempDir.'/puli-all-installed.json', $this->tempDir.'/puli.json');

        // Package was moved to sub-path (PSR-0 -> PSR-4)
        // Such a package is not recognized as removed, because the path still
        // exists. Hence we need to explicitly reinstall it.
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1/sub/path';

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Reinstalling <info>vendor/package1</info> (<comment>package1/sub/path</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Building Puli resource repository</info>');

        $this->plugin->postInstall($event);

        $this->assertFileExists($this->tempDir.'/My/PuliFactory.php');
        $this->assertFileExists($this->tempDir.'/repository');

        $this->assertJsonFileEquals($this->tempDir.'/puli-moved-package.json', $this->tempDir.'/puli.json');
    }

    public function testReinstallPackagesWithInstallPathMovedToParentPath()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy($this->tempDir.'/puli-moved-package.json', $this->tempDir.'/puli.json');

        // Package was moved to parent path (PSR-4 -> PSR-0)
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1';

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Reinstalling <info>vendor/package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Building Puli resource repository</info>');

        $this->plugin->postInstall($event);

        $this->assertFileExists($this->tempDir.'/My/PuliFactory.php');
        $this->assertFileExists($this->tempDir.'/repository');

        $this->assertJsonFileEquals($this->tempDir.'/puli-all-installed.json', $this->tempDir.'/puli.json');
    }

    public function testCopyComposerPackageNameToPuli()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->plugin->postInstall($event);

        $this->assertFileExists($this->tempDir.'/puli.json');

        $decoder = new JsonDecoder();
        $data = $decoder->decodeFile($this->tempDir.'/puli.json');

        $this->assertSame('vendor/root', $data->name);
    }

    public function testInsertFactoryClassIntoClassMap()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Generating PULI_FACTORY_CLASS constant</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Registering My\\PuliFactory with the class-map autoloader</info>');

        $this->plugin->$listener($event);

        $this->assertFileExists($this->tempDir.'/the-vendor/composer/autoload_classmap.php');

        $classMap = require $this->tempDir.'/the-vendor/composer/autoload_classmap.php';

        $this->assertInternalType('array', $classMap);
        $this->assertArrayHasKey('My\\PuliFactory', $classMap);
        $this->assertSame($this->tempDir.'/My/PuliFactory.php', Path::canonicalize($classMap['My\\PuliFactory']));
    }

    /**
     * @expectedException \Puli\ComposerPlugin\PuliPluginException
     * @expectedExceptionMessage autoload_classmap.php
     */
    public function testFailIfClassMapFileNotFound()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->once())
            ->method('write')
            ->with('<info>Generating PULI_FACTORY_CLASS constant</info>');

        unlink($this->tempDir.'/the-vendor/composer/autoload_classmap.php');

        $this->plugin->$listener($event);
    }

    /**
     * @runInSeparateProcess
     */
    public function testInsertFactoryConstantIntoAutoload()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Generating PULI_FACTORY_CLASS constant</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Registering My\\PuliFactory with the class-map autoloader</info>');

        $this->plugin->$listener($event);

        $this->assertFileExists($this->tempDir.'/the-vendor/autoload.php');

        require $this->tempDir.'/the-vendor/autoload.php';

        $this->assertTrue(defined('PULI_FACTORY_CLASS'));
        $this->assertSame('My\\PuliFactory', PULI_FACTORY_CLASS);
    }

    /**
     * @expectedException \Puli\ComposerPlugin\PuliPluginException
     * @expectedExceptionMessage autoload.php
     */
    public function testFailIfAutoloadFileNotFound()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->never())
            ->method('write');

        unlink($this->tempDir.'/the-vendor/autoload.php');

        $this->plugin->$listener($event);
    }

    public function testRunPostAutoloadDumpOnlyOnce()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->exactly(2))
            ->method('write');

        $this->plugin->$listener($event);
        $this->plugin->$listener($event);
    }
}
