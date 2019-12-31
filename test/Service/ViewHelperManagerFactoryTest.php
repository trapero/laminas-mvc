<?php

/**
 * @see       https://github.com/laminas/laminas-mvc for the canonical source repository
 * @copyright https://github.com/laminas/laminas-mvc/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-mvc/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\Mvc\Service;

use Laminas\Console\Request as ConsoleRequest;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;
use Laminas\Mvc\Router\RouteMatch;
use Laminas\Mvc\Router\RouteStackInterface;
use Laminas\Mvc\Service\ViewHelperManagerFactory;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Helper;
use PHPUnit_Framework_TestCase as TestCase;

class ViewHelperManagerFactoryTest extends TestCase
{
    public function setUp()
    {
        $this->services = new ServiceManager();
        $this->factory  = new ViewHelperManagerFactory();
    }

    /**
     * @return array
     */
    public function emptyConfiguration()
    {
        return [
            'no-config'                => [[]],
            'view-manager-config-only' => [['view_manager' => []]],
            'empty-doctype-config'     => [['view_manager' => ['doctype' => null]]],
        ];
    }

    /**
     * @dataProvider emptyConfiguration
     * @param  array $config
     * @return void
     */
    public function testDoctypeFactoryDoesNotRaiseErrorOnMissingConfiguration($config)
    {
        $this->services->setService('config', $config);
        $manager = $this->factory->createService($this->services);
        $this->assertInstanceof('Laminas\View\HelperPluginManager', $manager);
        $doctype = $manager->get('doctype');
        $this->assertInstanceof('Laminas\View\Helper\Doctype', $doctype);
    }

    public function testConsoleRequestsResultInSilentFailure()
    {
        $this->services->setService('config', []);
        $this->services->setService('Request', new ConsoleRequest());

        $manager = $this->factory->createService($this->services);

        $doctype = $manager->get('doctype');
        $this->assertInstanceof('Laminas\View\Helper\Doctype', $doctype);

        $basePath = $manager->get('basepath');
        $this->assertInstanceof('Laminas\View\Helper\BasePath', $basePath);
    }

    /**
     * @group 6247
     */
    public function testConsoleRequestWithBasePathConsole()
    {
        $this->services->setService('config', [
            'view_manager' => [
                'base_path_console' => 'http://test.com'
            ]
        ]);
        $this->services->setService('Request', new ConsoleRequest());

        $manager = $this->factory->createService($this->services);

        $basePath = $manager->get('basepath');
        $this->assertEquals('http://test.com', $basePath());
    }

    public function urlHelperNames()
    {
        return [
            ['url'],
            ['Url'],
            [Helper\Url::class],
            ['laminasviewhelperurl'],
        ];
    }

    /**
     * @group 71
     * @dataProvider urlHelperNames
     */
    public function testUrlHelperFactoryCanBeInvokedViaShortNameOrFullClassName($name)
    {
        $routeMatch = $this->prophesize(RouteMatch::class)->reveal();
        $mvcEvent = $this->prophesize(MvcEvent::class);
        $mvcEvent->getRouteMatch()->willReturn($routeMatch);

        $application = $this->prophesize(Application::class);
        $application->getMvcEvent()->willReturn($mvcEvent->reveal());

        $router = $this->prophesize(RouteStackInterface::class)->reveal();

        $this->services->setService('HttpRouter', $router);
        $this->services->setService('Router', $router);
        $this->services->setService('application', $application->reveal());
        $this->services->setService('config', []);

        $manager = $this->factory->createService($this->services);
        $helper = $manager->get($name);

        $this->assertAttributeSame($routeMatch, 'routeMatch', $helper, 'Route match was not injected');
        $this->assertAttributeSame($router, 'router', $helper, 'Router was not injected');
    }

    public function basePathConfiguration()
    {
        $names = ['basepath', 'basePath', 'BasePath', Helper\BasePath::class, 'laminasviewhelperbasepath'];

        $configurations = [
            'console' => [[
                'config' => [
                    'view_manager' => [
                        'base_path_console' => '/foo/bar',
                    ],
                ],
            ], '/foo/bar'],

            'hard-coded' => [[
                'config' => [
                    'view_manager' => [
                        'base_path' => '/foo/baz',
                    ],
                ],
            ], '/foo/baz'],

            'request-base' => [[
                'config' => [], // fails creating plugin manager without this
                'request' => function () {
                    $request = $this->prophesize(Request::class);
                    $request->getBasePath()->willReturn('/foo/bat');
                    return $request->reveal();
                },
            ], '/foo/bat'],
        ];

        foreach ($names as $name) {
            foreach ($configurations as $testcase => $arguments) {
                array_unshift($arguments, $name);
                $testcase .= '-' . $name;
                yield $testcase => $arguments;
            }
        }
    }

    /**
     * @group 71
     * @dataProvider basePathConfiguration
     */
    public function testBasePathHelperFactoryCanBeInvokedViaShortNameOrFullClassName($name, array $services, $expected)
    {
        foreach ($services as $key => $value) {
            if (is_callable($value)) {
                $this->services->setFactory($key, $value);
                continue;
            }

            $this->services->setService($key, $value);
        }

        $plugins = $this->factory->createService($this->services);
        $helper = $plugins->get($name);
        $this->assertInstanceof(Helper\BasePath::class, $helper);
        $this->assertEquals($expected, $helper());
    }

    public function doctypeHelperNames()
    {
        return [
            ['doctype'],
            ['Doctype'],
            [Helper\Doctype::class],
            ['laminasviewhelperdoctype'],
        ];
    }

    /**
     * @group 71
     * @dataProvider doctypeHelperNames
     */
    public function testDoctypeHelperFactoryCanBeInvokedViaShortNameOrFullClassName($name)
    {
        $this->services->setService('config', [
            'view_manager' => [
                'doctype' => Helper\Doctype::HTML5,
            ],
        ]);

        $plugins = $this->factory->createService($this->services);
        $helper = $plugins->get($name);
        $this->assertInstanceof(Helper\Doctype::class, $helper);
        $this->assertEquals('<!DOCTYPE html>', (string) $helper);
    }
}
