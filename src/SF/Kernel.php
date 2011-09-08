<?php

namespace SF;

abstract class Kernel
{
    protected $env;

    protected $container;

    public function __construct($env = 'DEV', Container $container = null)
    {
        if (null === $container) {
            $container = new Container();
        }

        $this->env = $env;
        $this->container = $container;
    }

    public function getContainer()
    {
        return $this->container;
    }

    public function getEnv()
    {
        return $this->env;
    }

    abstract protected function getRoutes();
    abstract protected function getConfig();
    abstract protected function getTemplatingDirectories();
    abstract protected function getTemplatingVars();
    abstract protected function getLogFile();

    private function build()
    {
        $this->callStep('init');

        if (null !== ($logFile = $this->getLogFile())) {
            $this->container['logger'] = new Logger($logFile);
        }

        $this->container['router'] = new Router($this->getRoutes());
        $this->container['event_dispatcher'] = new EventDispatcher();
        $this->container['templating'] = new Templating($this->getTemplatingDirectories());
        $this->container['templating']->setGlobalVars($this->getTemplatingVars());

        $this->callStep('start');
    }

    protected function callStep($step)
    {
        $methods = get_class_methods($this);
        $config = $this->getConfig();

        foreach ($methods as $method) {
            if (0 === strpos($method, $step)) {
                $name = strtolower(str_replace($step, '', $method));
                $this->$method(isset($config[$name]) ? $config[$name] : array());
            }
        }
    }

    /**
     * Handle the request and convert it to a response
     *
     * 1. url is parsed to find a route
     * 2. route controller is instanciated
     * 3. controller action is called and return response content
     */
    public function run()
    {
        $this->build();
        $this->container->freeze();

        $pathInfo = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '/';
        list($route, $params) = $this->container['router']->match($pathInfo);

        $controllerClass = $route['controller'];
        $controller = new $controllerClass($this->container);

        if (null !== ($before = $this->container['event_dispatcher']->dispatch('controller.before', array('route' => $route)))) {
            return $before;
        }

        $content = $this->callAction($controller, $route['action'], $params);

        if (null !== ($after = $this->container['event_dispatcher']->dispatch('controller.after', array('content' => $content)))) {
            return $after;
        }

        return $content;
    }

    /**
     * Call an action on a controller
     *
     * @param controller object     an instance of the controller
     * @param actionName string     the action name (eg: homepage)
     */
    private function callAction($controller, $action, $params = array())
    {
        $method = sprintf('%sAction', $action);
        $controllerRefl = new \ReflectionClass($controller);

        if (!$controllerRefl->hasMethod($method)) {
            throw new \InvalidArgumentException(sprintf('action \'%s\' do not exists', $action));
        }

        $reflMethod = $controllerRefl->getMethod($method);

        // if a $_GET['x'] parameter is found with and the action method accepts a $x argument
        // we add the GET parameter to the action call
        $actionParams = array();
        foreach($reflMethod->getParameters() as $reflParam) {
            $actionParams[] = isset($params[$reflParam->name]) ? $params[$reflParam->name] : null;
        }

        return $reflMethod->invokeArgs($controller, $actionParams);
    }
}
