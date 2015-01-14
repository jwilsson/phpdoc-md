<?php

namespace PHPDocMD;

use
    Twig_Loader_String,
    Twig_Environment,
    Twig_Filter_Function;


/**
 * This class takes the output from 'parser', and generate the markdown
 * templates.
 *
 * @copyright Copyright (C) 2007-2012 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license Mit
 */
class Generator
{

    /**
     * Output directory
     *
     * @var string
     */
    protected $outputDir;

    /**
     * The list of classes and interfaces
     *
     * @var array
     */
    protected $classDefinitions;

    /**
     * Directory containing the twig templates
     *
     * @var string
     */
    protected $templateDir;

    /**
     * A simple template for generating links.
     *
     * @var string
     */
    protected $linkTemplate;

    /**
     * Constructor
     *
     * @param string $structureXmlFile
     * @param string $outputDir
     * @param string $templateDir
     * @param string $linkTemplate
     */
    public function __construct(array $classDefinitions, $outputDir, $templateDir, $linkTemplate = '%c.md')
    {

        $this->classDefinitions = $classDefinitions;
        $this->outputDir = $outputDir;
        $this->templateDir = $templateDir;
        $this->linkTemplate = $linkTemplate;

    }

    /**
     * Starts the generator
     *
     * @return void
     */
    public function run() {

        $loader = new Twig_Loader_String();
        $twig = new Twig_Environment($loader);

        // Sad, sad globals
        $GLOBALS['PHPDocMD_classDefinitions'] = $this->classDefinitions;
        $GLOBALS['PHPDocMD_linkTemplate'] = $this->linkTemplate;

        $twig->addFilter('classLink', new Twig_Filter_Function('PHPDocMd\\Generator::classLink'));
        foreach($this->classDefinitions as $className=>$data) {

            $output = $twig->render(
                file_get_contents($this->templateDir . '/class.twig'),
                $data
            );
            file_put_contents($this->outputDir . '/' . $data['fileName'], $output);

        }

        $index = $this->createIndex();

        $index = $twig->render(
            file_get_contents($this->templateDir . '/index.twig'),
            array(
                'index' => $index,
                'classDefinitions' => $this->classDefinitions,
            )
        );

        file_put_contents($this->outputDir . '/index.md', $index);

    }

    /**
     * Creates an index of classes and namespaces.
     *
     * I'm generating the actual markdown output here, which isn't great.. but
     * it will have to do. If I don't want to make things too complicated.
     *
     * @return array
     */
    protected function createIndex() {

        $tree = array();

        foreach($this->classDefinitions as $className=>$classInfo) {

            $current =& $tree;

            foreach(explode('\\', $className) as $part) {

                if (!isset($current[$part])) {
                    $current[$part] = array();
                }
                $current =& $current[$part];

            }

        }

        $treeOutput = '';
        $treeOutput = function($item, $fullString = '') use (&$treeOutput) {

            $output = '';
            foreach ($item as $name => $subItems) {

                $fullName = $fullString ? $fullString . "\\" . $name : $name;
                $link = Generator::classLink($fullName, $name);

                if ($link) {
                    $output .= '* ' . $link . "\n";
                }

                $output.= $treeOutput($subItems, $fullName);

            }

            return $output;

        };

        return $treeOutput($tree);

    }

    /**
     * This is a twig template function.
     *
     * This function allows us to easily link classes to their existing
     * pages.
     *
     * Due to the unfortunate way twig works, this must be static, and we must
     * use a global to achieve our goal.
     *
     * @param mixed $className
     * @return void
     */
    static function classLink($className, $label = null) {

        $classDefinitions = $GLOBALS['PHPDocMD_classDefinitions'];
        $linkTemplate = $GLOBALS['PHPDocMD_linkTemplate'];

        $returnedClasses = array();

        foreach(explode('|', $className) as $oneClass) {

            $oneClass = trim($oneClass,'\\ ');

            $myLabel = $label?:$oneClass;

            if (isset($classDefinitions[$oneClass])) {

                $link = array_pop(explode('\\', $oneClass));
                $link = strtr($linkTemplate, array('%c' => $link));
                $link = str_replace('.md', '.html', $link);
                $link = strtolower($link);

                $returnedClasses[] = "[" . $myLabel . "](" . $link . ')';

            }

        }

       return implode('|', $returnedClasses);

    }

}
