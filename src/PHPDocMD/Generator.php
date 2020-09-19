<?php
namespace PHPDocMD;

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
        $this->linkTemplate = $linkTemplate;
        $this->outputDir = $outputDir;
        $this->templateDir = $templateDir;
    }

    /**
     * Starts the generator
     *
     * @return void
     */
    public function run()
    {
        $loader = new \Twig\Loader\FilesystemLoader('../templates');
        $twig = new \Twig\Environment($loader);

        $classLinkFilter = new \Twig\TwigFilter('classLink', [$this, 'classLink']);
        $twig->addFilter($classLinkFilter);

        foreach ($this->classDefinitions as $className => $data) {
            $output = $twig->render('class.twig', $data);
            $output = html_entity_decode($output, ENT_QUOTES | ENT_XML1);

            file_put_contents($this->outputDir . '/' . $data['fileName'], $output);
        }
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
    public function classLink($className, $label = null)
    {
        $returnedClasses = [];
        foreach (explode('|', $className) as $class) {
            $class = trim($class,'\\ ');
            $label = $label ?: $class;

            if (isset($this->classDefinitions[$class])) {
                $link = array_pop(explode('\\', $class));
                $link = strtr($this->linkTemplate, array('%c' => $link));
                $link = str_replace('.md', '.html', $link);
                $link = strtolower($link);

                $returnedClasses[] = sprintf('[%s](%s)', $label, $link);
            }
        }

        return implode('|', $returnedClasses);
    }
}
