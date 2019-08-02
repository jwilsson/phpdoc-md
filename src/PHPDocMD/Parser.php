<?php
namespace PHPDocMD;

use SimpleXMLElement;

/**
 * This class parses structure.xml and generates the api documentation.
 *
 * @copyright Copyright (C) 2007-2012 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license Mit
 */
class Parser
{
    /**
     * Path to the structure.xml file
     *
     * @var string
     */
    protected $structureXmlFile;

    /**
     * The list of classes and interfaces
     *
     * @var array
     */
    protected $classDefinitions;

    /**
     * Constructor
     *
     * @param string $structureXmlFile
     */
    public function __construct($structureXmlFile)
    {
        $this->structureXmlFile = $structureXmlFile;
    }

    /**
     * Starts the process
     *
     * @return void
     */
    public function run()
    {
        $xml = simplexml_load_file($this->structureXmlFile);

        $this->getClassDefinitions($xml);

        foreach($this->classDefinitions as $className => $classInfo) {
            $this->expandMethods($className);
            $this->expandProperties($className);
        }

        return $this->classDefinitions;
    }

    /**
     * Gets all classes and interfaces from the file and puts them in an easy
     * to use array.
     *
     * @param SimpleXmlElement $xml
     * @return void
     */
    protected function getClassDefinitions(SimpleXmlElement $xml)
    {
        foreach($xml->xpath('file/class|file/interface') as $class) {
            $className = (string)$class->full_name;
            $className = ltrim($className, '\\');

            $fileName = str_replace('\\', '-', $class->name) . '.md';

            $implements = [];
            if (isset($class->implements)) {
                foreach($class->implements as $interface) {
                    $implements[] = ltrim((string)$interface, '\\');
                }
            }

            $extends = [];
            if (isset($class->extends)) {
                foreach($class->extends as $parent) {
                    $extends[] = ltrim((string)$parent, '\\');
                }
            }

            $classNames[$className] = array(
                'abstract' => (string)$class['abstract'] == 'true',
                'className' => $className,
                'constants' => $this->parseConstants($class),
                'deprecated' => count($class->xpath('docblock/tag[@name="deprecated"]'))>0,
                'description' => (string)$class->docblock->description,
                'extends' => $extends,
                'fileName' => $fileName,
                'implements' => $implements,
                'isClass' => $class->getName() === 'class',
                'isInterface' => $class->getName() === 'interface',
                'longDescription' => (string)$class->docblock->{"long-description"},
                'methods' => $this->parseMethods($class),
                'namespace' => (string)$class['namespace'],
                'properties' => $this->parseProperties($class),
                'shortClass' => (string)$class->name,
            );
        }

        $this->classDefinitions = $classNames;
    }

    /**
     * Parses all the method information for a single class or interface.
     *
     * You must pass an xml element that refers to either the class or
     * interface element from structure.xml.
     *
     * @param SimpleXMLElement $class
     * @return array
     */
    protected function parseMethods(SimpleXMLElement $class)
    {
        $className = (string)$class->full_name;
        $className = ltrim($className,'\\');

        $methods = [];
        foreach($class->method as $method) {
            $methodName = (string)$method->name;

            $return = $method->xpath('docblock/tag[@name="return"]');

            if (count($return)) {
                $return = $return[0];

                $description = (string)$return['description'];
                $description = strip_tags(html_entity_decode($description));
                $description = str_replace('|', '\|', $description);
                $description = str_replace('- ', '    * ', $description);

                $type = str_replace('|', '\|', (string)$return['type']);

                $return = array(
                    'description' => $description,
                    'type' => $type,
                );
            }

            $arguments = [];
            foreach($method->argument as $argument) {
                $nArgument = array(
                    'name' => (string)$argument->name,
                    'type' => (string)$argument->type,
                );

                if (count($tag = $method->xpath('docblock/tag[@name="param" and @variable="' . $nArgument['name'] . '"]'))) {
                    $tag = $tag[0];

                    if ((string)$tag['type']) {
                        $type = str_replace('|', '\|', (string)$tag['type']);

                        $nArgument['type'] = $type;
                    }

                    if ((string)$tag['description']) {
                        $description = (string)$tag['description'];
                        $description = html_entity_decode($description);
                        $description = str_replace('<li>', '    * ', $description);
                        $description = strip_tags($description);
                        $description = preg_replace('/^\h*\v+/m', '', $description); // Remove blank lines

                        $nArgument['description'] = str_replace('|', '\|', $description);
                    }

                    if ((string)$tag['variable']) {
                        $nArgument['name'] = (string)$tag['variable'];
                    }

                }

                $arguments[] = $nArgument;
            }

            $argumentStr = implode(', ', array_map(function($argument) {
                return ($argument['type'] ? $argument['type'] . ' ' : '') . $argument['name'];
            }, $arguments));

            $returnType = str_replace('\|', '|', $return['type'] ?? '');
            $argumentStr = str_replace('\|', '|', $argumentStr);
            $signature = $returnType . ' ' . $className . '::' . $methodName . '('.$argumentStr.')';

            $description = (string)$method->docblock->description . "\n" . (string)$method->docblock->{"long-description"};
            $description = trim($description);
            $description = preg_replace('@(https?://([-\w\.]+)+(:\d+)?(/([\w/_\.-]*(\?\S+)?)?)?)@', '[$1]($1)', $description);

            $deprecated = $method->xpath('docblock/tag[@name="deprecated"]');
            if (count($deprecated)) {
                $deprecated = $deprecated[0];

                $description = (string)$deprecated['description'];
                $description = strip_tags(html_entity_decode($description));

                $deprecated = array(
                    'description' => $description,
                );
            }

            $methods[$methodName] = array(
                'arguments' => $arguments,
                'definedBy' => $className,
                'deprecated' => $deprecated,
                'description' => nl2br($description, false),
                'name' => $methodName,
                'return' => $return,
                'signature' => $signature,
            );

        }

        return $methods;
    }

    /**
     * Parses all property information for a single class or interface.
     *
     * You must pass an xml element that refers to either the class or
     * interface element from structure.xml.
     *
     * @param SimpleXMLElement $class
     * @return array
     */
    protected function parseProperties(SimpleXMLElement $class)
    {
        $className = (string)$class->full_name;
        $className = ltrim($className,'\\');

        $properties = [];
        foreach($class->property as $xProperty) {
            $propName = (string)$xProperty->name;
            $default = (string)$xProperty->default;

            $xVar = $xProperty->xpath('docblock/tag[@name="var"]');
            $type = count($xVar) ? $xVar[0]->type : 'mixed';

            $visibility = (string)$xProperty['visibility'];
            $signature = $visibility . ' ' . $type . ' ' . $propName;

            if ($default) {
                $signature .= ' = ' . $default;
            }

            $properties[$propName] = array(
                'default' => $default,
                'definedBy' => $className,
                'deprecated' => count($class->xpath('docblock/tag[@name="deprecated"]'))>0,
                'description' => (string)$xProperty->docblock->description . "\n\n" . (string)$xProperty->docblock->{"long-description"},
                'name' => $propName,
                'signature' => $signature,
                'static' => ((string)$xProperty['static'])=="true",
                'type' => $type,
                'visibility' => $visibility,
            );
        }

        return $properties;
    }

    /**
     * Parses all constant information for a single class or interface.
     *
     * You must pass an xml element that refers to either the class or
     * interface element from structure.xml.
     *
     * @param SimpleXMLElement $class
     * @return array
     */
    protected function parseConstants(SimpleXMLElement $class)
    {
        $className = (string)$class->full_name;
        $className = ltrim($className,'\\');

        $constants = [];
        foreach($class->constant as $xConstant) {
            $name = (string)$xConstant->name;
            $value = (string)$xConstant->value;

            $constants[$name] = array(
                'definedBy' => $className,
                'deprecated' => count($class->xpath('docblock/tag[@name="deprecated"]'))>0,
                'description' => (string)$xConstant->docblock->description . "\n\n" . (string)$xConstant->docblock->{"long-description"},
                'name' => $name,
                'signature' => sprintf('const %s = %s', $name, $value),
                'value' => $value,
            );
        }

        return $constants;
    }

    /**
     * This method goes through all the class definitions, and adds
     * non-overriden method information from parent classes.
     *
     * @return array
     */
    protected function expandMethods($className)
    {
        $class = $this->classDefinitions[$className];

        $newMethods = [];
        foreach(array_merge($class['extends'], $class['implements']) as $extends) {
            if (!isset($this->classDefinitions[$extends])) {
                continue;
            }

            foreach($this->classDefinitions[$extends]['methods'] as $methodName => $methodInfo) {
                if (!isset($class[$methodName])) {
                    $newMethods[$methodName] = $methodInfo;
                }

            }

            $newMethods = array_merge($newMethods, $this->expandMethods($extends));
        }

        $this->classDefinitions[$className]['methods'] = array_merge(
            $this->classDefinitions[$className]['methods'],
            $newMethods,
        );

        return $newMethods;
    }

    /**
     * This method goes through all the class definitions, and adds
     * non-overriden property information from parent classes.
     *
     * @return array
     */
    protected function expandProperties($className)
    {
        $class = $this->classDefinitions[$className];

        $newProperties = [];
        foreach(array_merge($class['implements'], $class['extends']) as $extends) {
            if (!isset($this->classDefinitions[$extends])) {
                continue;
            }

            foreach($this->classDefinitions[$extends]['properties'] as $propertyName => $propertyInfo) {
                if ($propertyInfo['visibility'] === 'private') {
                    continue;
                }

                if (!isset($class[$propertyName])) {
                    $newProperties[$propertyName] = $propertyInfo;
                }
            }

            $newProperties = array_merge($newProperties, $this->expandProperties($extends));
        }

        $this->classDefinitions[$className]['properties'] = array_merge(
            $this->classDefinitions[$className]['properties'],
            $newProperties,
        );

        return $newProperties;
    }
}
