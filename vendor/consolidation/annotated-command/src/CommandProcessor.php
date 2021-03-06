<?php
namespace Consolidation\AnnotatedCommand;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

use Consolidation\OutputFormatters\FormatterManager;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Consolidation\AnnotatedCommand\Hooks\HookManager;

/**
 * Process a command, including hooks and other callbacks.
 * There should only be one command processor per application.
 * Provide your command processor to the AnnotatedCommandFactory
 * via AnnotatedCommandFactory::setCommandProcessor().
 */
class CommandProcessor
{
    /** var HookManager */
    protected $hookManager;
    /** var FormatterManager */
    protected $formatterManager;
    /** var callable */
    protected $displayErrorFunction;

    public function __construct(HookManager $hookManager)
    {
        $this->hookManager = $hookManager;
    }

    /**
     * Return the hook manager
     * @return HookManager
     */
    public function hookManager()
    {
        return $this->hookManager;
    }

    public function setFormatterManager(FormatterManager $formatterManager)
    {
        $this->formatterManager = $formatterManager;
        return $this;
    }

    public function setDisplayErrorFunction(callable $fn)
    {
        $this->displayErrorFunction = $fn;
        return $this;
    }

    /**
     * Return the formatter manager
     * @return FormatterManager
     */
    public function formatterManager()
    {
        return $this->formatterManager;
    }

    public function initializeHook(
        InputInterface $input,
        $names,
        AnnotationData $annotationData
    ) {
        return $this->hookManager()->initializeHook($input, $names, $annotationData);
    }

    public function optionsHook(
        AnnotatedCommand $command,
        $names,
        AnnotationData $annotationData
    ) {
        $this->hookManager()->optionsHook($command, $names, $annotationData);
    }

    public function interact(
        InputInterface $input,
        OutputInterface $output,
        $names,
        AnnotationData $annotationData
    ) {
        return $this->hookManager()->interact($input, $output, $names, $annotationData);
    }

    public function process(
        OutputInterface $output,
        $names,
        $commandCallback,
        CommandData $commandData
    ) {
        $result = [];
        try {
            $result = $this->validateRunAndAlter(
                $names,
                $commandCallback,
                $commandData
            );
            return $this->handleResults($output, $names, $result, $commandData);
        } catch (\Exception $e) {
            $result = new CommandError($e->getMessage(), $e->getCode());
            return $this->handleResults($output, $names, $result, $commandData);
        }
    }

    public function validateRunAndAlter(
        $names,
        $commandCallback,
        CommandData $commandData
    ) {
        // Validators return any object to signal a validation error;
        // if the return an array, it replaces the arguments.
        $validated = $this->hookManager()->validateArguments($names, $commandData);
        if (is_object($validated)) {
            return $validated;
        }

        // Run the command, alter the results, and then handle output and status
        $result = $this->runCommandCallback($commandCallback, $commandData);
        return $this->processResults($names, $result, $commandData);
    }

    public function processResults($names, $result, CommandData $commandData)
    {
        return $this->hookManager()->alterResult($names, $result, $commandData);
    }

    /**
     * Handle the result output and status code calculation.
     */
    public function handleResults(OutputInterface $output, $names, $result, CommandData $commandData)
    {
        $status = $this->hookManager()->determineStatusCode($names, $result);
        // If the result is an integer and no separate status code was provided, then use the result as the status and do no output.
        if (is_integer($result) && !isset($status)) {
            return $result;
        }
        $status = $this->interpretStatusCode($status);

        // Get the structured output, the output stream and the formatter
        $structuredOutput = $this->hookManager()->extractOutput($names, $result);
        $output = $this->chooseOutputStream($output, $status);
        if ($status != 0) {
            return $this->writeErrorMessage($output, $status, $structuredOutput, $result);
        }
        if ($this->dataCanBeFormatted($structuredOutput) && isset($this->formatterManager)) {
            return $this->writeUsingFormatter($output, $structuredOutput, $commandData);
        }
        return $this->writeCommandOutput($output, $structuredOutput);
    }

    protected function dataCanBeFormatted($structuredOutput)
    {
        if (!isset($this->formatterManager)) {
            return false;
        }
        return
            is_object($structuredOutput) ||
            is_array($structuredOutput);
    }

    /**
     * Run the main command callback
     */
    protected function runCommandCallback($commandCallback, CommandData $commandData)
    {
        $result = false;
        try {
            $args = $commandData->getArgsAndOptions();
            $result = call_user_func_array($commandCallback, $args);
        } catch (\Exception $e) {
            $result = new CommandError($e->getMessage(), $e->getCode());
        }
        return $result;
    }

    /**
     * Determine the formatter that should be used to render
     * output.
     *
     * If the user specified a format via the --format option,
     * then always return that.  Otherwise, return the default
     * format, unless --pipe was specified, in which case
     * return the default pipe format, format-pipe.
     *
     * n.b. --pipe is a handy option introduced in Drush 2
     * (or perhaps even Drush 1) that indicates that the command
     * should select the output format that is most appropriate
     * for use in scripts (e.g. to pipe to another command).
     *
     * @return string
     */
    protected function getFormat($options)
    {
        // In Symfony Console, there is no way for us to differentiate
        // between the user specifying '--format=table', and the user
        // not specifying --format when the default value is 'table'.
        // Therefore, we must make --field always override --format; it
        // cannot become the default value for --format.
        if (!empty($options['field'])) {
            return 'string';
        }
        $options += [
            'default-format' => false,
            'pipe' => false,
        ];
        $options += [
            'format' => $options['default-format'],
            'format-pipe' => $options['default-format'],
        ];

        $format = $options['format'];
        if ($options['pipe']) {
            $format = $options['format-pipe'];
        }
        return $format;
    }

    /**
     * Determine whether we should use stdout or stderr.
     */
    protected function chooseOutputStream(OutputInterface $output, $status)
    {
        // If the status code indicates an error, then print the
        // result to stderr rather than stdout
        if ($status && ($output instanceof ConsoleOutputInterface)) {
            return $output->getErrorOutput();
        }
        return $output;
    }

    /**
     * Call the formatter to output the provided data.
     */
    protected function writeUsingFormatter(OutputInterface $output, $structuredOutput, CommandData $commandData)
    {
        $options = $commandData->input()->getOptions();
        $format = $this->getFormat($options);
        $formatterOptions = new FormatterOptions($commandData->annotationData()->getArrayCopy(), $options);
        $this->formatterManager->write(
            $output,
            $format,
            $structuredOutput,
            $formatterOptions
        );
        return 0;
    }

    /**
     * Description
     * @param OutputInterface $output
     * @param int $status
     * @param string $structuredOutput
     * @param mixed $originalResult
     * @return type
     */
    protected function writeErrorMessage($output, $status, $structuredOutput, $originalResult)
    {
        if (isset($this->displayErrorFunction)) {
            call_user_func($this->displayErrorFunction, $output, $structuredOutput, $status, $originalResult);
        } else {
            $this->writeCommandOutput($output, $structuredOutput);
        }
        return $status;
    }

    /**
     * If the result object is a string, then print it.
     */
    protected function writeCommandOutput(
        OutputInterface $output,
        $structuredOutput
    ) {
        // If there is no formatter, we will print strings,
        // but can do no more than that.
        if (is_string($structuredOutput)) {
            $output->writeln($structuredOutput);
        }
        return 0;
    }

    /**
     * If a status code was set, then return it; otherwise,
     * presume success.
     */
    protected function interpretStatusCode($status)
    {
        if (isset($status)) {
            return $status;
        }
        return 0;
    }
}
