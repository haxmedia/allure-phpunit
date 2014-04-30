<?php

namespace Yandex\Allure\Adapter;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\IndexedReader;
use Exception;
use PHPUnit_Framework_AssertionFailedError;
use PHPUnit_Framework_Test;
use PHPUnit_Framework_TestListener;
use PHPUnit_Framework_TestSuite;
use Rhumsaa\Uuid\Uuid;
use Yandex\Allure\Adapter\Annotation;
use Yandex\Allure\Adapter\Model;
use Yandex\Allure\Adapter\Model\Status;

require_once(dirname(__FILE__).'/../../../../vendor/autoload.php');

const DEFAULT_OUTPUT_DIRECTORY = "allure-report";

class AllureAdapter implements PHPUnit_Framework_TestListener {

    private $testSuites;

    private $outputDirectory;

    private $annotationsReader;

    function __construct($outputDirectory = DEFAULT_OUTPUT_DIRECTORY, $deletePreviousResults = false)
    {
        if (!file_exists($outputDirectory)){
            mkdir($outputDirectory, 0755, true);
        }
        if ($deletePreviousResults){
            $files = glob($outputDirectory . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE);
            foreach($files as $file){
                if(is_file($file)){
                    unlink($file);
                }
            }
        }
        $this->outputDirectory = $outputDirectory;
        $this->testSuites = array();
    }

    /**
     * An error occurred.
     *
     * @param PHPUnit_Framework_Test $test
     * @param Exception $e
     * @param float $time
     */
    public function addError(PHPUnit_Framework_Test $test, Exception $e, $time)
    {
        $this->handleUnsuccessfulTest($test, $e, Status::BROKEN);
    }

    /**
     * A failure occurred.
     *
     * @param PHPUnit_Framework_Test $test
     * @param PHPUnit_Framework_AssertionFailedError $e
     * @param float $time
     */
    public function addFailure(PHPUnit_Framework_Test $test, PHPUnit_Framework_AssertionFailedError $e, $time)
    {
        $this->handleUnsuccessfulTest($test, $e, Status::FAILED);
    }

    /**
     * Incomplete test.
     *
     * @param PHPUnit_Framework_Test $test
     * @param Exception $e
     * @param float $time
     */
    public function addIncompleteTest(PHPUnit_Framework_Test $test, Exception $e, $time)
    {
        $this->addError($test, $e, $time);
    }

    /**
     * Risky test.
     *
     * @param PHPUnit_Framework_Test $test
     * @param Exception $e
     * @param float $time
     * @since  Method available since Release 4.0.0
     */
    public function addRiskyTest(PHPUnit_Framework_Test $test, Exception $e, $time)
    {
        $this->addError($test, $e, $time);
    }

    /**
     * Skipped test.
     *
     * @param PHPUnit_Framework_Test $test
     * @param Exception $e
     * @param float $time
     * @since  Method available since Release 3.0.0
     */
    public function addSkippedTest(PHPUnit_Framework_Test $test, Exception $e, $time)
    {
        $this->handleUnsuccessfulTest($test, $e, Status::SKIPPED);
    }
    
    private function handleUnsuccessfulTest(PHPUnit_Framework_Test $test, Exception $e, $status)
    {
        $this->doIfTestIsValid($test, function(Model\TestCase $testCase) use ($e, $status) {
            $failure = new Model\Failure($e->getMessage());
            $failure->setStackTrace($e->getTraceAsString());
            $testCase->setStatus($status);
            $testCase->setFailure($failure);
        });
    }

    /**
     * A test suite started.
     *
     * @param PHPUnit_Framework_TestSuite $suite
     * @since  Method available since Release 2.2.0
     */
    public function startTestSuite(PHPUnit_Framework_TestSuite $suite)
    {
        $suiteName = $suite->getName();
        $suiteStart = self::getTimestamp();
        $testSuite = new Model\TestSuite($suiteName, $suiteStart);
        foreach ($this->getClassAnnotations($suite) as $annotation){
            if ($annotation instanceof Annotation\Title){
                $testSuite->setTitle($annotation->value);
            } else if ($annotation instanceof Annotation\Description){
                $testSuite->setDescription(new Model\Description(
                    $annotation->type,
                    $annotation->value
                ));
            } else if ($annotation instanceof Annotation\Features){
                foreach ($annotation->getFeatureNames() as $featureName){
                    $testSuite->addLabel(Model\Label::feature($featureName));
                }
            } else if ($annotation instanceof Annotation\Stories) {
                foreach ($annotation->getStories() as $storyName){
                    $testSuite->addLabel(Model\Label::story($storyName));
                }
            }
        }
        $this->pushTestSuite($testSuite);
    }

    /**
     * A test suite ended.
     *
     * @param PHPUnit_Framework_TestSuite $suite
     * @since  Method available since Release 2.2.0
     */
    public function endTestSuite(PHPUnit_Framework_TestSuite $suite)
    {
        $suiteStop = self::getTimestamp();
        $testSuite = $this->popTestSuite();
        if ($testSuite instanceof Model\TestSuite){
            $testSuite->setStop($suiteStop);
            if ($testSuite->size() > 0) {
                $xml = $testSuite->serialize();
                $fileName = self::getUUID() . '-testsuite.xml';
                $filePath = $this->getOutputDirectory() . DIRECTORY_SEPARATOR . $fileName;
                file_put_contents($filePath, $xml);
            }
        }
    }

    /**
     * A test started.
     *
     * @param PHPUnit_Framework_Test $test
     */
    public function startTest(PHPUnit_Framework_Test $test)
    {
        $testInstance = self::validateTestInstance($test);
        if (!is_null($testInstance)) {
            $testName = $testInstance->getName();
            $testStart = self::getTimestamp();
            $testCase = new Model\TestCase($testName, $testStart);
            foreach ($this->getMethodAnnotations($testInstance, $testName) as $annotation) {
                if ($annotation instanceof Annotation\Title) {
                    $testCase->setTitle($annotation->value);
                } else if ($annotation instanceof Annotation\Description) {
                    $testCase->setDescription(new Model\Description(
                        $annotation->type,
                        $annotation->value
                    ));
                } else if ($annotation instanceof Annotation\Features) {
                    foreach ($annotation->getFeatureNames() as $featureName) {
                        $testCase->addLabel(Model\Label::feature($featureName));
                    }
                } else if ($annotation instanceof Annotation\Stories) {
                    foreach ($annotation->getStories() as $storyName) {
                        $testCase->addLabel(Model\Label::story($storyName));
                    }
                } else if ($annotation instanceof Annotation\Step) {
                    //TODO: to be implemented!
                } else if ($annotation instanceof Annotation\Severity) {
                    $testCase->setSeverity($annotation->level);
                }
            }
            $this->getCurrentTestSuite()->addTestCase($testCase);
        }
    }

    /**
     * A test ended.
     *
     * @param PHPUnit_Framework_Test $test
     * @param float $time
     * @throws \Exception
     */
    public function endTest(PHPUnit_Framework_Test $test, $time)
    {
        $testInstance = self::validateTestInstance($test);
        if (!is_null($testInstance)) {
            $testName = $testInstance->getName();
            $testStop = self::getTimestamp();
            $testCase = $this->getCurrentTestSuite()->getTestCase($testName);
            if ($testCase instanceof Model\TestCase) {
                $testCase->setStop($testStop);
                foreach ($this->getMethodAnnotations($testInstance, $testName) as $annotation) {
                    if ($annotation instanceof Annotation\Attachment) {
                        $path = $annotation->path;
                        $type = $annotation->type;
                        if ($type != Model\AttachmentType::OTHER && file_exists($path)) {
                            $newFileName =
                                $this->getOutputDirectory() . DIRECTORY_SEPARATOR .
                                self::getUUID() . $annotation->name . '-attachment.' . $type;
                            $attachment = new Model\Attachment($annotation->name, $newFileName, $annotation->type);
                            if (!copy($path, $newFileName)) {
                                throw new Exception("Failed to copy attachment from $path to $newFileName.");
                            }
                            $testCase->addAttachment($attachment);
                        } else {
                            throw new Exception("Attachment $path doesn't exist.");
                        }
                    }
                }
            }
        }
    }

    /**
     * @return string
     */
    public function getOutputDirectory()
    {
        return $this->outputDirectory;
    }

    /**
     * @param Model\TestSuite $testSuite
     */
    public function pushTestSuite(Model\TestSuite $testSuite)
    {
        array_push($this->testSuites, $testSuite);
    }

    /**
     * @return Model\TestSuite
     */
    public function getCurrentTestSuite()
    {
        return end($this->testSuites);
    }

    /**
     * @return Model\TestSuite
     */
    public function popTestSuite()
    {
        return array_pop($this->testSuites);
    }

    /**
     * Returns a list of class annotations
     * @param $instance
     * @return array
     */
    private function getClassAnnotations($instance)
    {
        $ref = new \ReflectionClass($instance);
        return $this->getAnnotationsReader()->getClassAnnotations($ref);
    }

    /**
     * Returns a list of method annotations
     * @param $instance
     * @param $methodName
     * @return array
     */
    private function getMethodAnnotations($instance, $methodName)
    {
        $ref = new \ReflectionMethod($instance, $methodName);
        return $this->getAnnotationsReader()->getMethodAnnotations($ref);
    }

    /**
     * @return IndexedReader
     */
    private function getAnnotationsReader()
    {
        if (!isset($this->annotationsReader)){
            $this->annotationsReader = new IndexedReader(new AnnotationReader());
        }
        return $this->annotationsReader;
    }
    
    /**
     * @param PHPUnit_Framework_Test $test
     * @return \PHPUnit_Framework_TestCase|void
     */
    private static function validateTestInstance(PHPUnit_Framework_Test $test){
        if ($test instanceof \PHPUnit_Framework_TestCase){
            return $test;
        }
        echo("Warning: skipping test $test as it doesn't inherit from PHPUnit_Framework_TestCase.");
        return null;
    }

    /**
     * @param PHPUnit_Framework_Test $test
     * @param $action
     */
    private function doIfTestIsValid(PHPUnit_Framework_Test $test, $action)
    {
        $testInstance = self::validateTestInstance($test);
        if (!is_null($testInstance)){
            $testCase = $this->getCurrentTestSuite()->getTestCase($testInstance->getName());
            if (isset($testCase)){
                $action($testCase);
            }
        }

    }

    public static function getTimestamp()
    {
        return round(microtime(true) * 1000);
    }

    public static function getUUID()
    {
        return Uuid::uuid4();
    }

}