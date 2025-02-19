<?php

declare(strict_types=1);

namespace Codeception\Lib\Generator;

use Codeception\Codecept;
use Codeception\Configuration;
use Codeception\Lib\Di;
use Codeception\Lib\Generator\Shared\Classname;
use Codeception\Lib\ModuleContainer;
use Codeception\Step\GeneratedStep;
use Codeception\Util\ReflectionHelper;
use Codeception\Util\Template;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

class Actions
{
    use Classname;

    public Di $di;

    public ModuleContainer $moduleContainer;

    protected string $template = <<<EOF
<?php  //[STAMP] {{hash}}
namespace {{namespace}}_generated;

// This class was automatically generated by build task
// You should not change it manually as it will be overwritten on next build
// @codingStandardsIgnoreFile

trait {{name}}Actions
{
    /**
     * @return \Codeception\Scenario
     */
    abstract protected function getScenario();

    {{methods}}
}

EOF;

    protected string $methodTemplate = <<<EOF

    /**
     * [!] Method is generated. Documentation taken from corresponding module.
     *
     {{doc}}
     * @see \{{module}}::{{method}}()
     */
    public function {{action}}({{params}}){{return_type}} {
        {{return}}\$this->getScenario()->runStep(new \Codeception\Step\{{step}}('{{method}}', func_get_args()));
    }
EOF;

    protected string $name;

    protected array $settings = [];

    protected array $modules = [];

    protected array $actions = [];

    protected int $numMethods = 0;

    /**
     * @var GeneratedStep[]
     */
    protected array $generatedSteps = [];

    public function __construct(array $settings)
    {
        $this->name = $settings['actor'];
        $this->settings = $settings;
        $this->di = new Di();
        $modules = Configuration::modules($this->settings);
        $this->moduleContainer = new ModuleContainer($this->di, $settings);
        foreach ($modules as $moduleName) {
            $this->moduleContainer->create($moduleName);
        }
        $this->modules = $this->moduleContainer->all();
        $this->actions = $this->moduleContainer->getActions();

        $this->generatedSteps = (array)$settings['step_decorators'];
    }

    public function produce(): string
    {
        $namespace = trim($this->supportNamespace(), '\\');

        $methods = [];
        $code = [];
        foreach ($this->actions as $action => $moduleName) {
            if (in_array($action, $methods)) {
                continue;
            }
            $class = new ReflectionClass($this->modules[$moduleName]);
            $method = $class->getMethod($action);
            $code[] = $this->addMethod($method);
            $methods[] = $action;
            ++$this->numMethods;
        }

        return (new Template($this->template))
            ->place('namespace', $namespace !== '' ? $namespace . '\\' : '')
            ->place('hash', self::genHash($this->modules, $this->settings))
            ->place('name', $this->name)
            ->place('methods', implode("\n\n ", $code))
            ->produce();
    }

    protected function addMethod(ReflectionMethod $refMethod): string
    {
        $class = $refMethod->getDeclaringClass();
        $params = $this->getParamsString($refMethod);
        $module = $class->getName();

        $body = '';
        $doc = $this->addDoc($class, $refMethod);
        $doc = str_replace('/**', '', (string)$doc);
        $doc = trim(str_replace('*/', '', $doc));
        if ($doc === '') {
            $doc = "*";
        }
        $returnType = $this->createReturnTypeHint($refMethod);

        $methodTemplate = (new Template($this->methodTemplate))
            ->place('module', $module)
            ->place('method', $refMethod->name)
            ->place('return_type', $returnType)
            ->place('return', $returnType === ': void' ? '' : 'return ')
            ->place('params', $params);

        if (str_starts_with($refMethod->name, 'see')) {
            $type = 'Assertion';
        } elseif (str_starts_with($refMethod->name, 'am')) {
            $type = 'Condition';
        } else {
            $type = 'Action';
        }

        $body .= $methodTemplate
            ->place('doc', $doc)
            ->place('action', $refMethod->name)
            ->place('step', $type)
            ->produce();

        // add auto generated steps
        foreach (array_unique($this->generatedSteps) as $generator) {
            if (!is_callable([$generator, 'getTemplate'])) {
                throw new Exception("Wrong configuration for generated steps. {$generator} doesn't implement \Codeception\Step\GeneratedStep interface");
            }
            $template = call_user_func([$generator, 'getTemplate'], clone $methodTemplate);
            if ($template) {
                $body .= $template->produce();
            }
        }

        return $body;
    }

    protected function getParamsString(ReflectionMethod $refMethod): string
    {
        $params = [];
        foreach ($refMethod->getParameters() as $param) {
            $type = '';
            $reflectionType = $param->getType();
            if ($reflectionType !== null) {
                $type = $this->stringifyType($reflectionType, $refMethod->getDeclaringClass()) . ' ';
            }

            if ($param->isOptional()) {
                $params[] = $type . '$' . $param->name . ' = ' . ReflectionHelper::getDefaultValue($param);
            } else {
                $params[] = $type . '$' . $param->name;
            }
        }
        return implode(', ', $params);
    }

    /**
     * @throws ReflectionException
     */
    protected function addDoc(ReflectionClass $class, ReflectionMethod $refMethod): string|false
    {
        $doc = $refMethod->getDocComment();

        if (!$doc) {
            $interfaces = $class->getInterfaces();
            foreach ($interfaces as $interface) {
                $i = new ReflectionClass($interface->name);
                if ($i->hasMethod($refMethod->name)) {
                    $doc = $i->getMethod($refMethod->name)->getDocComment();
                    break;
                }
            }
        }

        if (!$doc && $class->getParentClass()) {
            $parent = new ReflectionClass($class->getParentClass()->name);
            if ($parent->hasMethod($refMethod->name)) {
                return $parent->getMethod($refMethod->name)->getDocComment();
            }
            return $doc;
        }
        return $doc;
    }

    public static function genHash(array $modules, array $settings): string
    {
        $actions = [];
        foreach ($modules as $moduleName => $module) {
            $actions[$moduleName] = get_class_methods($module::class);
        }

        return md5(Codecept::VERSION . serialize($actions) . serialize($settings['modules']) . implode(',', (array)$settings['step_decorators']));
    }

    public function getNumMethods(): int
    {
        return $this->numMethods;
    }

    private function createReturnTypeHint(ReflectionMethod $refMethod): string
    {
        $returnType = $refMethod->getReturnType();

        if (!$returnType instanceof ReflectionType) {
            return '';
        }
        return ': ' . $this->stringifyType($returnType, $refMethod->getDeclaringClass());
    }

    private function stringifyType(ReflectionType $type, ReflectionClass $moduleClass): string
    {
        if ($type instanceof ReflectionUnionType) {
            return $this->stringifyNamedTypes($type->getTypes(), $moduleClass, '|');
        } elseif ($type instanceof ReflectionIntersectionType) {
            return $this->stringifyNamedTypes($type->getTypes(), $moduleClass, '&');
        }

        return sprintf(
            '%s%s',
            ($type->allowsNull() && $type->getName() !== 'mixed') ? '?' : '',
            $this->stringifyNamedType($type, $moduleClass)
        );
    }

    /**
     * @param ReflectionNamedType[] $types
     */
    private function stringifyNamedTypes(array $types, ReflectionClass $moduleClass, string $separator): string
    {
        $strings = [];
        foreach ($types as $type) {
            $strings [] = $this->stringifyNamedType($type, $moduleClass);
        }

        return implode($separator, $strings);
    }


    private function stringifyNamedType(ReflectionNamedType $type, ReflectionClass $moduleClass): string
    {
        $typeName = $type->getName();

        if ($typeName === 'self') {
            $typeName = $moduleClass->getName();
        } elseif ($typeName === 'parent') {
            $typeName = $moduleClass->getParentClass()->getName();
        }

        return sprintf(
            '%s%s',
            $type->isBuiltin() ? '' : '\\',
            $typeName
        );
    }
}
